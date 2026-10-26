<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppLog extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    protected $connection = 'logs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Purge automatique (php artisan model:prune) au-delà de la durée de conservation. */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(config('portail.logs_retention_days')));
    }
}
