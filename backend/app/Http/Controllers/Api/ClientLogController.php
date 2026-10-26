<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Logging\LogSanitizer;
use App\Models\AppLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Monolog\Level;

/**
 * Réception des erreurs remontées par le frontend Angular (par lots).
 * Route publique (une erreur peut survenir avant la connexion), limitée en débit.
 */
class ClientLogController extends Controller
{
    private const LEVELS = ['debug', 'info', 'warning', 'error', 'critical'];

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entries' => ['required', 'array', 'min:1', 'max:20'],
            'entries.*.level' => ['required', Rule::in(self::LEVELS)],
            'entries.*.message' => ['required', 'string', 'max:5000'],
            'entries.*.type' => ['nullable', 'string', 'max:64'],          // js_error | http_error | unhandled_rejection | manual
            'entries.*.name' => ['nullable', 'string', 'max:255'],         // nom de l'erreur JS (TypeError…)
            'entries.*.stack' => ['nullable', 'string', 'max:20000'],
            'entries.*.url' => ['nullable', 'string', 'max:2048'],         // page Angular
            'entries.*.http' => ['nullable', 'array'],                     // {method, url, status, request_id}
            'entries.*.context' => ['nullable', 'array'],
            'entries.*.timestamp' => ['nullable', 'date'],
            'entries.*.session_id' => ['nullable', 'string', 'max:64'],
            'entries.*.app_version' => ['nullable', 'string', 'max:32'],
        ]);

        $user = auth('sanctum')->user();
        $rows = [];

        foreach ($data['entries'] as $entry) {
            $level = Level::fromName($entry['level']);
            $http = $entry['http'] ?? [];
            $context = array_filter([
                'type' => $entry['type'] ?? null,
                'http' => $http ?: null,
                'app_version' => $entry['app_version'] ?? null,
                'client_timestamp' => $entry['timestamp'] ?? null,
                'details' => $entry['context'] ?? null,
            ]);

            $rows[] = [
                'source' => 'frontend',
                'level' => $entry['level'],
                'level_value' => $level->value,
                'channel' => 'angular',
                'message' => $entry['message'],
                'context' => json_encode(LogSanitizer::sanitize($context), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                'exception_class' => isset($entry['name']) ? mb_substr($entry['name'], 0, 255) : null,
                'trace' => $entry['stack'] ?? null,
                'url' => $entry['url'] ?? null,
                'method' => isset($http['method']) ? mb_substr((string) $http['method'], 0, 10) : null,
                'status_code' => isset($http['status']) && is_numeric($http['status']) ? (int) $http['status'] : null,
                'ip' => $request->ip(),
                'user_agent' => LogSanitizer::truncate($request->userAgent(), 1000),
                'user_id' => $user?->id,
                // corrélation avec le log backend de la même requête HTTP, si connu
                'request_id' => isset($http['request_id']) ? mb_substr((string) $http['request_id'], 0, 64) : app('request_id'),
                'session_id' => $entry['session_id'] ?? null,
                'created_at' => now(),
            ];

            Log::channel('frontend')->log($entry['level'], $entry['message'], $context + ['url' => $entry['url'] ?? null, 'user_id' => $user?->id]);
        }

        AppLog::insert($rows);

        return response()->json(['received' => count($rows)], 202);
    }
}
