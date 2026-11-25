<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Empreinte du fichier .user.js, pour détecter une mise à jour sur les hébergeurs
 * qui ne donnent pas de date (risibank.fr, pastebin, serveurs perso…).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scripts', function (Blueprint $table) {
            $table->string('content_hash', 64)->nullable()->after('version');
        });
    }

    public function down(): void
    {
        Schema::table('scripts', function (Blueprint $table) {
            $table->dropColumn('content_hash');
        });
    }
};
