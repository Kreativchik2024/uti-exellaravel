<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('research_records', function (Blueprint $table) {
            // Добавляем boolean-поле. По умолчанию false (не отремонтировано)
            $table->boolean('is_repaired')->default(false)->after('result');
        });
    }

    public function down(): void
    {
        Schema::table('research_records', function (Blueprint $table) {
            $table->dropColumn('is_repaired');
        });
    }
};