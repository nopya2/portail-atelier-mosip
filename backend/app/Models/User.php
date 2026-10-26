<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Usager du portail. Le NIP (Numéro d'Identification Personnel) est obligatoire,
 * unique et non modifiable après l'inscription.
 */
class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $fillable = [
        'nip',
        'name',
        'given_name',
        'family_name',
        'email',
        'password',
        'phone_number',
        'birthdate',
        'gender',
        'address',
        'picture',
        'locale',
        'is_admin',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'esignet_sub',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'nip_verifie_at' => 'datetime',
            'registre_synchronise_at' => 'datetime',
            'password' => 'hashed',
            'birthdate' => 'date:Y-m-d',
            'address' => 'array',
            'is_admin' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function demandes(): HasMany
    {
        return $this->hasMany(Demande::class);
    }
}
