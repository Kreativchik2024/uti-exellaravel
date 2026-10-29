<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cross1_records', function (Blueprint $table) {
            // Убираем уникальность (запрет повтора)
            $table->dropUnique('unique_cross1_record');

            // Оставляем обычный индекс — это и есть «привязка» для быстрого поиска
            $table->index(['record_date', 'worker1'], 'cross1_records_record_date_worker1_index');
        });
    }

    public function down(): void
    {
        Schema::table('cross1_records', function (Blueprint $table) {
            $table->dropIndex('cross1_records_record_date_worker1_index');
            $table->unique(['record_date', 'worker1'], 'unique_cross1_record');
        });
    }
};