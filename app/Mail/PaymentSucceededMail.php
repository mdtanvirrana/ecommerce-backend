<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentSucceededMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly Payment $payment,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Payment Receipt for {$this->order->order_number}");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.orders.notification',
            with: [
                'order' => $this->order->loadMissing('items'),
                'headline' => 'Payment Received',
                'intro' => "We have verified your payment of \${$this->payment->amount} ".strtoupper($this->payment->currency)." for order {$this->order->order_number}.",
                'actionUrl' => config('services.frontend.url')."/orders/{$this->order->id}",
                'actionLabel' => 'Track Order',
            ],
        );
    }
}
