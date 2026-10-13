<?php

namespace App\Services\Shop;

use App\Helpers\MailHelper;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\ShopOrder;
use App\Models\User;
use App\Services\SmsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Order requests from the shop. No payment and no stock change: the owner calls the
 * customer, then "Convert to sale" records real sales (stock comes off, receipt available).
 */
class ShopOrderService
{
    /** Same phone, same shop, per rolling 24h. Real customers rarely need more. */
    public const MAX_ORDERS_PER_PHONE_PER_DAY = 5;

    /**
     * @param  array{name:string, phone:string, email:?string, fulfilment:string, address:?string, note:?string, items:array<int, array{product_id:int, variation_id:?int, quantity:float}>}  $data
     */
    public function place(Shop $shop, array $data, ?string $ip = null): ShopOrder
    {
        $tenantId = $shop->tenant_id;
        $phone = self::normalisePhone($data['phone']);

        if (! in_array($data['fulfilment'], array_keys(array_filter(['pickup' => $shop->pickup, 'delivery' => $shop->delivery])), true)) {
            throw ValidationException::withMessages(['fulfilment' => 'This shop doesn\'t offer that option.']);
        }
        if ($shop->isBlocked($phone)) {
            throw ValidationException::withMessages(['phone' => 'This shop isn\'t accepting orders from this number.']);
        }
        $recent = ShopOrder::withoutGlobalScopes()->where('shop_id', $shop->id)
            ->where('customer_phone', $phone)->where('created_at', '>', now()->subDay())->count();
        if ($recent >= self::MAX_ORDERS_PER_PHONE_PER_DAY) {
            throw ValidationException::withMessages(['phone' => 'You\'ve placed several orders today. The shop will be in touch, or contact them directly.']);
        }

        // Prices and availability always come from the database, never from the visitor.
        $lines = [];
        foreach ($data['items'] as $i => $item) {
            $product = Product::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('shop_visible', true)->find($item['product_id']);
            if (! $product) {
                throw ValidationException::withMessages(["items.{$i}" => 'One of the items is no longer available.']);
            }
            $hasVariations = ProductVariation::withoutGlobalScopes()->where('product_id', $product->id)->exists();
            $variation = null;
            if (! empty($item['variation_id'])) {
                $variation = ProductVariation::withoutGlobalScopes()->where('product_id', $product->id)->find($item['variation_id']);
                if (! $variation) {
                    throw ValidationException::withMessages(["items.{$i}" => "Choose an option for {$product->name}."]);
                }
            } elseif ($hasVariations) {
                throw ValidationException::withMessages(["items.{$i}" => "Choose an option for {$product->name}."]);
            }

            $label = $product->name.($variation ? " ({$variation->name})" : '');
            $stock = (float) ($variation ? $variation->quantity : $product->quantity);
            $qty = (float) $item['quantity'];
            if ($stock <= 0) {
                throw ValidationException::withMessages(["items.{$i}" => "{$label} is sold out."]);
            }
            if ($qty > $stock) {
                throw ValidationException::withMessages(["items.{$i}" => "Only ".(int) floor($stock)." {$label} left."]);
            }

            $price = (float) ($variation?->unit_price ?: $product->unit_price);
            $lines[] = [
                'product_id' => $product->id,
                'variation_id' => $variation?->id,
                'name' => $product->name,
                'variation_name' => $variation?->name,
                'quantity' => $qty,
                'unit_price' => $price,
                'line_total' => round($qty * $price, 2),
            ];
        }

        $order = DB::transaction(function () use ($shop, $tenantId, $data, $phone, $lines, $ip) {
            // Per-workspace order numbers (#1001, #1002…); the row lock keeps two orders from sharing one.
            $last = ShopOrder::withoutGlobalScopes()->where('tenant_id', $tenantId)->lockForUpdate()->max('number');

            $order = new ShopOrder([
                'shop_id' => $shop->id,
                'number' => max(1000, (int) $last) + 1,
                'status' => 'new',
                'customer_name' => trim($data['name']),
                'customer_phone' => $phone,
                'customer_email' => $data['email'] ?? null,
                'fulfilment' => $data['fulfilment'],
                'address' => $data['fulfilment'] === 'delivery' ? trim((string) ($data['address'] ?? '')) : null,
                'note' => isset($data['note']) ? trim($data['note']) ?: null : null,
                'source' => self::cleanSource($data['source'] ?? null),
                'subtotal' => array_sum(array_column($lines, 'line_total')),
                'item_count' => (int) ceil(array_sum(array_column($lines, 'quantity'))),
                'ip_hash' => $ip ? hash('sha256', $ip.config('app.key')) : null,
            ]);
            $order->tenant_id = $tenantId;
            $order->save();
            $order->items()->createMany($lines);

            return $order;
        });

        $this->notify($shop, $order->load('items'));

        return $order;
    }

    /** Owner: dashboard bell (from the "new" count), email to admins, SMS to the shop's number; customer: SMS + email. */
    private function notify(Shop $shop, ShopOrder $order): void
    {
        $tenant = $shop->tenant;
        $symbol = $tenant->settings['currency_symbol'] ?? 'GH₵';
        $money = fn ($n) => $symbol.' '.number_format((float) $n, 2);
        $dashboard = rtrim((string) config('services.frontend_url'), '/').'/dashboard/orders/?order='.$order->id;
        $wa = 'https://wa.me/'.SmsService::normalizePhone($order->customer_phone).'?text='.rawurlencode("Hi {$order->customer_name}, this is {$tenant->name} about your order #{$order->number}.");
        $items = $order->items->map(fn ($i) => '- '.rtrim(rtrim(number_format((float) $i->quantity, 2, '.', ''), '0'), '.')." × {$i->name}".($i->variation_name ? " ({$i->variation_name})" : '').' — '.$money($i->line_total))->implode("\n");

        rescue(function () use ($tenant, $order, $items, $money, $dashboard, $wa) {
            $body = "New order #{$order->number} from your online shop.\n\n"
                ."Customer: {$order->customer_name}\nPhone: {$order->customer_phone}\n"
                .($order->customer_email ? "Email: {$order->customer_email}\n" : '')
                .'Fulfilment: '.ucfirst($order->fulfilment).($order->address ? " to {$order->address}" : '')."\n"
                .($order->note ? "Note: {$order->note}\n" : '')
                .(self::sourceLabel($order->source) ? 'Came from: '.self::sourceLabel($order->source)."\n" : '')
                ."\n{$items}\n\nTotal: ".$money($order->subtotal)."\n\n"
                ."Chat on WhatsApp: {$wa}\nOpen the order: {$dashboard}\n\n"
                ."No payment has been taken. Contact the customer to confirm.\n\nZinnvy.";
            User::where('tenant_id', $tenant->id)->where('role', 'Administrator')->pluck('email')
                ->each(fn ($email) => MailHelper::sendEmailNotification($email, "New order #{$order->number}: {$order->customer_name}, ".$money($order->subtotal), $body));
        }, report: true);

        $sms = app(SmsService::class);
        // Order alerts go to the "send order alerts to" number, or the shop's WhatsApp number if that's empty.
        $alertPhone = $shop->notify_phone ?: $shop->whatsapp;
        if ($alertPhone) {
            rescue(fn () => $sms->queueSms($tenant->id, 'Zinnvy', $alertPhone,
                "New shop order #{$order->number}: {$order->customer_name} {$order->customer_phone}, ".$money($order->subtotal).". Open Zinnvy Inventory > Orders."), report: true);
        }
        $momo = $shop->momo();
        $payLine = $momo ? self::momoLine($momo, $order->number) : null;
        rescue(fn () => $sms->queueSms($tenant->id, 'Zinnvy', $order->customer_phone,
            "Thanks {$order->customer_name}! {$tenant->name} received your order #{$order->number} (".$money($order->subtotal)."). They'll contact you to confirm."
            .($payLine ? " After they confirm, pay by {$payLine}" : '')), report: true);
        if ($order->customer_email) {
            rescue(fn () => MailHelper::sendEmailNotification($order->customer_email, "Your order #{$order->number} at {$tenant->name}",
                "Hi {$order->customer_name},\n\n{$tenant->name} received your order #{$order->number}:\n\n{$items}\n\nTotal: ".$money($order->subtotal)
                ."\n\nNo payment has been taken. {$tenant->name} will contact you on {$order->customer_phone} to confirm the order and arrange "
                .($order->fulfilment === 'delivery' ? 'delivery' : 'pickup').'.'
                .($payLine ? "\n\nOnce they confirm, you can pay by {$payLine}\nOnly pay after {$tenant->name} has confirmed your order." : '')
                ."\n\nPowered by Zinnvy."), report: true);
        }
    }

    /**
     * Owner confirms the order happened: record one sale per line (normal stock rules),
     * linked to the customer (matched by phone, or created).
     */
    public function convert(ShopOrder $order, string $paymentMethod): ShopOrder
    {
        if (in_array($order->status, ['completed', 'cancelled'], true)) {
            throw ValidationException::withMessages(['status' => 'This order is already '.$order->status.'.']);
        }

        try {
            return DB::transaction(function () use ($order, $paymentMethod) {
                $customer = $this->customerFor($order);
                $saleIds = [];
                foreach ($order->items as $item) {
                    if (! $item->product_id) {
                        throw ValidationException::withMessages(['items' => "{$item->name} was deleted from your products, so it can't be sold."]);
                    }
                    $sale = new Sale([
                        'product_id' => $item->product_id,
                        'variation_id' => $item->variation_id,
                        'customer_id' => $customer->id,
                        'quantity' => $item->quantity,
                        'unit_price' => $item->unit_price,
                        'discount' => 0,
                        'notes' => "Online order #{$order->number}",
                        'sale_date' => now(),
                        'payment_method' => $paymentMethod,
                    ]);
                    $sale->tenant_id = $order->tenant_id;
                    $sale->save();
                    $saleIds[] = $sale->id;
                }

                $order->forceFill([
                    'status' => 'completed', 'completed_at' => now(), 'sale_ids' => $saleIds, 'customer_id' => $customer->id,
                ])->save();

                return $order;
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if (str_starts_with($e->getMessage(), 'Insufficient')) {
                throw ValidationException::withMessages(['items' => 'Not enough stock to complete this order. Add stock or edit the order with the customer first.']);
            }
            Log::error('Shop order convert failed', ['order' => $order->id, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function customerFor(ShopOrder $order): Customer
    {
        $last9 = substr(preg_replace('/\D/', '', $order->customer_phone), -9);
        $existing = Customer::withoutGlobalScopes()->where('tenant_id', $order->tenant_id)->whereNotNull('phone')->get()
            ->first(fn ($c) => substr(preg_replace('/\D/', '', $c->phone), -9) === $last9);

        return $existing ?? Customer::withoutGlobalScopes()->create([
            'tenant_id' => $order->tenant_id,
            'name' => $order->customer_name,
            'phone' => $order->customer_phone,
            'email' => $order->customer_email,
            'address' => $order->address,
        ]);
    }

    /** "024 123 4567" / "+233 24 123 4567" → "0241234567"; other countries keep their digits with "+". */
    public static function normalisePhone(string $phone): string
    {
        $d = preg_replace('/\D/', '', $phone);
        if (str_starts_with($d, '233') && strlen($d) === 12) {
            return '0'.substr($d, 3);
        }
        if (strlen($d) === 9) {
            return '0'.$d;
        }

        return strlen($d) === 10 && $d[0] === '0' ? $d : '+'.$d;
    }

    /** "MTN MoMo 0241234567 (Ama Mensah), reference #1042." */
    public static function momoLine(array $momo, int $orderNumber): string
    {
        $how = ! empty($momo['number'])
            ? trim(($momo['network'] ?? '').' MoMo '.$momo['number'].(! empty($momo['name']) ? " ({$momo['name']})" : ''))
            : trim(($momo['network'] ?? '').' MoMoPay merchant '.$momo['merchant_id']);
        if (! empty($momo['number']) && ! empty($momo['merchant_id'])) {
            $how .= ", or merchant ID {$momo['merchant_id']}";
        }

        return "{$how}, reference #{$orderNumber}.";
    }

    /** Keep only the attribution keys we know, trimmed. */
    public static function cleanSource(?array $source): ?array
    {
        $keys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'referrer', 'landing', 'fbclid', 'gclid', 'ttclid'];
        $clean = array_filter(array_map(fn ($v) => is_string($v) ? mb_substr(trim($v), 0, 200) : null, array_intersect_key($source ?? [], array_flip($keys))));

        return $clean ?: null;
    }

    /** "instagram / social (eid-sale)" or the referring site, for people. */
    public static function sourceLabel(?array $s): ?string
    {
        if (! $s) {
            return null;
        }
        if (! empty($s['utm_source'])) {
            return $s['utm_source'].(! empty($s['utm_medium']) ? " / {$s['utm_medium']}" : '').(! empty($s['utm_campaign']) ? " ({$s['utm_campaign']})" : '');
        }
        foreach (['fbclid' => 'Facebook/Instagram ad', 'gclid' => 'Google ad', 'ttclid' => 'TikTok ad'] as $k => $label) {
            if (! empty($s[$k])) {
                return $label;
            }
        }

        return ! empty($s['referrer']) ? parse_url($s['referrer'], PHP_URL_HOST) ?: null : null;
    }

    public static function phoneLooksValid(string $phone): bool
    {
        $d = preg_replace('/\D/', '', $phone);

        return strlen($d) >= 9 && strlen($d) <= 15;
    }
}
