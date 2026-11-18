<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Свои варианты узлов для ремонта/замены (кроме блоков справочника кодов) — после подтверждения администратором
        Schema::create('repair_items', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->foreignId('group_id')->nullable()->constrained('fault_code_groups')->nullOnDelete();
            $table->boolean('is_approved')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['is_approved', 'group_id']);
        });

        Schema::table('research_records', function (Blueprint $table) {
            // Что сделал ремонтник: repair — ремонт, replacement — замена; и какой узел
            $table->string('repair_action', 20)->nullable()->after('result');
            $table->string('repair_item', 255)->nullable()->after('repair_action');   // путь узла текстом — для показа и статистики
            $table->foreignId('repair_group_id')->nullable()->after('repair_item')->constrained('fault_code_groups')->nullOnDelete();
            $table->foreignId('repair_item_id')->nullable()->after('repair_group_id')->constrained('repair_items')->nullOnDelete();
            // Акт на замену: отмечает технолог (ОТК)
            $table->timestamp('act_done_at')->nullable()->after('repair_item_id');
            $table->foreignId('act_done_by')->nullable()->after('act_done_at')->constrained('users')->nullOnDelete();
            $table->index(['repair_action', 'act_done_at']);
        });

        // Прежнее поле «Замена» → «Замена: <узел>» (старая колонка остаётся, но больше не используется)
        DB::table('research_records')->whereNotNull('replacement')->where('replacement', '!=', '')
            ->whereRaw('LOWER(TRIM(replacement)) != ?', ['без замены'])
            ->update(['repair_action' => 'replacement', 'repair_item' => DB::raw('TRIM(replacement)')]);
    }

    public function down(): void
    {
        Schema::table('research_records', function (Blueprint $table) {
            $table->dropIndex(['repair_action', 'act_done_at']);
            $table->dropForeign(['repair_group_id']);
            $table->dropForeign(['repair_item_id']);
            $table->dropForeign(['act_done_by']);
            $table->dropColumn(['repair_action', 'repair_item', 'repair_group_id', 'repair_item_id', 'act_done_at', 'act_done_by']);
        });
        Schema::dropIfExists('repair_items');
    }
};
