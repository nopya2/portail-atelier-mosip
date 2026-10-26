<?php

namespace App\Services\Esignet;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Erreur fonctionnelle du parcours eSignet, à lever depuis EsignetService.
 *
 * Codes attendus par le frontend (messages en français côté Angular) :
 *   access_denied, consentement_refuse, transaction_invalide, transaction_expiree,
 *   invalid_grant, invalid_assertion, jeton_invalide, nonce_invalide, acr_insuffisant,
 *   claim_manquant, nip_incoherent, nip_deja_rattache,
 *   inscription_expiree, configuration_incomplete
 *
 * Exemple : throw new EsignetException('acr_insuffisant', "Niveau d'authentification insuffisant.", 403);
 */
class EsignetException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details  informations sans secret (jamais de jeton ni de code)
     */
    public function __construct(
        public readonly string $code_erreur,
        string $message,
        public readonly int $statut = 400,
        public readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** Journalisée en « warning » (refus, rejeu…) ou « error » (panne), visible dans le Journal. */
    public function report(): void
    {
        Log::log($this->statut >= 500 ? 'error' : 'warning', "eSignet [{$this->code_erreur}] {$this->getMessage()}", [
            'exception' => $this,
            'error_code' => $this->code_erreur,
            'status_code' => $this->statut,
            'details' => $this->details,
        ]);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'error' => $this->code_erreur,
            'message' => $this->getMessage(),
            'request_id' => app()->bound('request_id') ? app('request_id') : null,
        ], $this->statut);
    }
}
