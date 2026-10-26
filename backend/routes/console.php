<?php

use App\Models\AppLog;
use App\Models\EsignetInscription;
use App\Models\EsignetTransaction;
use Illuminate\Support\Facades\Schedule;

// Purge quotidienne : journal au-delà de LOG_DB_RETENTION_DAYS, parcours eSignet (> 7 jours)
// et identités eSignet non finalisées (> 24 h)
// (lancer « php artisan schedule:work » ou un cron ; le conteneur « scheduler » le fait en Docker)
Schedule::command('model:prune', ['--model' => [AppLog::class, EsignetTransaction::class, EsignetInscription::class]])->daily();
