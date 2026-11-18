<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('research_records', function (Blueprint $table) {
            // Узел, который исследуется: любой (список вкладок участка + свой вариант)
            $table->string('category', 120)->nullable()->after('seq');
            // new — не обработан, in_work — в работе, analysis — отправлен в разбор, conveyor — отправлен в конвейер
            $table->string('repair_status', 20)->default('new')->after('is_repaired');
            $table->index('repair_status');
        });

        // Уже отремонтированные — «отправлен в конвейер»
        DB::table('research_records')->where('is_repaired', true)->update(['repair_status' => 'conveyor']);
    }

    public function down(): void
    {
        Schema::table('research_records', function (Blueprint $table) {
            $table->dropIndex(['repair_status']);
            $table->dropColumn(['category', 'repair_status']);
        });
    }
};
