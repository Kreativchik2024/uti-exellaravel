<?php

namespace App\Http\Controllers;

use App\Models\News;
use App\Services\StatsService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request, StatsService $s)
    {
        $news = News::with(['author','images'])
            ->published()
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->paginate(5);

        // Если это AJAX-запрос для подгрузки новостей (бесконечная лента) — отдаем только JSON
        if ($request->ajax() || $request->boolean('json')) {
            return response()->json([
                'html'     => view('news._items', compact('news'))->render(),
                'has_more' => $news->hasMorePages(),
            ]);
        }

        // Период для статистики на главной (по умолчанию 30 дней)
        $statsPeriod = (int) $request->input('stats_period', 30);
        if (! in_array($statsPeriod, [1, 7, 30])) $statsPeriod = 30;

        $from = now()->subDays($statsPeriod - 1)->startOfDay();
        
        // Собираем общую статистику (targetUser = null, чтобы показывать по всем)
        $perSheet = $s->forPeriod($from, null); 
        $totals   = $s->totals($perSheet);

        return view('dashboard', compact('news', 'perSheet', 'totals', 'statsPeriod'));
    }
}