<?php

namespace App\Services;

use App\Models\Employee;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;

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

    /**
     * Проверка ПИН сотрудника (форма записи на сайте и программы на станциях).
     * После 5 ошибок подряд — пауза 15 минут, с какой бы учётки и с какого компьютера ни вводили.
     *
     * @return array{0: ?Employee, 1: ?string, 2: int} [сотрудник, текст ошибки, HTTP-код]
     */
    public function verifyPin(string $shortName, string $pin): array
    {
        $shortName = trim($shortName);
        $pin = trim($pin);

        if ($shortName === '' || $pin === '')   return [null, 'Укажите сотрудника и ПИН.', 422];
        if (!preg_match('/^\d{4,6}$/', $pin))    return [null, 'ПИН — 4–6 цифр.', 422];

        $employee = Employee::query()->where('short_name', $shortName)->active()->with('user')->first();

        if (!$employee)                 return [null, 'Сотрудник не найден.', 404];
        if (!$employee->user)           return [null, 'Сотрудник не привязан к учётной записи.', 422];
        if (!$employee->user->hasPin()) return [null, 'У сотрудника не установлен ПИН.', 422];

        $lockKey = 'pin-employee:' . $employee->id;
        if (RateLimiter::tooManyAttempts($lockKey, 5)) {
            $min = (int) ceil(RateLimiter::availableIn($lockKey) / 60);
            return [null, "Слишком много неверных попыток ПИН этого сотрудника. Подождите {$min} мин.", 429];
        }
        if (!$employee->user->checkPin($pin)) {
            RateLimiter::hit($lockKey, 900);
            return [null, 'Неверный ПИН.', 422];
        }
        RateLimiter::clear($lockKey);

        return [$employee, null, 200];
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
