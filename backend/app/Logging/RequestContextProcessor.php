<?php

namespace App\Logging;

use Illuminate\Support\Facades\Auth;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

/**
 * Ajoute à chaque entrée le contexte de la requête HTTP en cours
 * (identifiant de corrélation, URL, utilisateur…).
 */
class RequestContextProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = $record->extra;

        if (app()->bound('request_id')) {
            $extra['request_id'] = app('request_id');
        }

        if (! app()->runningInConsole() && app()->bound('request')) {
            $request = request();
            $extra['url'] = $request->fullUrl();
            $extra['method'] = $request->method();
            $extra['ip'] = $request->ip();
            $extra['user_agent'] = $request->userAgent();

            try {
                // hasUser() évite de déclencher une authentification pendant le log
                if (Auth::hasUser()) {
                    $extra['user_id'] = Auth::id();
                }
            } catch (Throwable) {
                // l'authentification n'est pas encore disponible
            }
        } else {
            $extra['url'] ??= 'console: '.implode(' ', array_slice($_SERVER['argv'] ?? [], 1, 3));
        }

        return $record->with(extra: $extra);
    }
}
