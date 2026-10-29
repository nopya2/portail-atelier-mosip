<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NIP facultatif dans l'inscription en attente : certaines instances eSignet ne transmettent pas
 * le claim individual_id ; l'usager saisit alors son NIP sur l'écran de finalisation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('esignet_inscriptions', function (Blueprint $table) {
            $table->string('nip', 16)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('esignet_inscriptions', function (Blueprint $table) {
            $table->string('nip', 16)->nullable(false)->change();
        });
    }
};
