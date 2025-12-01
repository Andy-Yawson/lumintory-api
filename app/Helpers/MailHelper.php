<?php

namespace App\Helpers;

use App\Jobs\SendQueuedMail;
use App\Models\ProductForecast;
use App\Models\User;
use Log;

class MailHelper
{

    public static function sendEmailNotification($email, $subject, $message): void
    {
        self::queueEmail($email, $subject, $message);
    }

    public static function sendLowStockForecastEmail(User $user, ProductForecast $forecast): void
    {
        $product = $forecast->product;
        $riskLabel = strtoupper($forecast->stock_risk_level);
        $days = $forecast->predicted_days_to_stockout;

        $subject = "[$riskLabel] Low Stock Forecast – {$product->name}";
        $body = self::buildLowStockEmailBody(
            $user->name,
            $product->name,
            $forecast,
            $riskLabel,
            $days
        );

        self::sendEmailNotification($user->email, $subject, $body);
    }


    private static function buildLowStockEmailBody(
        string $recipientName,
        string $productName,
        ProductForecast $forecast,
        string $riskLabel,
        ?float $daysToStockOut
    ): string {
        $lines = [];

        $lines[] = "Hi {$recipientName},";
        $lines[] = "";
        $lines[] = "Our inventory forecast shows {$productName} may run out soon.";
        $lines[] = "";
        $lines[] = "• Current stock: {$forecast->current_quantity}";
        $lines[] = "• Avg daily sales: " . number_format($forecast->avg_daily_sales ?? 0, 2);
        $lines[] = "• Predicted days to stockout: " . ($daysToStockOut !== null ? round($daysToStockOut, 1) : 'N/A');
        $lines[] = "• Risk level: {$riskLabel}";

        if ($forecast->stock_risk_level === 'critical') {
            $lines[] = "";
            $lines[] = "⚠️ This is a CRITICAL risk item. Please restock as soon as possible.";
        }

        $lines[] = "";
        $lines[] = "You can view more details in your dashboard: " . url('/dashboard/products');
        $lines[] = "";
        $lines[] = "You received this because stock forecasting is enabled for your tenant.";
        $lines[] = "";
        $lines[] = "Regards,";
        $lines[] = config('app.name');

        return implode("\n", $lines);
    }


    private static function queueEmail($email, $subject, $body)
    {
        try {
            dispatch(new SendQueuedMail($email, $subject, $body));
        } catch (\Exception $e) {
            Log::error("Email queue failed to dispatch for $email: " . $e->getMessage());
        }
    }
}
