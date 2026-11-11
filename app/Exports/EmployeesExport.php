<?php

namespace App\Exports;

use App\Exports\Support\TableSheet;
use App\Exports\Support\Workbook;
use App\Models\Employee;

/**
 * Сотрудники. Первый лист — шапка в первой строке, тот же формат, что принимает загрузка:
 * файл можно поправить и загрузить обратно.
 */
class EmployeesExport extends Workbook
{
    public const COLUMNS = ['Фамилия', 'Имя', 'Отчество', 'Кратко', 'Должность', 'Участок', 'Активен', 'Учётная запись'];

    public function __construct(bool $template = false)
    {
        $rows = $template
            ? [['Иванов', 'Иван', 'Иванович', 'Иванов И.И.', 'Инженер', 'Цех 1', 'Да', '']]
            : Employee::query()->with('user')->orderBy('last_name')->orderBy('first_name')->get()
                ->map(fn ($e) => [$e->last_name, $e->first_name, $e->middle_name, $e->short_name, $e->position, $e->department,
                    $e->is_active ? 'Да' : 'Нет', $e->user?->email])->all();

        $columns = array_map(fn ($t) => ['title' => $t], self::COLUMNS);
        $columns[3]['width'] = 18;

        $sheets = [TableSheet::make('Сотрудники', null, [], [['columns' => $columns, 'rows' => $rows, 'empty' => 'Сотрудников нет']])];
        if ($template) {
            $sheets[] = TableSheet::make('Как заполнять', 'Загрузка сотрудников', [], [[
                'columns' => [['title' => 'Колонка'], ['title' => 'Что писать', 'width' => 70, 'wrap' => true]],
                'rows' => [
                    ['Фамилия, Имя', 'Обязательно.'],
                    ['Отчество', 'Можно не заполнять.'],
                    ['Кратко', 'Можно не заполнять — соберётся из ФИО: «Иванов И.И.». По нему сотрудник ищется при повторной загрузке.'],
                    ['Должность, Участок', 'Как в справочнике сотрудников.'],
                    ['Активен', '«Да» — участвует в работе, «Нет» — не выпадает в списках.'],
                    ['Учётная запись', 'Только для справки, при загрузке не меняется.'],
                ],
            ]]);
        }
        parent::__construct($sheets);
    }
}
