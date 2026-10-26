<?php

namespace Tests\Unit;

use App\Support\Nip;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NipTest extends TestCase
{
    #[DataProvider('saisiesValides')]
    public function test_normalise_les_saisies_valides(string $saisie, string $attendu): void
    {
        $this->assertSame($attendu, Nip::normalize($saisie));
    }

    public static function saisiesValides(): array
    {
        return [
            'forme canonique' => ['4A-0012-19870425', '4A-0012-19870425'],
            'minuscules' => ['4a-0012-19870425', '4A-0012-19870425'],
            'sans tirets' => ['4A001219870425', '4A-0012-19870425'],
            'espaces' => [' 4A 0012 19870425 ', '4A-0012-19870425'],
            'clé numérique' => ['07-1234-20000229', '07-1234-20000229'],
        ];
    }

    #[DataProvider('saisiesInvalides')]
    public function test_rejette_les_saisies_invalides(?string $saisie): void
    {
        $this->assertNull(Nip::normalize($saisie));
    }

    public static function saisiesInvalides(): array
    {
        return [
            'null' => [null],
            'vide' => [''],
            'ordre sur 3 chiffres' => ['4A-012-19870425'],
            'clé trop longue' => ['4AB-0012-19870425'],
            'ordre non numérique' => ['4A-00X2-19870425'],
            'date impossible' => ['4A-0012-19870231'],
            'mois 13' => ['4A-0012-19871325'],
            'date future' => ['4A-0012-29990101'],
            'caractère spécial' => ['4#-0012-19870425'],
        ];
    }

    public function test_extrait_la_date_de_naissance(): void
    {
        $this->assertSame('1987-04-25', Nip::birthdate('4a 0012 19870425'));
        $this->assertNull(Nip::birthdate('invalide'));
    }
}
