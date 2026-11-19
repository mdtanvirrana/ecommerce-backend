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
use Illuminate\Support\Facades\Mail;

class SendOrderEmailNotifications
{
    public function handleOrderConfirmed(OrderConfirmed $event): void
    {
        Mail::to($event->order->shipping_email)->queue(new OrderConfirmedMail($event->order));
    }

    public function handlePaymentSucceeded(PaymentSucceeded $event): void
    {
        Mail::to($event->order->shipping_email)->queue(new PaymentSucceededMail($event->order, $event->payment));
    }

    public function handleOrderStatusUpdated(OrderStatusUpdated $event): void
    {
        Mail::to($event->order->shipping_email)->queue(new OrderStatusChangedMail($event->order, $event->previousStatus));
    }

    public function handleOrderCancelled(OrderCancelled $event): void
    {
        Mail::to($event->order->shipping_email)->queue(new OrderCancelledMail($event->order));
    }

    public function handleRefundProcessed(RefundProcessed $event): void
    {
        Mail::to($event->order->shipping_email)->queue(new RefundProcessedMail($event->order, $event->payment));
    }
}
