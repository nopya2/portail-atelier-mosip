<?php

return [

    'frontend_url' => rtrim(env('FRONTEND_URL', 'http://localhost:4210'), '/'),

    // NIP des usagers ayant accès au journal des erreurs (format CC-NNNN-AAAAMMJJ, cf. App\Support\Nip)
    'admin_nips' => array_filter(array_map('trim', explode(',', (string) env('PORTAIL_ADMIN_NIPS', '')))),

    // Durée de validité du jeton d'API remis au frontend (minutes)
    'token_ttl' => (int) env('PORTAIL_TOKEN_TTL', 480),

    // Conservation du journal des erreurs (jours)
    'logs_retention_days' => (int) env('LOG_DB_RETENTION_DAYS', 30),

    // Téléphone : nombre de chiffres du numéro national (avec le 0 initial) [min, max]
    'phone_national_digits' => array_map('intval', explode(',', (string) env('PHONE_NATIONAL_DIGITS', '8,9'))),

    /*
    | Envoi des SMS. Aucune passerelle SMS n'est disponible dans les services mutualisés :
    |  - « mailpit » : le SMS est déposé dans Mailpit (http://localhost:8125), destinataire <numéro>@sms.local
    |  - « log »     : le SMS est seulement écrit dans les logs
    | Pour la production, ajouter un driver (Orange, Airtel…) implémentant App\Services\Sms\SmsSender.
    */
    'sms_driver' => env('SMS_DRIVER', 'mailpit'),
    'sms_sender_name' => env('SMS_SENDER_NAME', 'PORTAIL'),
];
