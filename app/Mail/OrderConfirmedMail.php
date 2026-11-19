<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderConfirmedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Order $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Order Confirmed: {$this->order->order_number}");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.orders.notification',
            with: [
                'order' => $this->order->loadMissing('items'),
                'headline' => 'Thank you for your order!',
                'intro' => "Your order {$this->order->order_number} has been confirmed and is now being prepared.",
                'actionUrl' => config('services.frontend.url')."/orders/{$this->order->id}",
                'actionLabel' => 'View Order Details',
            ],
        );
    }
}
