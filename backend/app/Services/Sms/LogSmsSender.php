<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/** SMS écrit dans les logs uniquement (aucun envoi). */
class LogSmsSender implements SmsSender
{
    public function send(string $to, string $message): void
    {
        Log::info('SMS (driver log)', ['to' => $to, 'message' => $message]);
    }
}
