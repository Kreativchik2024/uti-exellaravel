<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Уровень компьютера в морском бое: normal | strong (null — игра с коллегой). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('battleship_games', function (Blueprint $table) {
            $table->string('bot_level', 10)->nullable()->after('player2_id');
        });
    }

    public function down(): void
    {
        Schema::table('battleship_games', function (Blueprint $table) {
            $table->dropColumn('bot_level');
        });
    }
};
