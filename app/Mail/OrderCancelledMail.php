<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderCancelledMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Order $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Order Cancelled: {$this->order->order_number}");
    }

    public function content(): Content
    {
        $reason = $this->order->cancellation_reason
            ? " Reason: {$this->order->cancellation_reason}."
            : '';

        return new Content(
            markdown: 'emails.orders.notification',
            with: [
                'order' => $this->order->loadMissing('items'),
                'headline' => 'Order Cancelled',
                'intro' => "Your order {$this->order->order_number} has been cancelled.{$reason}",
                'actionUrl' => config('services.frontend.url')."/orders/{$this->order->id}",
                'actionLabel' => 'View Order',
            ],
        );
    }
}
