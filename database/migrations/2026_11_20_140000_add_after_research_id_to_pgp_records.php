<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Цепочка документов борта: проверка ПГП «Не сдан» → исследование (ремонт) → повторная проверка ПГП
 * (новая запись, after_research_id — по какому ремонту) → «Сдан» или снова в ремонт.
 * Старые записи не трогаем: у них повторная проверка была правкой той же записи.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('pgp_records', function (Blueprint $table) {
            $table->foreignId('after_research_id')->nullable()->after('status')
                  ->constrained('research_records')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pgp_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('after_research_id');
        });
    }
};
