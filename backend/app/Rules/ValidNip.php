<?php

namespace App\Rules;

use App\Support\Nip;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Vérifie la structure du NIP et, si fournie, sa cohérence avec la date de naissance.
 */
class ValidNip implements ValidationRule
{
    public function __construct(private readonly ?string $birthdate = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $nip = Nip::normalize(is_string($value) ? $value : null);

        if ($nip === null) {
            $fail('Format de NIP invalide (attendu : '.Nip::FORMAT.', ex. '.Nip::EXAMPLE.').');

            return;
        }

        if ($this->birthdate && strtotime($this->birthdate) !== false
            && date('Y-m-d', strtotime($this->birthdate)) !== Nip::birthdate($nip)) {
            $fail('La date de naissance ne correspond pas à celle contenue dans le NIP.');
        }
    }
}
