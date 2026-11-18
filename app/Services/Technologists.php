<?php

namespace App\Services;

use App\Models\Employee;

/**
 * Технологи — сотрудники с ролью ОТК (роль задаётся в карточке сотрудника, учётная запись не обязательна).
 * Имя — короткое ФИО сотрудника.
 * names() — активные (для выпадающего списка), allNames() — все, в т.ч. отключённые (чтобы старые записи
 * с ушедшим технологом можно было сохранить без смены технолога).
 */
class Technologists
{
    public function names(): array
    {
        return $this->list(true);
    }

    public function allNames(): array
    {
        return $this->list(false);
    }

    private function list(bool $activeOnly): array
    {
        return Employee::query()->where('role', 'otk')
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->pluck('short_name')
            ->map(fn ($n) => trim((string) $n))
            ->filter()->unique()->sort()->values()->all();
    }
}
