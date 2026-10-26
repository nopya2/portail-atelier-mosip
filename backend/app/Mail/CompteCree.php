<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class CompteCree extends Mailable
{
    public function __construct(public readonly User $user) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Votre compte a été créé');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.compte-cree');
    }
}
