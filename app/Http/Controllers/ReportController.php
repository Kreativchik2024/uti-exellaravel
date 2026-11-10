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
        ]);
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
