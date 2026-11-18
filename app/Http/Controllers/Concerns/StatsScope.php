<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Employee;
use App\Models\User;
use App\Services\SectionContext;
use Carbon\Carbon;
use Illuminate\Http\Request;

/** Статистика: вкладки участка, период, выбранный исполнитель (StatsController, StatsZoneController). */
trait StatsScope
{
    /** Вкладки учёта текущего участка. */
    private function sheets(): array
    {
        return app(SectionContext::class)->sheets();
    }

    /** Вкладка текущего участка (чужие вкладки статистики не отдаём). */
    private function sheetCfg(?string $sheet): ?array
    {
        return $this->sheets()[(string) $sheet] ?? null;
    }

    /** Часть ключа кеша: данные разных участков не смешиваются. */
    private function sectionKey(): string
    {
        return app(SectionContext::class)->cacheKey();
    }

    private function resolveTargetUser(Request $r): ?User
    {
        $user = auth()->user();
        if (!$user->isWorker()) return null;
        if ($r->input('mode') === 'all') return null;
        return $user;
    }

    private function resolveDateRange(Request $r): array
    {
        $from = $r->filled('from') ? Carbon::parse($r->input('from'))->startOfDay() : null;
        $to   = $r->filled('to')   ? Carbon::parse($r->input('to'))->endOfDay()     : null;
        if ($from && !$to) $to = now()->endOfDay();
        return [$from, $to];
    }

    private function periodLabel(?Carbon $from, ?Carbon $to): string
    {
        if (!$from && !$to) return 'За всё время';
        if ($from && $to && $from->isSameDay($to)) return 'За ' . $from->format('d.m.Y');
        if ($from && $to) return 'С ' . $from->format('d.m.Y') . ' по ' . $to->format('d.m.Y');
        if ($from) return 'С ' . $from->format('d.m.Y');
        return 'По ' . $to->format('d.m.Y');
    }

    /**
     * Диапазон по «сырому» полю даты — без DATE(column), чтобы использовался индекс.
     */
    private function applyDateRange($query, ?string $from, ?string $to, string $field = 'record_date'): void
    {
        if ($from) $query->where($field, '>=', Carbon::parse($from)->startOfDay());
        if ($to)   $query->where($field, '<', Carbon::parse($to)->addDay()->startOfDay());
    }

    /**
     * Тот же диапазон для сырого SQL: [фрагмент с ведущим AND, bindings].
     *
     * @return array{0: string, 1: array}
     */
    private function sqlDateRange(?string $from, ?string $to, string $column = 'record_date'): array
    {
        $sql = '';
        $bindings = [];
        if ($from) {
            $sql .= " AND `$column` >= ?";
            $bindings[] = Carbon::parse($from)->toDateString() . ' 00:00:00';
        }
        if ($to) {
            $sql .= " AND `$column` < ?";
            $bindings[] = Carbon::parse($to)->addDay()->toDateString() . ' 00:00:00';
        }
        return [$sql, $bindings];
    }

    /**
     * Список активных сотрудников (short_name).
     */
    private function activeWorkerNames(): array
    {
        return Employee::query()
            ->active()
            ->orderBy('short_name')
            ->pluck('short_name')
            ->all();
    }

    /**
     * Проверка: имя — активный сотрудник из справочника.
     */
    private function isActiveWorker(?string $name): bool
    {
        $name = trim((string) $name);
        if ($name === '') return false;
        return Employee::query()->active()->where('short_name', $name)->exists();
    }
}
