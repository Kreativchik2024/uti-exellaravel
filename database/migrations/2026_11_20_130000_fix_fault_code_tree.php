<?php

use App\Models\AuditLog;
use App\Models\FaultCode;
use App\Models\FaultCodeGroup;
use App\Services\FaultCodes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Чистка дерева кодов неисправностей (после разбора несовпадений):
 *  1. ВМР (C.1.x — нумерация как у Кросс 1) → под МОТОРАМУ, коды ВМР.n; «жгут модуля связи» → КОММУТАЦИЯ.
 *  2. Роутер: коды датчика скорости (G.5.6, G.5.7) — дубли G.8.1, G.8.3, объединены; дальше нумерация без дыр.
 *  3. ВМГ (R.1.x) — дубль ХВОСТА: коды разложены по его подгруппам (регулятор / мотор / лопасти), ВМГ убрана.
 *  4. Два БЧ: отдельная группа БЧ (БЧ.1, БЧ.2) влита в «Кросс 3 → БЧ» (C(3).1.x), вкладка «БЧ» видит её.
 *  5. Префиксы: Кросс 3 «МКC33» (смесь кириллицы и латиницы) → C(3); G1/G2/G3 и W1 → G.1/G.2/G.3 и W.1, как у соседей.
 *  6. КОММУТАЦИЯ видна и на вкладках Data 15 / Data 17; ХВОСТ — в ПГП, «Регулятор мотора» — на своей вкладке.
 *  7. Опечатки и неполные описания.
 * Коды в записях учёта переписываются (FaultCodes::replaceInRecords). Чего нет в справочнике — пропускается.
 */
return new class extends Migration {
    private array $log = [];

    public function up(): void
    {
        DB::transaction(function () {
            $g = fn (string $name, ?string $parent = null) => $this->group($name, $parent);

            // --- 5. Префиксы
            $this->setPrefix($g('Кросс 3 (Cross 3)', 'МОТОРАМА'), 'C(3)');
            foreach (['Дневная камера' => ['G1', 'G.1'], 'Кросс 5 (Cross 5)' => ['G2', 'G.2'], 'Ночная камера' => ['G3', 'G.3']] as $name => [$from, $to]) {
                $grp = $g($name, 'КРОНШТЕЙН КАМЕРЫ');
                if ($grp && $grp->prefix === $from) $this->renumberGroup($grp, $from, $to);
            }
            $wings = $g('КРЫЛЬЯ', 'ФЮЗЕЛЯЖ');
            if ($wings && $wings->prefix === 'W1') $this->renumberGroup($wings, 'W1', 'W.1');

            // --- 1. ВМР → под МОТОРАМУ, коды ВМР.n
            $frame = $g('МОТОРАМА');
            $vmr = $g('ВМР');
            $comm = $g('КОММУТАЦИЯ');
            if ($vmr && $frame) {
                $vmr->forceFill(['parent_id' => $frame->id])->save();
                $this->log[] = 'ВМР перенесена под МОТОРАМУ';
                if ($comm) $this->move('C.1.8', 'H.1.8', $comm);
                for ($n = 1; $n <= 7; $n++) $this->move("C.1.$n", "ВМР.$n", $vmr);
                if ($vmr->prefix === 'C.1') $this->setPrefix($vmr, 'ВМР');
                $this->describe('ВМР.1', '12_ВМР', 'Неисправна ВМР');
            }

            // --- 2. Роутер: дубли датчика скорости
            $this->merge('G.5.6', 'G.8.1');
            $this->merge('G.5.7', 'G.8.3');
            $router = $g('Роутер', 'КРОНШТЕЙН КАМЕРЫ');
            if ($router) foreach (['G.5.8' => 'G.5.6', 'G.5.9' => 'G.5.7', 'G.5.10' => 'G.5.8'] as $from => $to) $this->move($from, $to, $router);

            // --- 3. ВМГ → подгруппы ХВОСТА
            $tail = $g('ХВОСТ');
            $esc = $g('Регулятор мотора', 'ХВОСТ');
            $motor = $g('Мотор', 'ХВОСТ');
            $blades = $g('Лопасти мотора', 'ХВОСТ');
            $vmg = $g('ВМГ');
            if ($vmg && $tail && $esc && $motor && $blades) {
                foreach (['R.1.1' => 'ХВ.1.1', 'R.1.2' => 'ХВ.1.2', 'R.1.3' => 'ХВ.1.3', 'R.1.4' => 'ХВ.1.4', 'R.1.5' => 'ХВ.1.5'] as $from => $to) $this->move($from, $to, $esc);
                foreach (['R.1.6' => 'ХВ.2.1', 'R.1.7' => 'ХВ.2.2', 'R.1.8' => 'ХВ.2.3'] as $from => $to) $this->move($from, $to, $motor);
                foreach (['R.1.9' => 'ХВ.3.1', 'R.1.10' => 'ХВ.3.2'] as $from => $to) $this->move($from, $to, $blades);
                $this->dropGroup($vmg, $tail);
                // Коды ВМГ теперь в ХВОСТЕ — он виден в ПГП (как была ВМГ), регулятор — и на своей вкладке
                $this->addSheets($tail, (array) $vmg->sheets ?: ['pgp']);
                $this->addSheets($esc, ['motor_regulator']);
            }

            // --- 4. БЧ: отдельная группа → Кросс 3 → БЧ
            $bchTop = FaultCodeGroup::query()->whereNull('parent_id')->where('name', 'БЧ')->first();
            $bchC3 = $g('БЧ', 'Кросс 3 (Cross 3)');
            if ($bchTop && $bchC3) {
                $this->move('БЧ.2', 'C(3).1.1', $bchC3);
                $this->move('БЧ.1', 'C(3).1.7', $bchC3);
                $this->describe('C(3).1.1', 'Не работает', 'Не работает БЧ');
                $this->describe('C(3).1.7', 'Не прошит', 'Не прошита БЧ');
                $this->addSheets($bchC3, (array) $bchTop->sheets ?: ['bch']);
                $this->dropGroup($bchTop, $bchC3);
            }

            // --- 6. Коммутация — и на вкладках кабелей DATA
            if ($comm) $this->addSheets($comm, ['data15', 'data17']);

            // --- 7. Опечатки и неполные описания
            $this->describe('C(3).2.2', 'Отсутствует', 'Отсутствует компас');
            $this->describe('ХВ.1.2', 'Отсутствует ПО / ESC', 'Отсутствует ПО регулятора мотора (ESC)');
            $this->describe('ХВ.3.1', null, 'Винт крепления лопасти не соответствует моменту затяжки');
            $this->describe('ХВ.3.2', null, 'Кронштейн крепления лопасти не соответствует моменту затяжки');
            $this->describe('G.5.4', 'Недоставлен кабель питания роутера', 'Не до конца вставлен кабель питания роутера');
            $this->describe('C(3).3.10', null, 'Демонтирована Wi-Fi антенна');
            $typos = [
                'Dana 17' => 'Data 17', 'разьём' => 'разъём', 'Неисправлен ' => 'Неисправен ', 'разъем PSHUT' => 'разъём PSHUT',
                'поляронсть' => 'полярность', 'лопости' => 'лопасти', 'срорости' => 'скорости', 'Демонтированна' => 'Демонтирована',
                'DС-' => 'DC-',   // кириллическая «С» в «DC-24V», «DC-52V»
                'Неисправна трубка' => 'Неисправна трубка датчика скорости',
            ];
            foreach (FaultCode::all() as $c) {
                $new = strtr((string) $c->description, $typos);
                if ($new !== (string) $c->description) {
                    $this->log[] = "{$c->code}: «{$c->description}» → «{$new}»";
                    $c->forceFill(['description' => $new])->save();
                }
            }

            AuditLog::write('fault_codes', 'updated', 'Дерево кодов неисправностей исправлено: ' . count($this->log) . ' изменений',
                ['section_id' => null, 'changes' => ['изменения' => [null, implode("\n", $this->log)]]]);
        });
    }

    public function down(): void
    {
        // Данные справочника не откатываются: список изменений — в журнале действий («Коды неисправностей»)
    }

    // ================================================================ помощники

    private function group(string $name, ?string $parent = null): ?FaultCodeGroup
    {
        $q = FaultCodeGroup::query()->where('name', $name);
        if ($parent) {
            $p = FaultCodeGroup::query()->where('name', $parent)->pluck('id');
            if ($p->isEmpty()) return null;
            $q->whereIn('parent_id', $p);
        }

        return $q->first();
    }

    private function setPrefix(?FaultCodeGroup $grp, string $prefix): void
    {
        if (! $grp || $grp->prefix === $prefix || FaultCodeGroup::where('prefix', $prefix)->exists()) return;
        $this->log[] = "Префикс «{$grp->name}»: {$grp->prefix} → {$prefix}";
        $grp->forceFill(['prefix' => $prefix])->save();
    }

    /** Коды группы «G1.3» → «G.1.3» и префикс группы. */
    private function renumberGroup(FaultCodeGroup $grp, string $from, string $to): void
    {
        foreach (FaultCode::where('group_id', $grp->id)->get() as $c) {
            if (str_starts_with($c->code, $from . '.')) $this->move($c->code, $to . substr($c->code, strlen($from)), $grp);
        }
        $this->setPrefix($grp, $to);
    }

    /** Переименовать код (и перенести в группу); новый код уже есть — объединить. */
    private function move(string $from, string $to, FaultCodeGroup $grp): void
    {
        $code = FaultCode::where('code', $from)->first();
        if (! $code) return;
        if (FaultCode::where('code', $to)->exists()) {
            $this->merge($from, $to);
            return;
        }
        $code->forceFill(['code' => $to, 'group_id' => $grp->id])->save();
        $n = app(FaultCodes::class)->replaceInRecords($from, $to);
        $this->log[] = "{$from} → {$to} ({$grp->name}), записей: {$n}";
    }

    /** Дубль: записи — на основной код, дубль удаляется. */
    private function merge(string $from, string $into): void
    {
        $code = FaultCode::where('code', $from)->first();
        if (! $code || ! FaultCode::where('code', $into)->exists()) return;
        $n = app(FaultCodes::class)->replaceInRecords($from, $into);
        $code->delete();
        $this->log[] = "{$from} объединён с {$into}, записей: {$n}";
    }

    /** Описание кода; $old — только если сейчас именно такое (null — любое). */
    private function describe(string $code, ?string $old, string $new): void
    {
        $c = FaultCode::where('code', $code)->first();
        if (! $c || ($old !== null && trim((string) $c->description) !== $old) || $c->description === $new) return;
        $this->log[] = "{$code}: «{$c->description}» → «{$new}»";
        $c->forceFill(['description' => $new])->save();
    }

    private function addSheets(FaultCodeGroup $grp, array $sheets): void
    {
        $now = (array) $grp->sheets;
        $all = array_values(array_unique(array_merge($now, $sheets)));
        if ($all === $now) return;
        $grp->forceFill(['sheets' => $all])->save();
        $this->log[] = "«{$grp->name}» видна на вкладках: " . implode(', ', $all);
    }

    /** Удалить пустую группу; ссылки исследований и узлов ремонта — на $into. */
    private function dropGroup(FaultCodeGroup $grp, FaultCodeGroup $into): void
    {
        if (FaultCode::where('group_id', $grp->id)->exists() || FaultCodeGroup::where('parent_id', $grp->id)->exists()) return;
        foreach ([['research_records', 'repair_group_id'], ['repair_items', 'group_id'], ['research_repair_items', 'group_id']] as [$table, $col]) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $col)) DB::table($table)->where($col, $grp->id)->update([$col => $into->id]);
        }
        $this->log[] = "Группа «{$grp->name}» убрана (ссылки — на «{$into->name}»)";
        $grp->delete();
    }
};
