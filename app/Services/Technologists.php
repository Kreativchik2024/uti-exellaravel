<?php

namespace App\Services;

use App\Models\Employee;

/**
 * Представители ОТК для поля ПГП «Представитель ОТК» (колонка technologist) — сотрудники с ролью «Представитель ОТК»
 * (роль задаётся в карточке сотрудника, учётная запись не нужна: прав на сайте у них нет). Имя — короткое ФИО.
 * names() — активные (для выпадающего списка), allNames() — все, в т.ч. отключённые и прежние технологи
 * (до разделения ролей в это поле ставили технологов) — чтобы старые записи можно было пересохранить.
 */
class Technologists
{
    public function names(): array
    {
        return $this->list(['otk'], true);
    }

    public function allNames(): array
    {
        return $this->list(['otk', 'technologist'], false);
    }

    private function list(array $roles, bool $activeOnly): array
    {
        return Employee::query()->whereIn('role', $roles)
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->pluck('short_name')
            ->map(fn ($n) => trim((string) $n))
            ->filter()->unique()->sort()->values()->all();
    }
}
