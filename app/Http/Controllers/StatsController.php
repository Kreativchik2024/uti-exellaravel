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
    /**
     * Допустимые периоды: 0 = всё время, 1, 7, 30 дней.
     */
    private const ALLOWED_PERIODS = [0, 1, 7, 30];

    /**
     * Вычислить дату начала периода.
     *
     * @param  int  $period  0 = всё время, иначе количество дней
     * @return Carbon|null   null = без фильтра по дате (всё время)
     */
    private function resolveFrom(int $period): ?Carbon
    {
        if ($period === 0) {
            return null;
        }

        return now()->subDays($period - 1)->startOfDay();
    }

    /**
     * Определить, чью статистику показывать.
     *
     * @return User|null  null = общая статистика (без фильтра по пользователю)
     */
    private function resolveTargetUser(Request $r): ?User
    {
        $user = auth()->user();

        if ($user->isAdmin() || $user->isMaster()) {
            if ($r->filled('user_id')) {
                return User::find($r->user_id) ?? $user;
            }

            return null; // общая статистика
        }

        // Исполнитель
        if ($r->input('mode') === 'all') {
            return null; // общая статистика
        }

        return $user; // только своя
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

        return view('stats.index', compact(
            'period',
            'from',
            'perSheet',
            'totals',
            'byPosts',
            'usersList',
            'targetUser'
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