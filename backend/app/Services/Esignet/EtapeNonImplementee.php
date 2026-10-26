<?php

namespace App\Services\Esignet;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Levée par les méthodes d'EsignetService tant que l'étape n'est pas codée.
 * L'API répond 501 et le frontend affiche « Intégration eSignet en cours (étape X) ».
 */
class EtapeNonImplementee extends HttpException
{
    public function __construct(public readonly string $etape, string $libelle)
    {
        parent::__construct(501, "Étape {$etape} non implémentée : {$libelle}");
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'error' => 'non_implemente',
            'etape' => $this->etape,
            'message' => $this->getMessage(),
            'request_id' => app()->bound('request_id') ? app('request_id') : null,
        ], 501);
    }
}
