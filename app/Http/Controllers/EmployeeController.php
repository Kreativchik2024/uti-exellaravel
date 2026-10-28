<?php

namespace App\Http\Controllers;

use App\Exports\EmployeesExport;
use App\Exports\EmployeesTemplateExport;
use App\Imports\EmployeesImport;
use App\Models\Employee;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class EmployeeController extends Controller
{
    public function index(Request $r)
    {
        $q = trim((string) $r->input('q', ''));

        $query = Employee::query()->orderBy('last_name')->orderBy('first_name');

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('last_name', 'like', "%{$q}%")
                  ->orWhere('first_name', 'like', "%{$q}%")
                  ->orWhere('middle_name', 'like', "%{$q}%")
                  ->orWhere('short_name', 'like', "%{$q}%")
                  ->orWhere('position', 'like', "%{$q}%")
                  ->orWhere('department', 'like', "%{$q}%");
            });
        }

        $employees = $query->paginate(50)->withQueryString();

        return view('employees.index', compact('employees', 'q'));
    }

    public function create()
    {
        return view('employees.create');
    }

    public function store(Request $r)
    {
        $data = $this->validateData($r);
        $data['short_name'] = Employee::buildShortName(
            $data['last_name'],
            $data['first_name'],
            $data['middle_name'] ?? null
        );

        Employee::create($data);

        return redirect()->route('employees.index')->with('ok', 'Сотрудник добавлен');
    }

    public function edit(Employee $employee)
    {
        return view('employees.edit', compact('employee'));
    }

    public function update(Request $r, Employee $employee)
    {
        $data = $this->validateData($r);
        $data['short_name'] = Employee::buildShortName(
            $data['last_name'],
            $data['first_name'],
            $data['middle_name'] ?? null
        );

        $employee->update($data);

        return redirect()->route('employees.index')->with('ok', 'Сотрудник обновлён');
    }

    public function destroy(Employee $employee)
    {
        $employee->delete();
        return back()->with('ok', 'Сотрудник удалён');
    }

    // =========================================================
    // Excel: экспорт, шаблон, импорт
    // =========================================================

    public function export()
    {
        $filename = 'employees-' . now()->format('Ymd-His') . '.xlsx';

        return Excel::download(new EmployeesExport, $filename);
    }

    public function template()
    {
        return Excel::download(new EmployeesTemplateExport, 'employees-template.xlsx');
    }

    public function importForm()
    {
        return view('employees.import');
    }

    public function import(Request $r)
    {
        $r->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);

        $import = new EmployeesImport;

        try {
            Excel::import($import, $r->file('file'));
        } catch (\Throwable $e) {
            return back()->with('error', 'Не удалось прочитать файл: ' . $e->getMessage());
        }

        $msg = "Импорт завершён: создано {$import->created}, обновлено {$import->updated}.";

        $redirect = redirect()->route('employees.index')->with('ok', $msg);

        if (!empty($import->errors)) {
            $redirect = $redirect->with('import_errors', $import->errors);
        }

        return $redirect;
    }

    private function validateData(Request $r): array
    {
        return $r->validate([
            'last_name'   => ['required', 'string', 'max:120'],
            'first_name'  => ['required', 'string', 'max:120'],
            'middle_name' => ['nullable', 'string', 'max:120'],
            'position'    => ['nullable', 'string', 'max:120'],
            'department'  => ['nullable', 'string', 'max:120'],
            'is_active'   => ['boolean'],
        ]);
    }
}