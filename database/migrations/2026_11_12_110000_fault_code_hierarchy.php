<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Коды неисправностей по схеме предприятия: подгруппа («Кросс 3») → раздел («МИНИ ПК»),
 * коды вида C(3).3.10; у кода — разъяснение («при частом моргании синего светодиода…»).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fault_code_groups', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('id')->constrained('fault_code_groups')->cascadeOnDelete();   // раздел внутри подгруппы
            $table->unsignedSmallInteger('sort')->default(0)->after('name');
            $table->string('prefix', 30)->change();
        });
        Schema::table('fault_codes', function (Blueprint $table) {
            $table->text('hint')->nullable()->after('description');   // разъяснение
        });
    }

    public function down(): void
    {
        Schema::table('fault_codes', function (Blueprint $table) {
            $table->dropColumn('hint');
        });
        Schema::table('fault_code_groups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn('sort');
        });
    }
};
