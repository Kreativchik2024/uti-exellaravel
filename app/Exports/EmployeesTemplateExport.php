<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class EmployeesTemplateExport implements FromArray, WithHeadings, ShouldAutoSize
{
    public function array(): array
    {
        return [
            ['Иванов', 'Иван', 'Иванович', 'Инженер', 'Участок 1', 'Да'],
        ];
    }

    public function headings(): array
    {
        return [
            'Фамилия',
            'Имя',
            'Отчество',
            'Должность',
            'Участок',
            'Активен',
        ];
    }
}