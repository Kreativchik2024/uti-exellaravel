<?php

namespace App\Http\Controllers;

use App\Exports\StatsExport;
use App\Models\User;
use App\Services\StatsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class StatsController extends Controller
{
    private const ALLOWED_PERIODS = [0, 1, 7, 30];

    private function resolveFrom(int $period): ?Carbon
    {
        if ($period === 0) {
            return null;
        }

        return now()->subDays($period - 1)->startOfDay();
    }

    private function resolveTargetUser(Request $r): ?User
    {
        $user = auth()->user();

        if ($user->isAdmin() || $user->isMaster()) {
            if ($r->filled('user_id')) {
                return User::find($r->user_id) ?? $user;
            }

            return null;
        }

        if ($r->input('mode') === 'all') {
            return null;
        }

        return $user;
    }

    public function index(Request $r, StatsService $s)
    {
        $period = (int) $r->input('period', 7);
        if (! in_array($period, self::ALLOWED_PERIODS, true)) {
            $period = 7;
        }

        $from = $this->resolveFrom($period);

        $targetUser = $this->resolveTargetUser($r);
        $usersList  = collect();

        if (auth()->user()->isAdmin() || auth()->user()->isMaster()) {
            $usersList = User::whereIn('role', ['executor', 'master', 'admin'])
                ->orderBy('name')
                ->get();
        }

        $perSheet = $s->forPeriod($from, $targetUser);
        $totals   = $s->totals($perSheet);
        $byPosts  = $s->byPosts($from, $targetUser);

        // === Сводка по исполнителю ===
        $worker         = trim((string) $r->input('worker', ''));
        $workerDateFrom = $r->input('worker_date_from');
        $workerDateTo   = $r->input('worker_date_to');

        $workersList   = $s->listWorkers();
        $workerSummary = [];

        if ($worker !== '') {
            if ($workerDateFrom || $workerDateTo) {
                if ($workerDateFrom && !$workerDateTo) {
                    // Одна дата — ровно этот день
                    $wFrom = Carbon::parse($workerDateFrom)->startOfDay();
                    $wTo   = Carbon::parse($workerDateFrom)->endOfDay();
                } else {
                    $wFrom = $workerDateFrom ? Carbon::parse($workerDateFrom)->startOfDay() : null;
                    $wTo   = $workerDateTo   ? Carbon::parse($workerDateTo)->endOfDay()     : null;
                }
            } else {
                // Даты не указаны — берём общий период
                $wFrom = $from;
                $wTo   = null;
            }

            $workerSummary = $s->workerSummary($worker, $wFrom, $wTo);
        }

        return view('stats.index', compact(
            'period',
            'from',
            'perSheet',
            'totals',
            'byPosts',
            'usersList',
            'targetUser',
            'worker',
            'workersList',
            'workerSummary'
        ));
    }

    public function export(Request $r, StatsService $s)
    {
        $period = (int) $r->input('period', 7);
        if (! in_array($period, self::ALLOWED_PERIODS, true)) {
            $period = 7;
        }

        $from = $this->resolveFrom($period);

        $targetUser = $this->resolveTargetUser($r);

        $perSheet = $s->forPeriod($from, $targetUser);
        $totals   = $s->totals($perSheet);

        $periodLabel = $period === 0 ? 'all' : "{$period}d";
        $filename    = "uti-stats-{$periodLabel}-" . now()->format('Ymd-His') . ".xlsx";

        return Excel::download(new StatsExport($period, $from, $perSheet, $totals), $filename);
    }
}