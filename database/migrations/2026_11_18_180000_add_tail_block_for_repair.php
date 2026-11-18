<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Блок «ХВОСТ» для выбора «Ремонт / Замена» в исследованиях (вместо убранных ВМГ, ВМР, КОММУТАЦИЯ):
 * регулятор мотора, мотор, лопасти мотора. Это обычный блок справочника кодов неисправностей —
 * его видно и можно править в «Кодах неисправностей». Вкладки не отмечены, поэтому в выпадающих кодах форм его нет.
 * Повторный запуск дублей не создаёт.
 */
return new class extends Migration {
    private const TOP = 'ХВОСТ';
    private const PARTS = ['Регулятор мотора', 'Мотор', 'Лопасти мотора'];

    public function up(): void
    {
        $now = now();
        $find = fn (string $name, ?int $parent) => DB::table('fault_code_groups')
            ->where('parent_id', $parent)->get(['id', 'name'])
            ->first(fn ($g) => mb_strtoupper(trim($g->name)) === mb_strtoupper($name))?->id;
        $prefix = function (string $base) {
            for ($p = $base, $i = 2; DB::table('fault_code_groups')->where('prefix', $p)->exists(); $i++) $p = $base . $i;
            return $p;
        };

        $top = $find(self::TOP, null) ?? DB::table('fault_code_groups')->insertGetId([
            'name' => self::TOP, 'prefix' => $prefix('ХВ'), 'sort' => 3, 'sheets' => json_encode([]),
            'parent_id' => null, 'section_id' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);

        foreach (self::PARTS as $n => $part) {
            if ($find($part, $top)) continue;
            DB::table('fault_code_groups')->insert([
                'name' => $part, 'prefix' => $prefix('ХВ.' . ($n + 1)), 'sort' => $n + 1, 'sheets' => json_encode([]),
                'parent_id' => $top, 'section_id' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $top = DB::table('fault_code_groups')->whereNull('parent_id')->where('name', self::TOP)->value('id');
        if (! $top) return;
        // Только если к блоку не привязали коды — иначе оставляем
        $ids = DB::table('fault_code_groups')->where('parent_id', $top)->pluck('id')->push($top);
        if (DB::table('fault_codes')->whereIn('group_id', $ids)->exists()) return;
        DB::table('fault_code_groups')->where('parent_id', $top)->delete();
        DB::table('fault_code_groups')->where('id', $top)->delete();
    }
};
