<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'infos_requises' => 'array',
            'champs' => 'array',
            'actif' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function demandes(): HasMany
    {
        return $this->hasMany(Demande::class);
    }
}
