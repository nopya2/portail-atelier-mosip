<?php

namespace App\Services\Sms;

use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * SMS simulé : déposé dans Mailpit (services mutualisés) pour être lu pendant les tests.
 * Destinataire : <numéro sans +>@sms.local — ex. 24107123456@sms.local
 */
class MailpitSmsSender implements SmsSender
{
    public function send(string $to, string $message): void
    {
        Mail::raw($message, function (Message $mail) use ($to) {
            $mail->to(ltrim($to, '+').'@sms.local')
                ->from('sms@sms.local', config('portail.sms_sender_name'))
                ->subject('[SMS] '.$to);
        });

        Log::info('SMS simulé envoyé (Mailpit)', ['to' => $to]);
    }
}
