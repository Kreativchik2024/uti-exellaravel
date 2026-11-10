<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Вход с кодом из приложения на телефоне отменён. Если колонки успели создаться
 * (миграция add_two_factor_to_users выполнялась на сервере) — удаляем их.
 */
return new class extends Migration
{
    public function up(): void
    {
        $columns = array_values(array_filter(
            ['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'],
            fn ($c) => Schema::hasColumn('users', $c),
        ));
        if ($columns) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }

    public function down(): void
    {
        // Возвращать нечего
    }
};
