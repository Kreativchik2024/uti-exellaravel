<?php

use App\Models\AuditLog;
use App\Models\FaultCode;
use App\Models\FaultCodeGroup;
use App\Services\FaultCodes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Выравнивание подуровней дерева кодов неисправностей:
 *  1. ВМР: коды ВМР.n → ВМР.1.n (три части, как у соседей: C(1).1.n, H.1.n, C(3).3.n), префикс ВМР.1.
 *  2. Порядковые номера уровней без дыр (после удаления ВМГ и отдельной БЧ): 01, 02, 03…
 *  3. Бывшие БЧ.1 / БЧ.2 (C(3).1.7, C(3).1.1) — сняты с проверки: описания выверены прошлой чисткой.
 * Коды в записях учёта переписываются (FaultCodes::replaceInRecords).
 */
return new class extends Migration {
    private array $log = [];

    public function up(): void
    {
        DB::transaction(function () {
            // --- 1. ВМР.n → ВМР.1.n
            $frame = FaultCodeGroup::query()->whereNull('parent_id')->where('name', 'МОТОРАМА')->first();
            $vmr = $frame ? FaultCodeGroup::query()->where('parent_id', $frame->id)->where('name', 'ВМР')->first() : null;
            if ($vmr && $vmr->prefix === 'ВМР' && ! FaultCodeGroup::where('prefix', 'ВМР.1')->exists()) {
                foreach (FaultCode::where('group_id', $vmr->id)->get() as $c) {
                    if (! preg_match('/^ВМР\.(\d+)$/u', $c->code, $m)) continue;
                    $to = 'ВМР.1.' . $m[1];
                    if (FaultCode::where('code', $to)->exists()) continue;
                    $old = $c->code;
                    $c->forceFill(['code' => $to])->save();
                    $n = app(FaultCodes::class)->replaceInRecords($old, $to);
                    $this->log[] = "{$old} → {$to}, записей: {$n}";
                }
                $this->log[] = 'Префикс «ВМР»: ВМР → ВМР.1';
                $vmr->forceFill(['prefix' => 'ВМР.1'])->save();
            }

            // --- 2. Номера уровней подряд внутри каждого родителя
            $all = FaultCodeGroup::query()->get();
            foreach ($all->groupBy(fn ($g) => $g->parent_id ?? 0) as $siblings) {
                $i = 0;
                foreach ($siblings->sortBy([['sort', 'asc'], ['name', 'asc']]) as $g) {
                    $i++;
                    if ((int) $g->sort === $i || (int) $g->sort >= 999) continue;
                    $this->log[] = "«{$g->name}»: номер {$g->sort} → {$i}";
                    $g->forceFill(['sort' => $i])->save();
                }
            }

            // --- 3. Снять с проверки бывшие коды БЧ
            foreach (['C(3).1.1', 'C(3).1.7'] as $code) {
                if (FaultCode::where('code', $code)->where('needs_review', true)->update(['needs_review' => false])) {
                    $this->log[] = "{$code}: снят с проверки";
                }
            }

            if ($this->log) {
                AuditLog::write('fault_codes', 'updated', 'Подуровни кодов неисправностей выровнены: ' . count($this->log) . ' изменений',
                    ['section_id' => null, 'changes' => ['изменения' => [null, implode("\n", $this->log)]]]);
            }
        });
    }

    public function down(): void
    {
        // Данные справочника не откатываются: список изменений — в журнале действий («Коды неисправностей»)
    }
};
