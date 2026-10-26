<?php

namespace App\Support;

/**
 * NIP — Numéro d'Identification Personnel (Registre Biométrique des Personnes Physiques, Gabon).
 *
 * Format : CC-NNNN-AAAAMMJJ   (ex. fictif : 4A-0012-19870425)
 *   CC        clé de contrôle, 2 caractères alphanumériques
 *   NNNN      numéro d'ordre, 4 chiffres
 *   AAAAMMJJ  date de naissance
 *
 * La saisie est tolérante (minuscules, espaces, tirets absents) ; le NIP est
 * toujours stocké sous sa forme canonique en majuscules avec tirets.
 */
final class Nip
{
    public const FORMAT = 'CC-NNNN-AAAAMMJJ';

    public const EXAMPLE = '4A-0012-19870425';

    /** Forme saisie acceptée (côté Angular comme côté API). */
    public const INPUT_PATTERN = '^[0-9A-Za-z]{2}[- ]?[0-9]{4}[- ]?[0-9]{8}$';

    private const COMPACT = '/^([0-9A-Z]{2})([0-9]{4})([0-9]{4})([0-9]{2})([0-9]{2})$/';

    /**
     * Forme canonique (CC-NNNN-AAAAMMJJ), ou null si la saisie ne respecte pas la structure
     * ou contient une date de naissance impossible.
     */
    public static function normalize(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $compact = strtoupper(preg_replace('/[\s-]+/', '', $value));
        if (! preg_match(self::COMPACT, $compact, $m)) {
            return null;
        }

        [, $cle, $ordre, $annee, $mois, $jour] = $m;
        if (! checkdate((int) $mois, (int) $jour, (int) $annee) || "{$annee}{$mois}{$jour}" > date('Ymd')) {
            return null;
        }

        return "{$cle}-{$ordre}-{$annee}{$mois}{$jour}";
    }

    public static function isValid(?string $value): bool
    {
        return self::normalize($value) !== null;
    }

    /** Date de naissance encodée dans le NIP (Y-m-d). */
    public static function birthdate(string $nip): ?string
    {
        $canonical = self::normalize($nip);

        return $canonical ? substr($canonical, 8, 4).'-'.substr($canonical, 12, 2).'-'.substr($canonical, 14, 2) : null;
    }

    /**
     * NIP des administrateurs (PORTAIL_ADMIN_NIPS), normalisés.
     *
     * @return list<string>
     */
    public static function admins(): array
    {
        return array_values(array_filter(array_map(self::normalize(...), config('portail.admin_nips'))));
    }
}
