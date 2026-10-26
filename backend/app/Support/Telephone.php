<?php

namespace App\Support;

/**
 * Numéros de téléphone gabonais, normalisés au format E.164 (+241…).
 *
 * Saisies acceptées : « 07 12 34 56 », « 077 12 34 56 », « +241 07123456 », « 00241… ».
 * Le nombre de chiffres nationaux est paramétrable (PHONE_NATIONAL_DIGITS, défaut 8 ou 9)
 * pour suivre le plan de numérotation en vigueur.
 */
final class Telephone
{
    public const INDICATIF = '+241';

    public static function normalize(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $digits = preg_replace('/[^\d+]/', '', $value);
        $digits = preg_replace('/^(\+241|00241)/', '', $digits);

        if (! preg_match('/^\d+$/', $digits)) {
            return null;
        }

        [$min, $max] = config('portail.phone_national_digits');
        $len = strlen($digits);

        return $len >= $min && $len <= $max && str_starts_with($digits, '0')
            ? self::INDICATIF.$digits
            : null;
    }

    /** Affichage masqué pour les messages : +241 07 •• •• 56 */
    public static function mask(string $e164): string
    {
        $national = substr($e164, strlen(self::INDICATIF));

        return self::INDICATIF.' '.substr($national, 0, 2).' '.str_repeat('•', max(0, strlen($national) - 4)).' '.substr($national, -2);
    }
}
