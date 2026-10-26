<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('titre');
            $table->string('categorie');
            $table->string('administration');
            $table->text('description');
            $table->string('icone', 32)->default('document');
            $table->unsignedSmallInteger('delai_jours')->default(7);
            // Informations du profil de l'usager reprises dans la demande (name, birthdate…)
            $table->jsonb('infos_requises');
            // Champs complémentaires saisis par l'usager : [{name,label,type,required,options?}]
            $table->jsonb('champs')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });

        Schema::create('demandes', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->string('nip', 16); // NIP de l'usager au moment de la demande
            $table->string('statut', 24)->default('soumise'); // soumise | en_cours | validee | rejetee
            $table->jsonb('identite');   // instantané du profil de l'usager
            $table->jsonb('donnees')->nullable(); // champs complémentaires
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demandes');
        Schema::dropIfExists('services');
    }
};
