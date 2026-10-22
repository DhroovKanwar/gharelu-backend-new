<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class OrderConfirmationMail extends Mailable
{
    public function __construct(public readonly Order $order, public readonly bool $forShop = false) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->forShop
                ? "New order {$this->order->order_number}"
                : "Your Gharelu Bake order {$this->order->order_number} is confirmed",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.order-confirmation');
    }
}
