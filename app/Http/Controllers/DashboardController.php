<?php

namespace App\Http\Controllers;

use App\Models\News;
use App\Services\SectionContext;
use App\Services\StatsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    public function index(Request $request, StatsService $s, SectionContext $sections)
    {
        $section = $sections->current();

        // Новости текущего участка + общие
        $news = News::with(['author','images'])
            ->forSection($section)
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

        // Сводка по 19 таблицам считается дорого — кешируем на минуту
        [$perSheet, $totals] = Cache::remember(
            'dash.stats.' . $sections->cacheKey() . '.' . $statsPeriod . '.' . now()->toDateString(),
            60,
            function () use ($s, $from) {
                $perSheet = $s->forPeriod($from, null);
                return [$perSheet, $s->totals($perSheet)];
            }
        );

        return view('dashboard', compact('news', 'perSheet', 'totals', 'statsPeriod', 'section'));
    }
}
