<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Фото к записям учёта отменены. Таблица удаляется, только если в ней ничего нет —
 * если фото уже успели прикрепить, данные остаются нетронутыми.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('record_photos') && DB::table('record_photos')->doesntExist()) {
            Schema::drop('record_photos');
        }
    }

    public function down(): void
    {
        // Возвращать нечего
    }
};
