<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Игры (раздел «Игры» в меню профиля) — отдельно от рабочей статистики.
 *  settings          — настройки сайта (в т.ч. «игры включены»), переживают очистку кеша;
 *  game_sessions     — партии одиночных игр (сапёр, змейка) и их результаты;
 *  battleship_games  — партии морского боя (с коллегой или с компьютером).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('game_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('game', 20);                       // minesweeper | snake
            $table->string('level', 20)->nullable();          // сапёр: easy | medium | hard
            $table->json('state')->nullable();                // поле сапёра — только на сервере
            $table->string('status', 10)->default('playing'); // playing | won | lost | finished
            $table->unsignedInteger('score')->nullable();     // змейка
            $table->unsignedInteger('time_ms')->nullable();   // сапёр
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('finished_at')->nullable();

            $table->index(['game', 'level', 'status', 'finished_at']);
        });

        Schema::create('battleship_games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player1_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('player2_id')->nullable()->constrained('users')->cascadeOnDelete();   // null — компьютер
            $table->string('status', 12)->default('invited');  // invited | placing | playing | finished | declined | cancelled
            $table->json('ships1')->nullable();
            $table->json('ships2')->nullable();
            $table->json('shots1')->nullable();                // выстрелы игрока 1 по полю игрока 2
            $table->json('shots2')->nullable();
            $table->boolean('ready1')->default(false);
            $table->boolean('ready2')->default(false);
            $table->unsignedTinyInteger('turn')->default(1);   // чей ход: 1 или 2
            $table->foreignId('winner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('winner_bot')->default(false);
            $table->timestamp('last_move_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'finished_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('battleship_games');
        Schema::dropIfExists('game_sessions');
        Schema::dropIfExists('settings');
    }
};
