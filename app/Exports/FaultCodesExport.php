<?php

namespace App\Exports;

use App\Exports\Support\TableSheet;
use App\Exports\Support\Workbook;
use App\Models\FaultCode;
use App\Models\FaultCodeGroup;

/**
 * Справочник кодов неисправностей. Первый лист — в формате таблицы предприятия
 * (НПП, Р1, Р2, Р3, Описание, общий вид, Разъяснение): его можно поправить в Excel и загрузить обратно.
 * Шапка — в первой строке, как ждёт загрузка. Нет уровня — «СМ Р3» / «СМ ОПИСАНИЕ», как в исходной таблице.
 */
class FaultCodesExport extends Workbook
{
    public function __construct()
    {
        $tree = FaultCodeGroup::tree(true);
        $sheetTitles = collect(config('sheets.map'))->map(fn ($c) => $c['title']);
        $titles = fn (array $slugs) => collect($slugs)->map(fn ($s) => $sheetTitles[$s] ?? $s)->implode(', ');

        $codes = FaultCode::all();
        FaultCodeGroup::attachTo($codes, $tree);
        $codes = $codes->sort(fn ($a, $b) => strcmp($a->group?->sortKey() ?? '~', $b->group?->sortKey() ?? '~') ?: strnatcasecmp($a->code, $b->code))->values();

        $levels = max(3, (int) $tree->max(fn ($g) => $g->depth()));

        $rows = [];
        foreach ($codes as $i => $c) {
            $path = $c->group?->path() ?? [];
            $cells = [];
            for ($k = 0; $k < $levels; $k++) {
                $cells[] = isset($path[$k]) ? $path[$k]->numberedName()
                    : ($k === $levels - 1 ? 'СМ ОПИСАНИЕ' : 'СМ Р' . ($k + 2));
            }
            $rows[] = array_merge([$i + 1], $cells, [
                $c->description,
                $c->code,
                $c->hint,
                $c->group ? $titles($c->group->effectiveSheets()) : 'все',
                ! $c->is_active ? 'Отключён' : ($c->needs_review ? 'Новый, не проверен' : 'Действует'),
            ]);
        }

        $columns = [['title' => 'НПП', 'type' => 'int', 'width' => 7]];
        for ($k = 1; $k <= $levels; $k++) $columns[] = ['title' => 'Р' . $k, 'width' => 24];
        $columns = array_merge($columns, [
            ['title' => 'Описание', 'width' => 50, 'wrap' => true],
            ['title' => 'общий вид', 'width' => 13],
            ['title' => 'Разъяснение', 'width' => 50, 'wrap' => true],
            ['title' => 'Вкладки', 'width' => 30, 'wrap' => true],
            ['title' => 'Статус', 'width' => 18],
        ]);

        $groupRows = [];
        foreach ($tree->sortBy(fn ($g) => $g->sortKey()) as $g) {
            $groupRows[] = ['Р' . $g->depth(), str_repeat('    ', $g->depth() - 1) . $g->numberedName(), $g->label(), $g->prefix, $g->codes_count,
                $titles((array) $g->sheets) ?: '—', $titles($g->effectiveSheets()) ?: 'не выпадают'];
        }

        parent::__construct([
            TableSheet::make('Коды', null, [], [['columns' => $columns, 'rows' => $rows, 'empty' => 'Справочник пуст']]),
            TableSheet::make('Уровни', 'Уровни справочника', [['Кодов всего', (string) count($rows)], Workbook::stamp()], [[
                'columns' => [
                    ['title' => 'Уровень'], ['title' => 'Название', 'width' => 32], ['title' => 'Полный путь', 'width' => 50, 'wrap' => true],
                    ['title' => 'Основа кода'], ['title' => 'Кодов', 'type' => 'int'],
                    ['title' => 'Свои вкладки', 'width' => 30, 'wrap' => true], ['title' => 'Выпадают во вкладках', 'width' => 40, 'wrap' => true],
                ],
                'rows'  => $groupRows,
                'empty' => 'Уровней нет',
            ]]),
        ]);
    }
}
