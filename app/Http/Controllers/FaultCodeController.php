<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\FaultCode;
use App\Models\FaultCodeGroup;
use App\Models\Section;
use App\Services\FaultCodes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Справочник кодов неисправностей (админ): список, добавление, правка, загрузка из Excel/CSV.
 * Коды выпадают в поле «Код неисправности» во всех вкладках учёта.
 */
class FaultCodeController extends Controller
{
    public function index(Request $r)
    {
        $q = trim((string) $r->query('q', ''));
        $group = $r->query('group');
        $tree = FaultCodeGroup::tree(true);
        $codes = FaultCode::query()->with(['section:id,name', 'creator:id,name'])
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x
                ->where('code', 'like', '%' . addcslashes($q, '%_\\') . '%')
                ->orWhere('description', 'like', '%' . addcslashes($q, '%_\\') . '%')))
            ->when($group === 'none', fn ($w) => $w->whereNull('group_id'))
            ->when(ctype_digit((string) $group), fn ($w) => $w->whereIn('group_id', $tree[(int) $group]?->selfAndDescendantIds($tree) ?? [0]))
            ->when($r->boolean('review'), fn ($w) => $w->where('needs_review', true))
            ->orderByDesc('needs_review')->orderBy('code')
            ->paginate(100)->withQueryString();
        FaultCodeGroup::attachTo($codes, $tree);

        return view('fault-codes.index', [
            'codes'    => $codes,
            'q'        => $q,
            'group'    => $group,
            'review'   => $r->boolean('review'),
            'groups'   => $tree->sortBy(fn ($g) => $g->sortKey())->values(),
            'sheets'   => collect(config('sheets.map'))->map(fn ($c) => $c['title'])->all(),
            'sections' => Section::ordered()->get(['id', 'name']),
            'total'    => FaultCode::count(),
            'toReview' => FaultCode::where('needs_review', true)->count(),
        ]);
    }

    public function store(Request $r)
    {
        if (trim((string) $r->input('code')) === '' && $r->filled('group_id')) {
            $grp = FaultCodeGroup::findOrFail((int) $r->input('group_id'));
            $r->merge(['code' => app(FaultCodes::class)->nextCode($grp)]);   // код подставляется сам
        }
        $data = $this->validated($r);
        $code = $this->upsert($data['code'], $data['description'] ?? null, $data['section_id'] ?? null, $data['group_id'] ?? null, $data['hint']);
        AuditLog::write('fault_codes', 'created', "Код неисправности {$code->code}", ['subject_type' => 'fault_code', 'subject_id' => $code->id, 'section_id' => null]);

        return back()->with('ok', "Код {$code->code} сохранён");
    }

    public function update(Request $r, FaultCode $faultCode)
    {
        $data = $this->validated($r, $faultCode);
        $before = $faultCode->only(['code', 'description', 'is_active']);
        $faultCode->update($data + ['is_active' => $r->boolean('is_active'), 'needs_review' => false]);
        AuditLog::write('fault_codes', 'updated', "Код неисправности {$faultCode->code}", ['subject_type' => 'fault_code', 'subject_id' => $faultCode->id, 'section_id' => null,
            'changes' => collect($faultCode->only(['code', 'description', 'is_active']))->map(fn ($v, $k) => [$before[$k], $v])->filter(fn ($p) => $p[0] != $p[1])->all()]);

        return back()->with('ok', "Код {$faultCode->code} изменён");
    }

    public function destroy(FaultCode $faultCode)
    {
        AuditLog::write('fault_codes', 'deleted', "Код неисправности {$faultCode->code} — {$faultCode->description}", ['subject_type' => 'fault_code', 'subject_id' => $faultCode->id, 'section_id' => null]);
        $faultCode->delete();

        return back()->with('ok', 'Код удалён. В уже сохранённых записях он останется.');
    }

    /** Автоматически добавленный код проверен. */
    public function approve(FaultCode $faultCode)
    {
        $faultCode->update(['needs_review' => false]);

        return back()->with('ok', "Код {$faultCode->code} проверен");
    }

    /**
     * Объединить дубль с существующим кодом: во всех записях учёта код заменяется,
     * дубль удаляется (например, автоматически добавили неисправность, которая уже была).
     */
    public function merge(Request $r, FaultCode $faultCode)
    {
        $target = trim((string) $r->validate(['target' => ['required', 'string', 'max:100']])['target']);
        $into = FaultCode::where('code', $target)->first();
        if (! $into || $into->id === $faultCode->id) {
            return back()->withErrors(['target' => "Код «{$target}» не найден в справочнике"]);
        }

        $changed = app(FaultCodes::class)->replaceInRecords($faultCode->code, $into->code);
        AuditLog::write('fault_codes', 'updated', "Код {$faultCode->code} объединён с {$into->code}: исправлено записей {$changed}",
            ['subject_type' => 'fault_code', 'subject_id' => $into->id, 'section_id' => null]);
        $faultCode->delete();

        return back()->with('ok', "Код {$faultCode->code} объединён с {$into->code}, в записях исправлено: {$changed}");
    }

    /** Стереть весь справочник. Коды в записях учёта остаются и привяжутся к новым при загрузке. */
    public function wipe(Request $r)
    {
        $r->validate(['confirm' => ['required', 'in:СТЕРЕТЬ']], ['confirm.*' => 'Для подтверждения введите слово СТЕРЕТЬ.']);
        $n = app(FaultCodes::class)->wipe();
        AuditLog::write('fault_codes', 'deleted', "Справочник кодов неисправностей стёрт: {$n} кодов (в архив для привязки)", ['section_id' => null]);

        return back()->with('ok', "Справочник стёрт: {$n} кодов. В записях учёта коды остались — после загрузки новых кодов они привяжутся к тем же или похожим.");
    }

    // ---------------------------------------------------------------- категории

    public function storeGroup(Request $r)
    {
        $data = $this->groupData($r);
        $group = FaultCodeGroup::create($data);
        AuditLog::write('fault_codes', 'created', "Категория кодов «{$group->name}» ({$group->prefix})", ['section_id' => null]);

        return back()->with('ok', "«{$group->name}» создан, коды: {$group->prefix}.1, {$group->prefix}.2…");
    }

    public function updateGroup(Request $r, FaultCodeGroup $group)
    {
        $group->update($this->groupData($r, $group));

        return back()->with('ok', "«{$group->name}» сохранён");
    }

    public function destroyGroup(FaultCodeGroup $group)
    {
        AuditLog::write('fault_codes', 'deleted', "Категория кодов «{$group->name}»", ['section_id' => null]);
        $group->delete();   // коды остаются, становятся общими

        return back()->with('ok', 'Удалено, коды стали общими');
    }

    private function groupData(Request $r, ?FaultCodeGroup $group = null): array
    {
        $data = $r->validate([
            'name'     => ['required', 'string', 'max:120'],
            'parent_id' => ['nullable', 'integer', Rule::exists('fault_code_groups', 'id')],
            'prefix'   => ['nullable', 'string', 'max:80', 'regex:/^[\p{L}\p{N}().\-_]+$/u', Rule::unique('fault_code_groups', 'prefix')->ignore($group)],
            'sheets'   => ['nullable', 'array'],
            'sheets.*' => ['string', Rule::in(array_keys(config('sheets.map')))],
        ], ['prefix.regex' => 'Основа кода — буквы, цифры, точки и скобки, например C(3).3', 'prefix.unique' => 'Такая основа кода уже есть']);
        $data['prefix'] = trim((string) ($data['prefix'] ?? '')) ?: $this->freePrefix(FaultCodes::derivePrefix($data['name']));
        if ($group && ! empty($data['parent_id'])) {
            $tree = FaultCodeGroup::tree();
            abort_if(in_array((int) $data['parent_id'], $tree[$group->id]->selfAndDescendantIds($tree), true), 422, 'Уровень нельзя вложить сам в себя');
        }
        $data['sheets'] = array_values($data['sheets'] ?? []);

        return $data;
    }

    /** Загрузка из Excel (.xlsx, .xls) или CSV: колонки «Код» и «Описание». */
    public function import(Request $r)
    {
        $r->validate([
            'file'       => ['required', 'file', 'max:10240', 'mimes:xlsx,xls,csv,txt'],
            'section_id' => ['nullable', 'integer', Rule::exists('sections', 'id')],
        ], ['file.mimes' => 'Нужен файл Excel (.xlsx, .xls) или CSV.']);

        $sheets = \App\Support\TableReader::sheets($r->file('file'));
        $sectionId = $r->input('section_id') ?: null;

        // Лист с таблицей кодов (свой код у каждой неисправности: «общий вид») — на любом листе.
        // Нет такого — журнал анализа отказов («Код», «Код первопричины», «Формализованный дефект»).
        $sheetName = array_key_first($sheets);
        $rows = $sheets[$sheetName] ?? [];
        $col = $this->detectColumns($rows);
        $picked = false;
        foreach ($sheets as $name => $sheetRows) {
            if ($this->detectJournal($sheetRows)) continue;   // журнал отказов — не таблица кодов
            $c = $this->detectColumns($sheetRows);
            if (isset($c['codeHeader']) && $this->codeRepeats($sheetRows, $c)[0] <= $this->repeatLimit($sheetRows, $c)) {
                [$sheetName, $rows, $col, $picked] = [$name, $sheetRows, $c, true];
                break;
            }
        }
        if (! $picked) {
            foreach ($sheets as $sheetRows) {
                if ($journal = $this->detectJournal($sheetRows)) return $this->importJournal($sheetRows, $journal, $sectionId);
            }
        }

        // Проверка до записи: если «коды» массово повторяются, колонка выбрана не та — ничего не грузим
        [$repeats, $values] = $this->codeRepeats($rows, $col);
        if ($repeats > $this->repeatLimit($rows, $col)) {
            $top = collect(array_count_values($values))->sortDesc()->take(4)->map(fn ($n, $v) => mb_strtoupper($v) . " — {$n} раз")->implode(', ');
            return back()->withErrors(['file' => "Ничего не загружено: в колонке «{$col['codeHeader']}» значения повторяются ({$repeats} повторов на " . count($values) . " строк: {$top}). "
                . 'Это не коды неисправностей, а, похоже, код системы или группы. У каждой неисправности должен быть свой код — '
                . 'в колонке «общий вид» (или «Код неисправности»), как C(3).3.1. '
                . (! empty($col['headers']) ? 'Колонки в файле: «' . implode('», «', $col['headers']) . '».' : 'Проверьте шапку файла.')]);
        }

        $added = $updated = $skipped = 0;
        $seen = $duplicates = $touched = [];
        $prev = [];
        foreach (array_slice($rows, $col['start'], null, true) as $i => $row) {
            $cell = fn ($k) => $col[$k] !== null ? trim(preg_replace('/\s+/u', ' ', (string) ($row[$col[$k]] ?? ''))) : '';
            $code = mb_substr(self::latinCode($cell('code')), 0, 100);
            if ($code === '') { $skipped++; continue; }

            // Уровни Р1, Р2, Р3… Объединённые ячейки Excel: значение есть только в первой строке —
            // пустая ячейка значит «как в строке выше», если уровни над ней те же
            $levels = [];
            $same = true;
            foreach ($col['levels'] as $k => $c) {
                $v = trim(preg_replace('/\s+/u', ' ', (string) ($row[$c] ?? '')));
                if ($v === '' && $same) $v = $prev[$k] ?? '';
                $same = $same && $v === ($prev[$k] ?? '');
                $levels[$k] = $v;
            }
            $prev = $levels;

            if (isset($seen[mb_strtolower($code)])) {   // один код у двух неисправностей — ошибка в файле
                $duplicates[] = "{$code} (строки {$seen[mb_strtolower($code)]} и " . ($i + 1) . ')';
                $skipped++;
                continue;
            }
            $seen[mb_strtolower($code)] = $i + 1;

            // «Нет», «СМ Р3», «СМ ОПИСАНИЕ» — уровня нет, путь заканчивается
            $group = null;
            foreach ($levels as $name) {
                if (self::isPlaceholder($name)) break;
                $group = $this->groupByName($name, $group);
            }
            $groupId = $group?->id;
            if ($groupId) $touched[$groupId] = true;

            $existed = $this->find($code, $sectionId);
            $desc = mb_substr($cell('desc'), 0, 255);
            // Есть колонка подгруппы — файл главный: код переезжает туда, где он в файле (в т.ч. в общие)
            $this->upsert($code, $desc !== '' ? $desc : null, $sectionId, $groupId, $cell('hint') !== '' ? $cell('hint') : null, (bool) $col['levels']);
            $existed ? $updated++ : $added++;
        }

        // Основа кода группы — по её кодам (C(3).3.1…C(3).3.10 → C(3).3): по ней нумеруются новые
        foreach (FaultCodeGroup::whereIn('id', array_keys($touched))->get() as $g) {
            $base = FaultCodes::baseOf($g->codes()->pluck('code')->all());
            if ($base && $base !== $g->prefix && ! FaultCodeGroup::where('prefix', $base)->whereKeyNot($g->id)->exists()) {
                $g->update(['prefix' => $base]);
            }
        }

        // Подгруппы и разделы, оставшиеся без кодов после перераспределения, убираем
        $removed = 0;
        if ($col['levels']) {
            do {   // снизу вверх: опустел нижний уровень — может опустеть и верхний
                $empty = FaultCodeGroup::doesntHave('codes')->doesntHave('children')->get();
                foreach ($empty as $g) { $g->delete(); $removed++; }
            } while ($empty->isNotEmpty());
        }

        AuditLog::write('fault_codes', 'imported', "Загрузка кодов неисправностей: добавлено {$added}, обновлено {$updated}", ['section_id' => null]);

        if ($added + $updated === 0) {
            return back()->withErrors(['file' => 'В файле не найдено ни одного кода. Нужны колонки «общий вид» (или «Код») и «Описание».']);
        }

        $msg = (count($sheets) > 1 ? "Лист «{$sheetName}». " : '') . "Загружено: добавлено {$added}, обновлено {$updated}" . ($removed ? ", убрано пустых подгрупп и разделов: {$removed}" : '');
        $link = app(FaultCodes::class)->relinkArchive();
        if ($link['linked'] || $link['left']) {
            $msg .= ". Старые коды: привязано {$link['linked']}" . ($link['records'] ? " (в записях исправлено {$link['records']})" : '');
            if ($link['left']) $msg .= ', без пары ' . count($link['left']) . ': ' . implode(', ', array_slice($link['left'], 0, 15)) . (count($link['left']) > 15 ? '…' : '')
                . ' — в записях остались как были, их можно объединить с новыми кнопкой «Объединить»';
        }
        if ($duplicates) {
            $list = implode('; ', array_slice($duplicates, 0, 10)) . (count($duplicates) > 10 ? ' и ещё ' . (count($duplicates) - 10) : '');
            return back()->with('ok', $msg)->withErrors(['file' => 'В файле один и тот же код у разных неисправностей — загружена только первая: ' . $list . '. Исправьте код у второй и загрузите файл ещё раз.']);
        }

        return back()->with('ok', $msg);
    }

    /**
     * Подгруппа («09_Кросс 3 (Cross 3)» → «Кросс 3 (Cross 3)», порядок 9) или раздел внутри неё.
     * Номер в начале отбрасывается: «10_Кросс 1» и «11_Кросс 1» — одна подгруппа.
     */
    private function groupByName(string $raw, ?FaultCodeGroup $parent, ?string $prefix = null): FaultCodeGroup
    {
        $sort = preg_match('/^(\d+)[_\s.]+/u', $raw, $m) ? (int) $m[1] : 0;
        $name = trim(preg_replace('/^\d+[_\s.]+/u', '', $raw)) ?: $raw;
        // «КРОСС_1» → «КРОСС 1»; коды вида SYS_CAM_N, HW-CONN-PIN оставляем как есть
        if ($prefix === null) $name = trim(preg_replace('/\s+/u', ' ', str_replace('_', ' ', $name)));

        $existing = FaultCodeGroup::where('parent_id', $parent?->id)->get()
            ->first(fn ($g) => mb_strtolower($g->name) === mb_strtolower($name));
        if ($existing) return $existing;

        return FaultCodeGroup::create([
            'parent_id' => $parent?->id,
            'name'      => mb_substr($name, 0, 120),
            'sort'      => $sort,
            'prefix'    => $this->freePrefix($prefix ?? mb_substr(FaultCodes::derivePrefix(($parent ? $parent->name . ' ' : '') . $name), 0, 26)),
            'sheets'    => $this->guessSheets($name, $parent === null),
        ]);
    }

    /**
     * Вкладки для новой подгруппы: где название вкладки содержит все слова подгруппы
     * («Кросс 3» → «Кросс 3», «Роутер» → «Прошивка роутеров»), плюс ПГП — борт целиком.
     */
    private function guessSheets(string $name, bool $top = true): array
    {
        $main = trim(preg_split('/[,(]/u', $name)[0]);   // «Регулятор мотора , Электромотор» → «Регулятор мотора»
        $words = self::wordRoots($main);
        $sheets = [];
        foreach (config('sheets.map') as $slug => $cfg) {
            if (! isset($cfg['fields']['fault_code'])) continue;
            // Все слова уровня есть в названии вкладки (с точностью до окончания):
            // «Роутер» → «Прошивка роутеров», «Кронштейн камеры» → «Кронштейн с камерой», но «Моторама» ≠ «мотора»
            $title = self::wordRoots($cfg['title']);
            if (($words && ! array_diff($words, $title)) || ($top && $slug === 'pgp')) $sheets[] = $slug;
        }

        return $sheets;
    }

    /** Слова без окончаний: «камерой» → «камер», «роутеров» → «роутер», «КРОСС_1» → «кросс», «1». */
    private static function wordRoots(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', str_replace('ё', 'е', mb_strtolower($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stop = ['и', 'в', 'на', 'с', 'со', 'по', 'к', 'о', 'об', 'от', 'для', 'из', 'у', 'при', 'не', 'в сборе'];
        $endings = ['ами', 'ями', 'ого', 'его', 'ому', 'ему', 'ыми', 'ими', 'ой', 'ей', 'ий', 'ый', 'ая', 'яя', 'ое', 'ее', 'ые', 'ие',
                    'ов', 'ев', 'ам', 'ям', 'ах', 'ях', 'ом', 'ем', 'а', 'я', 'ы', 'и', 'у', 'ю', 'е', 'о', 'ь'];
        $out = [];
        foreach ($words as $w) {
            if (in_array($w, $stop, true)) continue;
            foreach ($endings as $e) {
                if (mb_strlen($w) - mb_strlen($e) >= 3 && str_ends_with($w, $e)) { $w = mb_substr($w, 0, -mb_strlen($e)); break; }
            }
            $out[] = $w;
        }

        return array_values(array_unique($out));
    }

    /** Справочник в Excel — в том же виде, что загружается обратно. */
    public function export()
    {
        return \Maatwebsite\Excel\Facades\Excel::download(new \App\Exports\FaultCodesExport(), 'Коды неисправностей ' . now()->format('Y-m-d') . '.xlsx');
    }

    /** Шаблон для заполнения — в том же виде, что выгрузка. */
    public function template()
    {
        $cols = [['title' => 'НПП', 'type' => 'int', 'width' => 7], ['title' => 'Р1', 'width' => 24], ['title' => 'Р2', 'width' => 24], ['title' => 'Р3', 'width' => 24],
                 ['title' => 'Описание', 'width' => 50, 'wrap' => true], ['title' => 'общий вид', 'width' => 13], ['title' => 'Разъяснение', 'width' => 50, 'wrap' => true]];
        $rows = [
            [1, '01_МОТОРАМА', '01_Кросс 3 (Cross 3)', '03_МИНИ ПК', 'Неисправен мини ПК', 'C(3).3.1', ''],
            [2, '01_МОТОРАМА', '01_Кросс 3 (Cross 3)', '02_КОМПАС', 'Отсутствует', 'C(3).2.2', 'При частом моргании синего светодиода компас неисправен'],
            [3, '01_МОТОРАМА', '02_КРОСС_1', 'СМ ОПИСАНИЕ', 'Неисправен Cross 1', 'C(1).1.1', ''],
            [4, '04_ВМР', 'СМ Р3', 'СМ ОПИСАНИЕ', 'Отсутствует ПО', 'C.1.2', ''],
        ];
        $help = [
            ['НПП', 'Не обязателен, при загрузке не учитывается.'],
            ['Р1, Р2, Р3', 'Уровни: Р1 — узел борта («01_МОТОРАМА»), Р2 — изделие («01_Кросс 3 (Cross 3)»), Р3 — часть изделия («03_МИНИ ПК»). '
                . 'Число в начале задаёт порядок в списке. Нет уровня — «СМ Р3», «СМ ОПИСАНИЕ» или «Нет». Уровней может быть и больше: Р4, Р5…'],
            ['Описание', 'Текст неисправности — его видят в выпадающем списке.'],
            ['общий вид', 'Сам код: C(3).3.1. Коды не должны повторяться. Новые коды раздела продолжают его нумерацию.'],
            ['Разъяснение', 'Как распознать неисправность. Не обязательно.'],
            ['Объединённые ячейки', 'Можно: пустая ячейка уровня берётся из строки выше.'],
        ];

        return \Maatwebsite\Excel\Facades\Excel::download(new \App\Exports\Support\Workbook([
            \App\Exports\Support\TableSheet::make('Коды', null, [], [['columns' => $cols, 'rows' => $rows]]),
            \App\Exports\Support\TableSheet::make('Как заполнять', 'Загрузка кодов неисправностей', [], [[
                'columns' => [['title' => 'Колонка'], ['title' => 'Что писать', 'width' => 80, 'wrap' => true]], 'rows' => $help]]),
        ]), 'Коды неисправностей — шаблон.xlsx');
    }

    // ================================================================ служебное

    private function validated(Request $r, ?FaultCode $existing = null): array
    {
        $data = $r->validate([
            'code'        => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'hint'        => ['nullable', 'string', 'max:1000'],
            'section_id'  => ['nullable', 'integer', Rule::exists('sections', 'id')],
            'group_id'    => ['nullable', 'integer', Rule::exists('fault_code_groups', 'id')],
        ]);
        $data['hint'] = isset($data['hint']) && trim($data['hint']) !== '' ? trim($data['hint']) : null;
        $data['group_id'] = $data['group_id'] ?? null;
        $data['code'] = trim($data['code']);
        $data['section_id'] = $data['section_id'] ?? null;

        $dup = $this->find($data['code'], $data['section_id']);
        abort_if($dup && $existing && $dup->id !== $existing->id, 422, 'Такой код уже есть');

        return $data;
    }

    private function find(string $code, $sectionId): ?FaultCode
    {
        return FaultCode::where('code', $code)
            ->when($sectionId, fn ($q) => $q->where('section_id', $sectionId), fn ($q) => $q->whereNull('section_id'))
            ->first();
    }

    private function upsert(string $code, ?string $description, $sectionId, $groupId = null, ?string $hint = null, bool $setGroup = false): FaultCode
    {
        $row = $this->find($code, $sectionId) ?? new FaultCode(['code' => $code, 'section_id' => $sectionId ?: null]);
        if ($description !== null) $row->description = $description;
        if ($groupId || $setGroup) $row->group_id = $groupId;
        if ($hint !== null) $row->hint = mb_substr($hint, 0, 1000);
        $row->is_active = true;
        $row->save();

        return $row;
    }

    /** Строки файла: Excel — через PhpSpreadsheet, CSV — сами (кодировка и разделитель как у русского Excel). */
    private function readRows(UploadedFile $file): array
    {
        return \App\Support\TableReader::rows($file);
    }

    /**
     * Колонки по заголовку. Таблица предприятия: «код подгруппы», «Раздел», «Описание»,
     * «общий вид» (это код), «Разъяснение»; «порядковый номер» не нужен.
     * Без заголовка: код, описание, категория — 1-я, 2-я, 3-я колонки.
     */
    private function detectColumns(array $rows): array
    {
        foreach (array_slice($rows, 0, 5, true) as $i => $row) {
            $col = ['code' => null, 'desc' => null, 'group' => null, 'sub' => null, 'hint' => null];
            $numbered = $codeCols = [];
            foreach ($row as $c => $cell) {
                $h = mb_strtolower(trim((string) $cell));
                if ($h === '' || preg_match('/(порядков|^№|^n$|^#|^нпп$|^п\/п$)/u', $h)) continue;
                // Р1, Р2, Р3 (или «уровень 1») — уровни по порядку номеров
                if (preg_match('/^(?:р|p|ур(?:овень)?)\s*\.?\s*(\d+)$/u', $h, $m)) { $numbered[(int) $m[1]] = $c; continue; }
                if ($col['hint'] === null && preg_match('/(разъясн|пояснен|примечан|комментар|подсказ|hint)/u', $h)) $col['hint'] = $c;
                elseif ($col['sub'] === null && preg_match('/^(под)?раздел/u', $h)) $col['sub'] = $c;
                elseif ($col['group'] === null && preg_match('/(подгрупп|категор|групп|издели|узел|вкладк|category|group)/u', $h)) $col['group'] = $c;
                elseif (preg_match('/^(код|code|шифр|общий вид|обозначен)/u', $h)) $codeCols[] = $c;
                elseif ($col['desc'] === null && preg_match('/(описан|расшифр|наимен|неисправ|причин|description|name)/u', $h)) $col['desc'] = $c;
            }
            if ($codeCols) {
                // Колонок «код…» несколько (код системы, код неисправности) — берём ту, где значения
                // почти не повторяются: у кода неисправности каждое значение своё
                $data = array_slice($rows, $i + 1);
                $col['code'] = collect($codeCols)->sortByDesc(function ($c) use ($data, $row) {
                    $vals = array_values(array_filter(array_map(fn ($r) => mb_strtolower(trim((string) ($r[$c] ?? ''))), $data), fn ($v) => $v !== ''));
                    $unique = $vals ? count(array_unique($vals)) / count($vals) : 0;
                    $preferred = preg_match('/общий вид|неисправ/u', mb_strtolower((string) $row[$c])) ? 0.001 : 0;
                    return $unique + $preferred;
                })->first();
                $col['codeHeader'] = trim((string) $row[$col['code']]);
                $col['headers'] = array_values(array_filter(array_map(fn ($h) => trim((string) $h), $row), fn ($h) => $h !== ''));
                ksort($numbered);
                $col['levels'] = $numbered ? array_values($numbered) : array_values(array_filter([$col['group'], $col['sub']], fn ($x) => $x !== null));
                return $col + ['start' => $i + 1];
            }
        }
        $width = max(array_map('count', array_slice($rows, 0, 5)) ?: [0]);

        return ['code' => 0, 'desc' => 1, 'group' => $width >= 3 ? 2 : null, 'sub' => null, 'hint' => null, 'start' => 0, 'levels' => $width >= 3 ? [2] : [], 'codeHeader' => 'первая колонка'];
    }

    // ================================================================ журнал анализа отказов

    /**
     * Журнал анализа отказов: шапка с «Формализованный дефект» и «Код первопричины».
     * @return array{sys: int, cause: int, desc: int, alt: ?int, hint: ?int, start: int}|null
     */
    private function detectJournal(array $rows): ?array
    {
        foreach (array_slice($rows, 0, 5, true) as $i => $row) {
            $col = ['sys' => null, 'cause' => null, 'desc' => null, 'alt' => null, 'hint' => null];
            foreach ($row as $c => $cell) {
                $h = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $cell)));
                if ($h === '') continue;
                if (str_contains($h, 'формализ')) $col['desc'] ??= $c;
                elseif (str_contains($h, 'первопричин')) $col['cause'] ??= $c;
                elseif (preg_match('/^код( систем.*)?$/u', $h)) $col['sys'] ??= $c;
                elseif (str_starts_with($h, 'действи')) $col['hint'] ??= $c;
                elseif (str_contains($h, 'дефект при анализ')) $col['alt'] ??= $c;
            }
            if ($col['desc'] !== null && $col['cause'] !== null && $col['sys'] !== null) return $col + ['start' => $i + 1];
        }

        return null;
    }

    /**
     * Строка журнала → код справочника. Уровни: система (Р1) → первопричина (Р2).
     * Код собирается сам: SYS_CAM_N.HW-CONN-PIN.1, .2… Одинаковые формализованные дефекты
     * внутри системы и первопричины — один код. Разъяснение — «Действия предпринятые для анализа».
     */
    private function importJournal(array $rows, array $col, $sectionId)
    {
        $fc = app(FaultCodes::class);
        $added = $updated = $skipped = 0;
        $prevSys = $prevCause = '';

        DB::transaction(function () use ($rows, $col, $sectionId, $fc, &$added, &$updated, &$skipped, &$prevSys, &$prevCause) {
            $cache = [];   // [group_id => [нормализованное описание => FaultCode]]
            foreach (array_slice($rows, $col['start']) as $row) {
                $cell = fn ($k) => $col[$k] !== null ? trim(preg_replace('/\s+/u', ' ', (string) ($row[$col[$k]] ?? ''))) : '';
                $sys = mb_strtoupper($cell('sys'));
                $cause = mb_strtoupper($cell('cause'));
                // Объединённые ячейки: пусто — как в строке выше
                if ($sys === '') { $sys = $prevSys; if ($cause === '') $cause = $prevCause; }
                $prevSys = $sys;
                $prevCause = $cause;

                $desc = mb_substr($cell('desc') !== '' ? $cell('desc') : $cell('alt'), 0, 255);
                if ($sys === '' || $desc === '') { $skipped++; continue; }

                $group = $this->groupByName($sys, null, $sys);
                if ($cause !== '' && ! self::isPlaceholder($cause)) $group = $this->groupByName($cause, $group, "{$sys}.{$cause}");

                $key = self::descKey($desc);
                $cache[$group->id] ??= FaultCode::where('group_id', $group->id)->get()
                    ->keyBy(fn ($c) => self::descKey((string) $c->description))->all();

                $hint = $cell('hint');
                if ($code = $cache[$group->id][$key] ?? null) {
                    if (! $code->hint && $hint !== '') $code->update(['hint' => mb_substr($hint, 0, 1000)]);
                    $updated++;
                    continue;
                }
                $cache[$group->id][$key] = FaultCode::create([
                    'code' => $fc->nextCode($group), 'description' => $desc, 'hint' => $hint !== '' ? mb_substr($hint, 0, 1000) : null,
                    'group_id' => $group->id, 'section_id' => $sectionId, 'is_active' => true,
                ]);
                $added++;
            }
        });

        AuditLog::write('fault_codes', 'imported', "Загрузка журнала анализа отказов: новых кодов {$added}", ['section_id' => null]);
        if ($added + $updated === 0) {
            return back()->withErrors(['file' => 'В журнале не нашлось строк с «Код» и «Формализованный дефект».']);
        }

        $msg = "Журнал анализа отказов загружен: новых кодов {$added}, повторов склеено {$updated}" . ($skipped ? ", пропущено пустых строк {$skipped}" : '');
        $link = $fc->relinkArchive();
        if ($link['linked'] || $link['left']) {
            $msg .= ". Старые коды: привязано {$link['linked']}" . ($link['records'] ? " (в записях исправлено {$link['records']})" : '')
                . ($link['left'] ? ', без пары ' . count($link['left']) . ' — в записях остались как были' : '');
        }

        return back()->with('ok', $msg);
    }

    /** [сколько повторов в колонке кода, значения]. */
    private function codeRepeats(array $rows, array $col): array
    {
        $values = [];
        foreach (array_slice($rows, $col['start']) as $row) {
            $v = mb_strtolower(self::latinCode(trim((string) ($row[$col['code']] ?? ''))));
            if ($v !== '') $values[] = $v;
        }

        return [count($values) - count(array_unique($values)), $values];
    }

    /** Сколько повторов кода терпим (единичные опечатки) — больше значит колонка не та. */
    private function repeatLimit(array $rows, array $col): float
    {
        return max(3, count($this->codeRepeats($rows, $col)[1]) * 0.2);
    }

    /**
     * Русские буквы, похожие на латинские, в коде — латиницей: «С(1).1.1» → «C(1).1.1».
     * Только если в коде нет других русских букв (код «К3-001» не трогаем).
     */
    public static function latinCode(string $code): string
    {
        $map = ['А' => 'A', 'В' => 'B', 'С' => 'C', 'Е' => 'E', 'Н' => 'H', 'К' => 'K', 'М' => 'M', 'О' => 'O', 'Р' => 'P', 'Т' => 'T', 'Х' => 'X',
                'а' => 'a', 'с' => 'c', 'е' => 'e', 'о' => 'o', 'р' => 'p', 'х' => 'x'];
        if (preg_match('/\p{Cyrillic}{3,}/u', $code)) return $code;   // русское слово («Кросс-1») — не код с опечаткой
        $converted = strtr($code, $map);

        return preg_match('/\p{Cyrillic}/u', $converted) ? $code : $converted;
    }

    /** Описание для сравнения: регистр, ё, знаки и пробелы не важны. */
    private static function descKey(string $d): string
    {
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', str_replace('ё', 'е', mb_strtolower($d))));
    }

    /** «Нет», «-», «СМ Р3», «см. описание» — уровня нет. */
    private static function isPlaceholder(string $v): bool
    {
        return $v === '' || (bool) preg_match('/^(нет|-|—|no|none|см\.?(\s.*)?)$/iu', $v);
    }

    private function freePrefix(string $prefix): string
    {
        $p = $prefix;
        for ($i = 2; FaultCodeGroup::where('prefix', $p)->exists(); $i++) $p = $prefix . $i;

        return $p;
    }
}
