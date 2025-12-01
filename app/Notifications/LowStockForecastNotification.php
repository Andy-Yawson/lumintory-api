<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\ProductForecast;

class LowStockForecastNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ProductForecast $forecast
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $product = $this->forecast->product;
        $days = $this->forecast->predicted_days_to_stockout;
        $risk = strtoupper($this->forecast->stock_risk_level);

        $mail = (new MailMessage)
            ->subject("[$risk] Low Stock Forecast – {$product->name}")
            ->greeting("Hi {$notifiable->name},")
            ->line("Our inventory forecast shows {$product->name} may run out soon.")
            ->line("• Current stock: {$this->forecast->current_quantity}")
            ->line("• Avg daily sales: " . number_format($this->forecast->avg_daily_sales ?? 0, 2))
            ->line("• Predicted days to stockout: " . ($days !== null ? round($days, 1) : 'N/A'))
            ->action('View in Dashboard', url('/dashboard/products'))
            ->line('You received this because stock forecasting is enabled for your tenant.');

        if ($this->forecast->stock_risk_level === 'critical') {
            $mail->line('⚠️ This is a CRITICAL risk item. Please restock as soon as possible.');
        }

        return $mail;
    }


    public function toArray(object $notifiable): array
    {
        return [
            'product_id' => $this->forecast->product_id,
            'product_name' => $this->forecast->product?->name,
            'current_quantity' => $this->forecast->current_quantity,
            'avg_daily_sales' => $this->forecast->avg_daily_sales,
            'predicted_days_to_stockout' => $this->forecast->predicted_days_to_stockout,
            'stock_risk_level' => $this->forecast->stock_risk_level,
            'forecasted_at' => $this->forecast->forecasted_at,
        ];
    }
}
