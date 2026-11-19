<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Пункты ремонта и замены в исследовании (их может быть несколько). Поля repair_* в research_records
 * остаются сводкой по пунктам: по ним работают технолог, ремонтник и журнал борта.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('research_repair_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('research_record_id')->constrained('research_records')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('action', 20);                       // repair | replacement
            $table->string('label', 255);                       // «МОТОРАМА · Кросс 3»
            $table->unsignedBigInteger('group_id')->nullable(); // блок справочника кодов
            $table->foreignId('item_id')->nullable()->constrained('repair_items')->nullOnDelete();   // свой вариант
            $table->timestamps();
            $table->index(['research_record_id', 'position']);
        });

        // Уже указанные ремонт/замена — первым пунктом
        $now = now();
        DB::table('research_records')->whereNotNull('repair_action')->where('repair_item', '!=', '')
            ->orderBy('id')->select(['id', 'repair_action', 'repair_item', 'repair_group_id', 'repair_item_id'])
            ->chunk(500, function ($rows) use ($now) {
                DB::table('research_repair_items')->insert($rows->map(fn ($r) => [
                    'research_record_id' => $r->id, 'position' => 0, 'action' => $r->repair_action, 'label' => $r->repair_item,
                    'group_id' => $r->repair_group_id, 'item_id' => $r->repair_item_id, 'created_at' => $now, 'updated_at' => $now,
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('research_repair_items');
    }
};
