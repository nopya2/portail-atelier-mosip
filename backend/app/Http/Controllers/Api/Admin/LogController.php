<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Monolog\Level;

/**
 * Consultation du journal centralisé (réservé aux administrateurs).
 */
class LogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'source' => ['nullable', Rule::in(['backend', 'frontend'])],
            'level' => ['nullable', Rule::in(['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'])],
            'q' => ['nullable', 'string', 'max:200'],
            'request_id' => ['nullable', 'string', 'max:64'],
            'user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = AppLog::query()
            ->select(['id', 'source', 'level', 'level_value', 'message', 'exception_class', 'url', 'method', 'status_code', 'user_id', 'request_id', 'created_at'])
            ->with('user:id,name,nip')
            ->latest('created_at')->latest('id');

        if (! empty($filters['source'])) {
            $query->where('source', $filters['source']);
        }
        if (! empty($filters['level'])) {
            // niveau minimum : « warning » renvoie warning, error, critical…
            $query->where('level_value', '>=', Level::fromName($filters['level'])->value);
        }
        if (! empty($filters['q'])) {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $filters['q']).'%';
            $query->where(fn ($q) => $q->where('message', 'ilike', $term)->orWhere('exception_class', 'ilike', $term)->orWhere('url', 'ilike', $term));
        }
        if (! empty($filters['request_id'])) {
            $query->where('request_id', $filters['request_id']);
        }
        if (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }

        return response()->json($query->paginate($filters['per_page'] ?? 25));
    }

    public function show(AppLog $log): JsonResponse
    {
        $related = $log->request_id
            ? AppLog::where('request_id', $log->request_id)->whereKeyNot($log->id)
                ->orderBy('created_at')->limit(20)
                ->get(['id', 'source', 'level', 'message', 'created_at'])
            : [];

        return response()->json(['log' => $log->load('user:id,name,nip'), 'related' => $related]);
    }

    /** Synthèse des dernières 24 h par source et niveau. */
    public function stats(): JsonResponse
    {
        $rows = AppLog::where('created_at', '>=', now()->subDay())
            ->selectRaw('source, level, count(*) as total')
            ->groupBy('source', 'level')
            ->get();

        return response()->json([
            'periode' => '24h',
            'par_source' => $rows->groupBy('source')->map(fn ($g) => $g->pluck('total', 'level')),
            'total' => $rows->sum('total'),
        ]);
    }

    /** Déclenche volontairement une exception pour vérifier la chaîne de journalisation. */
    public function test(): never
    {
        throw new \RuntimeException('Erreur de test déclenchée depuis le journal (backend)');
    }
}
