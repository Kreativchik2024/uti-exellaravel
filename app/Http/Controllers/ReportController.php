<?php

namespace App\Http\Controllers;

use App\Exports\ShiftReportExport;
use App\Services\SectionContext;
use App\Services\ShiftReport;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/** Отчёт по смене для мастера и админа. */
class ReportController extends Controller
{
    public function shift(Request $r, ShiftReport $report, SectionContext $sections)
    {
        [$date, $shift] = $this->params($r);
        [$from, $to] = ShiftReport::bounds($date, $shift);
        $data = $report->build($from, $to);

        if ($r->boolean('excel')) {
            $name = 'Смена_' . $date->format('Y-m-d') . '_' . config("shifts.list.$shift.name") . '.xlsx';
            return Excel::download(new ShiftReportExport($data, $from, $to, $sections->current()?->name ?? ''), $name);
        }

        return view('reports.shift', [
            'data'   => $data,
            'date'   => $date,
            'shift'  => $shift,
            'from'   => $from,
            'to'     => $to,
            'shifts' => config('shifts.list'),
            'prev'   => $this->neighbour($date, $shift, -1),
            'next'   => $this->neighbour($date, $shift, 1),
        ]);
    }

    /**
     * Записи смены, из которых сложены числа отчёта: по вкладке и/или исполнителю, можно только брак.
     * Набор записей берётся из того же ShiftReport::build(), поэтому список всегда совпадает с цифрой в отчёте.
     */
    public function shiftRecords(Request $r, ShiftReport $report, SectionContext $sections)
    {
        [$date, $shift] = $this->params($r);
        $r->validate(['sheet' => ['nullable', 'string', 'max:60'], 'worker' => ['nullable', 'string', 'max:200']]);
        [$from, $to] = ShiftReport::bounds($date, $shift);
        $data = $report->build($from, $to);

        $sheet = $r->input('sheet');
        $worker = $r->input('worker');
        $onlyDefect = $r->boolean('defect');
        $key = $onlyDefect ? 'defect_ids' : 'ids';

        // какие ids показывать по каждой вкладке
        $source = $worker !== null && $worker !== ''
            ? $data['workers'][$worker]['sheets'] ?? []
            : $data['sheets'];
        if ($sheet) $source = array_intersect_key($source, [$sheet => true]);

        $cfgs = $sections->sheets();
        $groups = [];
        foreach ($source as $slug => $s) {
            $cfg = $cfgs[$slug] ?? null;
            $ids = $s[$key] ?? [];
            if (! $cfg || empty($cfg['model']) || ! $ids) continue;

            $columns = self::listColumns($cfg);
            $records = $cfg['model']::query()->with('creator')->whereIn('id', $ids)->orderBy('created_at')->get();
            $groups[$slug] = ['title' => $data['sheets'][$slug]['title'] ?? $cfg['title'], 'cfg' => $cfg,
                              'columns' => $columns, 'records' => $records,
                              'defectIds' => array_fill_keys($s['defect_ids'] ?? [], true)];
        }

        return view('reports.shift-records', [
            'groups' => $groups,
            'date'   => $date,
            'shift'  => $shift,
            'from'   => $from,
            'to'     => $to,
            'sheet'  => $sheet,
            'worker' => $worker,
            'onlyDefect' => $onlyDefect,
            'sheetTitle' => $sheet ? ($data['sheets'][$sheet]['title'] ?? $cfgs[$sheet]['title'] ?? $sheet) : null,
        ]);
    }

    /** Колонки вкладки для списков из отчётов (исполнители, автор и код брака выводятся отдельно). */
    public static function listColumns(array $cfg): array
    {
        return array_values(array_filter($cfg['list_columns'] ?? [],
            fn ($c) => ! in_array($c, ['health_indicator', 'created_by', 'fault_code'], true) && ! str_starts_with($c, 'worker')));
    }

    /** Соседняя смена (dir = -1 / 1): после последней смены дня — первая смена следующего дня и наоборот. */
    private function neighbour(Carbon $date, int $shift, int $dir): array
    {
        $keys = array_keys(config('shifts.list'));
        $i = array_search($shift, $keys, true) + $dir;
        $day = $date->copy();
        if ($i < 0) { $i = count($keys) - 1; $day->subDay(); }
        if ($i >= count($keys)) { $i = 0; $day->addDay(); }

        return ['date' => $day->format('Y-m-d'), 'shift' => $keys[$i]];
    }

    public function defects(Request $r, \App\Services\DefectAnalysis $analysis, SectionContext $sections)
    {
        $r->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'sheet' => ['nullable', 'string', 'max:60']]);
        $to = $r->filled('to') ? Carbon::parse($r->input('to')) : now();
        $from = $r->filled('from') ? Carbon::parse($r->input('from')) : $to->copy()->subDays(29);
        if ($from->gt($to)) [$from, $to] = [$to, $from];
        $sheet = $r->input('sheet');
        if ($sheet && ! isset($sections->sheets()[$sheet])) $sheet = null;

        return view('reports.defects', [
            'data'   => $analysis->build($from, $to, $sheet),
            'from'   => $from,
            'to'     => $to,
            'sheet'  => $sheet,
            'sheets' => $sections->sheets(),
        ]);
    }

    /** Дата и смена из запроса; по умолчанию — текущая (ночная после полуночи — со вчерашней датой). */
    private function params(Request $r): array
    {
        $r->validate(['date' => ['nullable', 'date'], 'shift' => ['nullable', 'integer']]);
        $shifts = config('shifts.list');

        if ($r->filled('date') && isset($shifts[(int) $r->input('shift')])) {
            return [Carbon::parse($r->input('date'))->startOfDay(), (int) $r->input('shift')];
        }
        if ($r->filled('date')) {
            return [Carbon::parse($r->input('date'))->startOfDay(), (int) array_key_first($shifts)];
        }

        foreach ([now()->startOfDay(), now()->subDay()->startOfDay()] as $day) {
            foreach (array_keys($shifts) as $n) {
                [$from, $to] = ShiftReport::bounds($day, $n);
                if (now()->between($from, $to)) return [$day, $n];
            }
        }

        return [now()->startOfDay(), (int) array_key_first($shifts)];
    }
}
