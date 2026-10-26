<?php

namespace App\Mail;

use App\Models\Demande;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class DemandeRecue extends Mailable
{
    public function __construct(public readonly Demande $demande) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Accusé de réception — {$this->demande->reference}");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.demande-recue');
    }
}
