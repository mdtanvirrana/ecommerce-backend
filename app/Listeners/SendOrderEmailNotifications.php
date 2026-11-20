<?php

namespace App\Listeners;

use App\Events\OrderCancelled;
use App\Events\OrderConfirmed;
use App\Events\OrderStatusUpdated;
use App\Events\PaymentSucceeded;
use App\Events\RefundProcessed;
use App\Mail\OrderCancelledMail;
use App\Mail\OrderConfirmedMail;
use App\Mail\OrderStatusChangedMail;
use App\Mail\PaymentSucceededMail;
use App\Mail\RefundProcessedMail;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendOrderEmailNotifications
{
    public function handleOrderConfirmed(OrderConfirmed $event): void
    {
        $this->safeQueue($event->order->shipping_email, new OrderConfirmedMail($event->order), 'order_confirmed');
    }

    public function handlePaymentSucceeded(PaymentSucceeded $event): void
    {
        $this->safeQueue($event->order->shipping_email, new PaymentSucceededMail($event->order, $event->payment), 'payment_succeeded');
    }

    public function handleOrderStatusUpdated(OrderStatusUpdated $event): void
    {
        $this->safeQueue($event->order->shipping_email, new OrderStatusChangedMail($event->order, $event->previousStatus), 'order_status_updated');
    }

    public function handleOrderCancelled(OrderCancelled $event): void
    {
        $this->safeQueue($event->order->shipping_email, new OrderCancelledMail($event->order), 'order_cancelled');
    }

    public function handleRefundProcessed(RefundProcessed $event): void
    {
        $this->safeQueue($event->order->shipping_email, new RefundProcessedMail($event->order, $event->payment), 'refund_processed');
    }

    private function safeQueue(string $recipient, Mailable $mailable, string $context): void
    {
        try {
            Mail::to($recipient)->queue($mailable);
        } catch (\Throwable $e) {
            Log::warning("Unable to deliver {$context} email notification.", [
                'recipient' => $recipient,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
