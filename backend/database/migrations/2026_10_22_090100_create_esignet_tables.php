<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Un parcours OIDC vers eSignet (étapes A à H de la fiche atelier).
         * Usage unique, durée de vie courte ; nonce et code_verifier chiffrés (cast « encrypted »).
         */
        Schema::create('esignet_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('mode', 16);                      // creation | connexion | rattachement
            $table->string('state', 128)->unique();
            $table->text('nonce')->nullable();
            $table->text('code_verifier')->nullable();
            $table->string('navigateur_hash', 64)->nullable(); // empreinte du cookie HttpOnly liant le parcours au navigateur
            $table->text('request_uri')->nullable();           // renvoyé par /oauth2/par
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // mode rattachement
            $table->string('statut', 24)->default('initiee');
            // initiee | retour_recu | jetons_recus | terminee | echec
            $table->string('acr', 64)->nullable();
            $table->string('error_code', 128)->nullable();
            $table->text('error_description')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestamp('expire_at');
            $table->timestamp('utilisee_at')->nullable();      // retour traité : un code ne s'échange qu'une fois
            $table->timestamps();

            $table->index(['statut', 'expire_at']);
        });

        /*
         * Identité vérifiée en attente de finalisation (écran « Finaliser mon compte »).
         * Le jeton remis au navigateur n'est stocké que haché ; les claims sont chiffrés.
         */
        Schema::create('esignet_inscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('transaction_id')->constrained('esignet_transactions')->cascadeOnDelete();
            $table->string('jeton_hash', 64)->unique();
            $table->string('sub');
            $table->string('nip', 16);
            $table->text('claims');                            // claims userinfo vérifiés (chiffrés)
            $table->string('acr', 64)->nullable();
            $table->timestamp('expire_at');                    // 15 min
            $table->timestamp('utilisee_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('esignet_inscriptions');
        Schema::dropIfExists('esignet_transactions');
    }
};
