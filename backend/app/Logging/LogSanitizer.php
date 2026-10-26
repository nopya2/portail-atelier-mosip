<?php

namespace App\Logging;

use Throwable;

/**
 * Prépare un contexte de log pour stockage : masque les secrets, borne la taille,
 * convertit les objets en valeurs JSON-sérialisables.
 */
class LogSanitizer
{
    /** Clés masquées lorsqu'elles correspondent exactement. */
    private const SECRET_KEYS = ['pin', 'otp', 'code', 'nonce', 'authorization', 'cookie'];

    /** Clés masquées dès qu'elles se terminent par l'un de ces suffixes (ex. access_token). */
    private const SECRET_SUFFIXES = ['password', 'token', 'tokens', 'secret', 'private_key', 'api_key', 'assertion', 'verifier'];

    private const MAX_DEPTH = 5;

    private const MAX_STRING = 4000;

    private const MAX_ITEMS = 50;

    public static function sanitize(mixed $value, int $depth = 0): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            return '[profondeur max]';
        }

        if ($value instanceof Throwable) {
            return ['class' => $value::class, 'message' => $value->getMessage()];
        }

        if (is_object($value)) {
            $value = method_exists($value, 'toArray') ? $value->toArray()
                : ($value instanceof \JsonSerializable ? $value->jsonSerialize() : '['.$value::class.']');
        }

        if (is_array($value)) {
            $out = [];
            $i = 0;
            foreach ($value as $key => $item) {
                if (++$i > self::MAX_ITEMS) {
                    $out['…'] = 'tronqué ('.count($value).' éléments)';
                    break;
                }
                $out[$key] = is_string($key) && self::isSecret($key)
                    ? '***'
                    : self::sanitize($item, $depth + 1);
            }

            return $out;
        }

        if (is_string($value) && mb_strlen($value) > self::MAX_STRING) {
            return mb_substr($value, 0, self::MAX_STRING).'… [tronqué]';
        }

        if (is_resource($value)) {
            return '[ressource]';
        }

        return $value;
    }

    public static function truncate(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max).'… [tronqué]' : $value;
    }

    private static function isSecret(string $key): bool
    {
        $key = strtolower($key);
        if (in_array($key, self::SECRET_KEYS, true)) {
            return true;
        }
        foreach (self::SECRET_SUFFIXES as $suffix) {
            if (str_ends_with($key, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
