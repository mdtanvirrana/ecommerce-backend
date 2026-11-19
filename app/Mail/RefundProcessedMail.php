<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RefundProcessedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly Payment $payment,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Refund Processed for {$this->order->order_number}");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.orders.notification',
            with: [
                'order' => $this->order->loadMissing('items'),
                'headline' => 'Refund Processed',
                'intro' => "A refund of \${$this->payment->amount} ".strtoupper($this->payment->currency)." has been processed for order {$this->order->order_number}.",
                'actionUrl' => config('services.frontend.url')."/orders/{$this->order->id}",
                'actionLabel' => 'View Order Details',
            ],
        );
    }
}
