<?php

namespace App\Mail;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderStatusChangedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly OrderStatus $previousStatus,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Order {$this->order->order_number} is now ".ucfirst($this->order->status->value));
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.orders.notification',
            with: [
                'order' => $this->order->loadMissing('items'),
                'headline' => 'Order Status Updated',
                'intro' => "Your order {$this->order->order_number} status changed from {$this->previousStatus->value} to {$this->order->status->value}.",
                'actionUrl' => config('services.frontend.url')."/orders/{$this->order->id}",
                'actionLabel' => 'View Order Status',
            ],
        );
    }
}
