<?php

namespace App\Services;

use App\Models\User;

/**
 * Технологи — пользователи группы ОТК. Имя — короткое ФИО привязанного сотрудника, иначе имя учётной записи.
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
        return User::query()->where('role', 'otk')
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->with('employee')->get()
            ->map(fn (User $u) => trim((string) ($u->employee?->short_name ?: $u->name)))
            ->filter()->unique()->sort()->values()->all();
    }
}
