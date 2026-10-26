<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Création de compte avec eSignet : identité vérifiée par le registre.
 * Voir la fiche atelier « Créer un compte avec eSignet », section 3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Identifiant pairwise renvoyé par eSignet (claim « sub ») : ce n'est PAS le NIP
            $table->string('esignet_sub')->nullable()->unique()->after('nip');
            // Origine du NIP : « declare » (inscription locale) ou « esignet » (vérifié par le registre)
            $table->string('nip_source', 16)->default('declare')->after('esignet_sub');
            $table->timestamp('nip_verifie_at')->nullable()->after('nip_source');
            // Niveau d'authentification (claim « acr ») de la dernière vérification eSignet
            $table->string('acr', 64)->nullable()->after('nip_verifie_at');
            // Dernière mise à jour des données issues du registre
            $table->timestamp('registre_synchronise_at')->nullable()->after('acr');

            // Un compte créé avec eSignet n'a pas de mot de passe local
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['esignet_sub']);
            $table->dropColumn(['esignet_sub', 'nip_source', 'nip_verifie_at', 'acr', 'registre_synchronise_at']);
        });
    }
};
