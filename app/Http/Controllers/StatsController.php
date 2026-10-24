<?php

namespace App\Http\Controllers;

use App\Exports\StatsExport;
use App\Models\User;
use App\Services\StatsService;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class StatsController extends Controller
{
    public function index(Request $r, StatsService $s)
    {
        $period = (int) $r->input('period', 7);
        if (! in_array($period, [1, 7, 30])) $period = 7;

        $from = now()->subDays($period - 1)->startOfDay();

        // Определяем, чью статистику показывать
        $targetUser = auth()->user();
        $usersList = collect();

        if (auth()->user()->isAdmin() || auth()->user()->isMaster()) {
            // Админ/мастер могут смотреть всех
            $usersList = User::whereIn('role', ['executor', 'master', 'admin'])->orderBy('name')->get();
            
            if ($r->filled('user_id')) {
                $targetUser = User::find($r->user_id) ?? auth()->user();
            } else {
                // Показываем общую статистику (передаем null, чтобы сервис не фильтровал)
                $targetUser = null; 
            }
        } else {
            // Исполнитель
            if ($r->input('mode') === 'all') {
                $targetUser = null; // Общая статистика
            } else {
                $targetUser = auth()->user(); // Только своя
            }
        }

        $perSheet = $s->forPeriod($from, $targetUser);
        $totals   = $s->totals($perSheet);
        $byPosts  = $s->byPosts($from, $targetUser);

        return view('stats.index', compact('period', 'from', 'perSheet', 'totals', 'byPosts', 'usersList', 'targetUser'));
    }

    public function export(Request $r, StatsService $s)
    {
        $period = (int) $r->input('period', 7);
        if (! in_array($period, [1, 7, 30])) $period = 7;

        $from = now()->subDays($period - 1)->startOfDay();

        $targetUser = auth()->user();

        if (auth()->user()->isAdmin() || auth()->user()->isMaster()) {
            if ($r->filled('user_id')) {
                $targetUser = User::find($r->user_id) ?? auth()->user();
            } else {
                $targetUser = null;
            }
        } else {
            if ($r->input('mode') === 'all') {
                $targetUser = null;
            }
        }

        $perSheet = $s->forPeriod($from, $targetUser);
        $totals   = $s->totals($perSheet);

        $filename = "uti-stats-{$period}d-" . now()->format('Ymd-His') . ".xlsx";

        return Excel::download(new StatsExport($period, $from, $perSheet, $totals), $filename);
    }
}