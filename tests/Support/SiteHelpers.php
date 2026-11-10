<?php

namespace Tests\Support;

use App\Models\Employee;
use App\Models\Section;
use App\Models\User;

/**
 * Общие помощники для тестов сайта.
 */
trait SiteHelpers
{
    private int $employeeSeq = 0;

    /**
     * Пользователь с карточкой сотрудника (нужна, чтобы выбирать его исполнителем),
     * ПИН 1234 и доступом к участку испытаний.
     */
    protected function worker(string $role = 'executor', ?string $shortName = null): User
    {
        $user = User::factory()->state(['role' => $role])->create();
        $user->setPin('1234');

        $n = ++$this->employeeSeq;
        Employee::create([
            'user_id' => $user->id, 'last_name' => "Тестов{$n}", 'first_name' => 'Иван', 'middle_name' => 'Иванович',
            'short_name' => $shortName ?? "Тестов{$n} И.И.", 'is_active' => true,
        ]);

        $user->sections()->syncWithoutDetaching([Section::where('slug', Section::DEFAULT_SLUG)->value('id')]);

        return $user->fresh();
    }

    /**
     * Запрос от имени пользователя с выбранным рабочим местом.
     * Исполнителю разрешён один вход на устройство, а в тестах у каждого
     * запроса новая сессия — поэтому перед запросом сбрасываем отметку сессии.
     */
    protected function asUser(User $user): static
    {
        $user->forceFill(['session_id' => null])->saveQuietly();

        return $this->actingAs($user)->withSession(['workstation_workers' => []]);
    }

    /** Короткое ФИО сотрудника пользователя. */
    protected function nameOf(User $user): string
    {
        return $user->employee()->value('short_name');
    }

    /**
     * Правильно заполненная запись для любой вкладки — строится из её конфига.
     * Исполнитель 1 — переданный сотрудник, остальные исполнители пустые.
     */
    protected function validRecord(string $sheet, string $worker1): array
    {
        $data = [];
        foreach (config("sheets.map.$sheet.fields") as $name => $f) {
            if (preg_match('/^worker[2-5]$/', $name)) continue;

            $data[$name] = match (true) {
                $name === 'worker1'                => $worker1,
                $name === 'post_number'            => 3,
                $name === 'peripheral_number'      => 2,
                ($f['type'] ?? '') === 'date'      => now()->toDateString(),
                ($f['type'] ?? '') === 'time'      => '08:30',
                ($f['type'] ?? '') === 'number'    => 1,
                ($f['type'] ?? '') === 'select'    => $f['options'][0],
                ($f['type'] ?? '') === 'ip_suffix' => '15',
                default                            => 'Тест ' . $name,
            };
        }
        return $data;
    }

    /** Модель записей вкладки. */
    protected function modelOf(string $sheet): string
    {
        return config("sheets.map.$sheet.model");
    }
}
