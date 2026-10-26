<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal centralisé des erreurs : backend (Monolog) et frontend (Angular).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_logs', function (Blueprint $table) {
            $table->id();
            $table->string('source', 16);               // backend | frontend
            $table->string('level', 16);                // debug … emergency
            $table->unsignedSmallInteger('level_value'); // valeur numérique Monolog (100 … 600)
            $table->string('channel', 64)->nullable();
            $table->text('message');
            $table->jsonb('context')->nullable();
            $table->string('exception_class')->nullable();
            $table->string('file', 1024)->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->text('trace')->nullable();
            $table->string('url', 2048)->nullable();
            $table->string('method', 10)->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('request_id', 64)->nullable()->index();
            $table->string('session_id', 64)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['source', 'level_value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_logs');
    }
};
