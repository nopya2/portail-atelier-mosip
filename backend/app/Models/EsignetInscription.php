<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Identité vérifiée par eSignet, en attente de l'écran de finalisation.
 * Le jeton remis au navigateur n'est conservé que sous forme de hachage (jeton_hash).
 */
class EsignetInscription extends Model
{
    use HasUuids, MassPrunable;

    protected $guarded = ['id'];

    protected $hidden = ['jeton_hash', 'claims', 'sub'];

    protected function casts(): array
    {
        return [
            'claims' => 'encrypted:array',
            'expire_at' => 'datetime',
            'utilisee_at' => 'datetime',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(EsignetTransaction::class, 'transaction_id');
    }

    public static function hacherJeton(string $jeton): string
    {
        return hash_hmac('sha256', $jeton, config('app.key'));
    }

    public function estUtilisable(): bool
    {
        return $this->utilisee_at === null && $this->expire_at->isFuture();
    }

    /** Inscriptions non finalisées : purgées après 24 h. */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDay());
    }
}
