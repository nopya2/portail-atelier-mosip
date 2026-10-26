<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Parcours OIDC vers eSignet (un par clic sur « Créer mon compte / Se connecter avec eSignet »).
 */
class EsignetTransaction extends Model
{
    use HasUuids, MassPrunable;

    public const MODES = ['creation', 'connexion'];

    protected $guarded = ['id'];

    protected $hidden = ['nonce', 'code_verifier', 'navigateur_hash', 'request_uri'];

    protected function casts(): array
    {
        return [
            'nonce' => 'encrypted',
            'code_verifier' => 'encrypted',
            'expire_at' => 'datetime',
            'utilisee_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Parcours encore utilisable : non expiré et retour pas encore traité. */
    public function estUtilisable(): bool
    {
        return $this->utilisee_at === null && $this->expire_at->isFuture();
    }

    /** Purge quotidienne des parcours de plus de 7 jours (php artisan model:prune). */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(7));
    }
}
