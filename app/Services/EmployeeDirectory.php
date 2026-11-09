<?php

namespace App\Services;

use App\Models\Employee;
use Illuminate\Support\Collection;

/**
 * Справочник сотрудников для форм записей и выбора рабочего места.
 * Регистрируется как scoped-синглтон: данные загружаются один раз за запрос.
 */
class EmployeeDirectory
{
    private ?Collection $active = null;
    private ?array $verifiedMap = null;

    /** Активные сотрудники с подгруженным user (один запрос на весь HTTP-запрос). */
    public function active(): Collection
    {
        return $this->active ??= Employee::query()->active()->with('user')->orderBy('short_name')->get();
    }

    /** Сотрудник по короткому имени среди активных. */
    public function findActiveByName(string $shortName): ?Employee
    {
        return $this->active()->first(fn (Employee $e) => trim((string) $e->short_name) === $shortName);
    }

    /** Активные сотрудники, привязанные к учётке с email — для выпадающих списков. */
    public function namesForSelect(): array
    {
        return $this->active()
            ->filter(fn (Employee $e) => $e->user && trim((string) $e->user->email) !== '')
            ->map(fn (Employee $e) => trim((string) $e->short_name))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @return array{0: string[], 1: string[]} [с ПИН, без ПИН] */
    public function splitByPin(): array
    {
        [$with, $without] = $this->active()->partition(fn (Employee $e) => $e->user && $e->user->hasPin());

        $names = fn (Collection $c) => $c->map(fn ($e) => trim((string) $e->short_name))
            ->filter()->unique()->values()->all();

        return [$names($with), $names($without)];
    }

    /** Короткое имя сотрудника, привязанного к текущему пользователю. */
    public function currentEmployeeName(): ?string
    {
        $name = trim((string) auth()->user()?->employee?->short_name);

        return $name !== '' ? $name : null;
    }

    /** Карта «имя => срок действия» для ПИН, подтверждённых в текущей сессии. */
    public function verifiedMap(): array
    {
        if ($this->verifiedMap !== null) {
            return $this->verifiedMap;
        }

        $now = now()->timestamp;
        $ids = [];
        foreach ((array) session('verified_workers', []) as $id => $expires) {
            if ((int) $expires > $now) {
                $ids[(int) $id] = (int) $expires;
            }
        }

        $map = [];
        if ($ids) {
            foreach (Employee::whereIn('id', array_keys($ids))->get(['id', 'short_name']) as $e) {
                $map[trim((string) $e->short_name)] = $ids[$e->id];
            }
        }

        return $this->verifiedMap = $map;
    }
}
