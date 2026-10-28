<?php

use App\Http\Controllers\Api\Admin\LogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientLogController;
use App\Http\Controllers\Api\EsignetController;
use App\Http\Controllers\Api\ServiceController;
use Illuminate\Support\Facades\Route;

// --- Public -----------------------------------------------------------------
Route::get('services', [ServiceController::class, 'index']);
Route::get('services/{service}', [ServiceController::class, 'show']);

Route::prefix('auth')->group(function () {
    Route::get('nip-format', [AuthController::class, 'nipFormat']);

    // Création de compte et connexion : uniquement avec eSignet
    // (logique OIDC : App\Services\Esignet\EsignetService)
    Route::prefix('esignet')->middleware('throttle:esignet')->group(function () {
        Route::get('configuration', [EsignetController::class, 'configuration']);
        Route::post('demarrer', [EsignetController::class, 'demarrer']);
        Route::post('retour', [EsignetController::class, 'retour']);
        Route::get('inscription/{jeton}', [EsignetController::class, 'inscription'])->where('jeton', '[A-Za-z0-9_-]{32,128}');
        Route::post('inscription/{jeton}', [EsignetController::class, 'finaliser'])->where('jeton', '[A-Za-z0-9_-]{32,128}');
    });
});

// Erreurs remontées par le frontend
Route::post('logs', [ClientLogController::class, 'store'])->middleware('throttle:logs');

// --- Authentifié ------------------------------------------------------------
Route::middleware('auth:sanctum')->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::put('auth/profile', [AuthController::class, 'updateProfile']);
    Route::post('auth/logout', [AuthController::class, 'logout']);

    Route::get('demandes', [ServiceController::class, 'mesDemandes']);
    Route::post('services/{service}/demandes', [ServiceController::class, 'deposer']);

    // --- Administration -----------------------------------------------------
    Route::prefix('admin')->middleware('admin')->group(function () {
        Route::get('logs', [LogController::class, 'index']);
        Route::get('logs/stats', [LogController::class, 'stats']);
        Route::get('logs/{log}', [LogController::class, 'show']);
        // Déclenche volontairement une exception pour vérifier la chaîne de journalisation
        Route::post('logs/test', [LogController::class, 'test']);
    });
});
