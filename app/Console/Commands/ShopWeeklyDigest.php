<?php

namespace App\Console\Commands;

use App\Helpers\MailHelper;
use App\Models\Shop;
use App\Models\User;
use App\Services\Shop\ShopInsights;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Monday email to each live shop's Administrators: last week's visitors, orders, top product and source. */
class ShopWeeklyDigest extends Command
{
    protected $signature = 'shop:weekly-digest {--shop= : Only this shop id (for testing)}';
    protected $description = 'Email shop owners last week\'s Shop Insights';

    public function handle(ShopInsights $insights): int
    {
        $sent = 0;
        $shops = Shop::live()->with('tenant')->when($this->option('shop'), fn ($q, $id) => $q->whereKey($id))->get();

        foreach ($shops as $shop) {
            $data = $insights->build($shop, '7d');
            $t = $data['totals'];
            $p = $data['previous'];
            $symbol = $shop->tenant->settings['currency_symbol'] ?? 'GH₵';
            $change = fn ($a, $b) => $b ? sprintf(' (%s%d%% on the week before)', $a >= $b ? '+' : '-', abs(round(($a - $b) / $b * 100))) : '';
            $topProduct = collect($data['products'])->first();
            $topSource = collect($data['sources'])->first();
            $names = ['whatsapp' => 'WhatsApp', 'instagram' => 'Instagram', 'facebook' => 'Facebook', 'tiktok' => 'TikTok', 'google' => 'Google', 'x' => 'X', 'marketplace' => 'Zinnvy marketplace', 'direct' => 'Direct link', 'other' => 'Other sites'];
            $link = rtrim((string) config('services.frontend_url'), '/').'/dashboard/shop/insights/';

            $body = "Hi {$shop->tenant->name},\n\nHere's how your online shop did last week ("
                .CarbonImmutable::parse($data['from'])->format('j M').' to '.CarbonImmutable::parse($data['to'])->format('j M')."):\n\n"
                ."Visitors: {$t['visitors']}".$change($t['visitors'], $p['visitors'])."\n"
                ."Product views: {$t['product_views']}\n"
                ."Added to cart: {$t['carters']}\n"
                ."Orders: {$t['orders']}".($t['orders'] ? ' worth '.$symbol.' '.number_format($t['order_value'], 2) : '').$change($t['orders'], $p['orders'])."\n"
                .($topProduct && $topProduct['views'] ? "Most viewed: {$topProduct['name']} ({$topProduct['views']} views)\n" : '')
                .($topSource ? 'Top source: '.($names[$topSource['source']] ?? $topSource['source'])." ({$topSource['visitors']} visitors)\n" : '')
                ."\n".collect($data['highlights'])->map(fn ($h) => '- '.$h['text'])->implode("\n")
                ."\n\nSee the full picture: {$link}\n\nZinnvy.";

            User::where('tenant_id', $shop->tenant_id)->where('role', 'Administrator')->pluck('email')
                ->each(function ($email) use ($body, $t, &$sent) {
                    MailHelper::sendEmailNotification($email, "Your shop last week: {$t['visitors']} visitors, {$t['orders']} order".($t['orders'] === 1 ? '' : 's'), $body);
                    $sent++;
                });
        }

        $this->info("Sent {$sent} digest email(s) for {$shops->count()} shop(s).");

        return self::SUCCESS;
    }
}
