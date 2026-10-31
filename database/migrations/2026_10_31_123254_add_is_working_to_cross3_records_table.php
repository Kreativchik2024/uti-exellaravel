<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Проверка: если колонка уже есть — не трогаем
        if (!Schema::hasColumn('cross3_records', 'is_working')) {
            Schema::table('cross3_records', function (Blueprint $table) {
                $table->string('is_working', 20)->nullable()->after('status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('cross3_records', 'is_working')) {
            Schema::table('cross3_records', function (Blueprint $table) {
                $table->dropColumn('is_working');
            });
        }
    }
};