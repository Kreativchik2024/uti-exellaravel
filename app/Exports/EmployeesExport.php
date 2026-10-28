<?php

namespace App\Exports;

use App\Models\Employee;
use Illuminate\Support\Enumerable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class EmployeesExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize
{
    public function collection(): Enumerable
    {
        return Employee::orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }

    public function headings(): array
    {
        return [
            'Фамилия',
            'Имя',
            'Отчество',
            'Кратко',
            'Должность',
            'Участок',
            'Активен',
        ];
    }

    public function map($e): array
    {
        return [
            $e->last_name,
            $e->first_name,
            $e->middle_name,
            $e->short_name,
            $e->position,
            $e->department,
            $e->is_active ? 'Да' : 'Нет',
        ];
    }
}