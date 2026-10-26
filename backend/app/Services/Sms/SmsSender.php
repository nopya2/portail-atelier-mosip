<?php

namespace App\Services\Sms;

/**
 * Envoi d'un SMS. Implémentations : MailpitSmsSender (développement), LogSmsSender.
 * Une passerelle réelle (opérateur, agrégateur) s'ajoute en implémentant cette interface
 * puis en la déclarant dans AppServiceProvider (SMS_DRIVER).
 */
interface SmsSender
{
    /**
     * @param  string  $to  numéro au format E.164 (+241…)
     */
    public function send(string $to, string $message): void;
}
