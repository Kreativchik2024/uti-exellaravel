<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    private const IMPORT_HEADER_ALIASES = [
        'last_name'   => ['last_name', 'фамилия'],
        'first_name'  => ['first_name', 'имя'],
        'middle_name' => ['middle_name', 'отчество'],
        'position'    => ['position', 'должность'],
        'department'  => ['department', 'участок', 'участок / отдел', 'отдел'],
        'is_active'   => ['is_active', 'активен', 'активен (участвует в работе)'],
        'short_name'  => ['short_name', 'кратко', 'фио', 'сокращённо', 'сокращенно'],
    ];

    private function buildShortName(string $lastName, string $firstName, string $middleName = ''): string
    {
        $lastName   = trim($lastName);
        $firstName  = trim($firstName);
        $middleName = trim($middleName);

        $initials = '';
        if ($firstName !== '')  $initials .= mb_strtoupper(mb_substr($firstName, 0, 1)) . '.';
        if ($middleName !== '') $initials .= mb_strtoupper(mb_substr($middleName, 0, 1)) . '.';

        return trim($lastName . ' ' . $initials);
    }

    private function parseShortName(string $shortName): array
    {
        $shortName = trim(preg_replace('/\s+/u', ' ', $shortName));
        $parts = preg_split('/\s+/u', $shortName, 3);

        return [
            'last_name'   => $parts[0] ?? '',
            'first_name'  => $parts[1] ?? '',
            'middle_name' => $parts[2] ?? '',
        ];
    }

    private function usersAvailableFor(?Employee $current = null)
    {
        return User::query()
            ->orderBy('name')
            ->whereNotIn('id', function ($q) use ($current) {
                $q->select('user_id')->from('employees')->whereNotNull('user_id');
                if ($current && $current->exists) {
                    $q->where('id', '!=', $current->id);
                }
            })
            ->get(['id', 'name', 'email']);
    }

    public function index(Request $r)
    {
        $q = trim((string) $r->input('q', ''));

        $employees = Employee::query()
            ->with('user')
            ->when($q !== '', function ($qq) use ($q) {
                $qq->where(function ($w) use ($q) {
                    $w->where('last_name', 'like', "%{$q}%")
                       ->orWhere('first_name', 'like', "%{$q}%")
                       ->orWhere('middle_name', 'like', "%{$q}%")
                       ->orWhere('short_name', 'like', "%{$q}%")
                       ->orWhere('position', 'like', "%{$q}%")
                       ->orWhere('department', 'like', "%{$q}%");
                });
            })
            ->orderBy('short_name')
            ->paginate(50)
            ->withQueryString();

        return view('employees.index', compact('employees', 'q'));
    }

    public function create()
    {
        $users = $this->usersAvailableFor();

        return view('employees.create', compact('users'));
    }

    public function store(Request $r)
    {
        $data = $r->validate([
            'last_name'   => 'required|string|max:100',
            'first_name'  => 'required|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'position'    => 'nullable|string|max:150',
            'department'  => 'nullable|string|max:150',
            'is_active'   => 'boolean',
            'user_id'     => 'nullable|exists:users,id|unique:employees,user_id',
        ]);

        $data['is_active']  = $r->boolean('is_active');
        $data['short_name'] = $this->buildShortName(
            $data['last_name'],
            $data['first_name'],
            $data['middle_name'] ?? ''
        );

        if (Employee::where('short_name', $data['short_name'])->exists()) {
            return back()->withInput()->withErrors([
                'last_name' => "Сотрудник «{$data['short_name']}» уже существует.",
            ]);
        }

        try {
            Employee::create($data);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() === '23000') {
                return back()->withInput()->withErrors([
                    'general' => 'Такой сотрудник или учётная запись уже используется.',
                ]);
            }
            throw $e;
        }

        return redirect()->route('employees.index')->with('ok', 'Сотрудник добавлен');
    }

    public function edit(Employee $employee)
    {
        $users = $this->usersAvailableFor($employee);

        return view('employees.edit', compact('employee', 'users'));
    }

    public function update(Request $r, Employee $employee)
    {
        $data = $r->validate([
            'last_name'   => 'required|string|max:100',
            'first_name'  => 'required|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'position'    => 'nullable|string|max:150',
            'department'  => 'nullable|string|max:150',
            'is_active'   => 'boolean',
            'user_id'     => [
                'nullable',
                'exists:users,id',
                Rule::unique('employees', 'user_id')->ignore($employee->id),
            ],
        ]);

        $data['is_active'] = $r->boolean('is_active');
        $newShort = $this->buildShortName($data['last_name'], $data['first_name'], $data['middle_name'] ?? '');

        if (Employee::where('short_name', $newShort)->where('id', '!=', $employee->id)->exists()) {
            return back()->withInput()->withErrors([
                'last_name' => "Сотрудник «{$newShort}» уже существует.",
            ]);
        }
        $data['short_name'] = $newShort;

        try {
            $employee->update($data);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() === '23000') {
                return back()->withInput()->withErrors([
                    'general' => 'Такая учётная запись уже привязана к другому сотруднику.',
                ]);
            }
            throw $e;
        }

        return redirect()->route('employees.index')->with('ok', 'Сотрудник обновлён');
    }

    public function destroy(Employee $employee)
    {
        $employee->delete();
        return redirect()->route('employees.index')->with('ok', 'Сотрудник удалён');
    }

    public function export()
    {
        $rows = Employee::query()->with('user')->orderBy('short_name')->get();

        try {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            $sheet->fromArray([
                ['Фамилия', 'Имя', 'Отчество', 'Кратко', 'Должность', 'Участок', 'Активен', 'Учётка'],
            ], null, 'A1');

            $r = 2;
            foreach ($rows as $e) {
                $sheet->fromArray([[
                    $e->last_name,
                    $e->first_name,
                    $e->middle_name,
                    $e->short_name,
                    $e->position,
                    $e->department,
                    $e->is_active ? 'Да' : 'Нет',
                    $e->user?->email,
                ]], null, 'A' . $r);
                $r++;
            }

            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);

            return response()->streamDownload(function () use ($writer) {
                $writer->save('php://output');
            }, 'employees-' . now()->format('Ymd-His') . '.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]);
        } catch (\Throwable $e) {
            return response()->streamDownload(function () use ($rows) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['Фамилия', 'Имя', 'Отчество', 'Кратко', 'Должность', 'Участок', 'Активен', 'Учётка']);
                foreach ($rows as $e) {
                    fputcsv($out, [
                        $e->last_name, $e->first_name, $e->middle_name, $e->short_name,
                        $e->position, $e->department, $e->is_active ? 'Да' : 'Нет', $e->user?->email,
                    ]);
                }
                fclose($out);
            }, 'employees-' . now()->format('Ymd-His') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }
    }

    public function template()
    {
        try {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->fromArray([
                ['Фамилия', 'Имя', 'Отчество', 'Должность', 'Участок', 'Активен'],
                ['Иванов', 'Иван', 'Иванович', 'Инженер', 'Цех 1', 'Да'],
            ], null, 'A1');

            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);

            return response()->streamDownload(function () use ($writer) {
                $writer->save('php://output');
            }, 'employees-template.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]);
        } catch (\Throwable $e) {
            return response()->streamDownload(function () {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['Фамилия', 'Имя', 'Отчество', 'Должность', 'Участок', 'Активен']);
                fputcsv($out, ['Иванов', 'Иван', 'Иванович', 'Инженер', 'Цех 1', 'Да']);
                fclose($out);
            }, 'employees-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }
    }

    public function importForm()
    {
        return view('employees.import');
    }

    public function import(Request $r)
    {
        $r->validate(['file' => 'required|file|max:8192']);

        $file = $r->file('file');
        $rows = [];

        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file->getRealPath());
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, false);
        } catch (\Throwable $e) {
            $handle = fopen($file->getRealPath(), 'r');
            if ($handle !== false) {
                $bom = fread($handle, 3);
                if ($bom !== "\xEF\xBB\xBF") rewind($handle);
                while (($r2 = fgetcsv($handle)) !== false) $rows[] = $r2;
                fclose($handle);
            }
        }

        if (empty($rows)) {
            return back()->withErrors(['file' => 'Файл пустой или не удалось прочитать.']);
        }

        $header = array_shift($rows);
        $header = array_map(fn($h) => mb_strtolower(trim((string) $h)), $header);

        $map = [];
        foreach (self::IMPORT_HEADER_ALIASES as $field => $aliases) {
            foreach ($header as $idx => $h) {
                if (in_array($h, $aliases, true)) {
                    $map[$field] = $idx;
                    break;
                }
            }
        }

        $created = 0; $updated = 0; $skipped = 0;
        $errors = [];

        foreach ($rows as $rowNum => $row) {
            if (empty(array_filter($row, fn($v) => trim((string) $v) !== ''))) continue;

            $lastName   = $map['last_name']   ?? null;
            $firstName  = $map['first_name']  ?? null;
            $middleName = $map['middle_name'] ?? null;
            $shortCol   = $map['short_name']  ?? null;

            $last  = $lastName   !== null ? trim((string) ($row[$lastName]   ?? '')) : '';
            $first = $firstName  !== null ? trim((string) ($row[$firstName]  ?? '')) : '';
            $mid   = $middleName !== null ? trim((string) ($row[$middleName] ?? '')) : '';
            $short = $shortCol   !== null ? trim((string) ($row[$shortCol]   ?? '')) : '';

            if (($last === '' || $first === '') && $short !== '') {
                $parsed = $this->parseShortName($short);
                $last  = $last  ?: $parsed['last_name'];
                $first = $first ?: $parsed['first_name'];
                $mid   = $mid   ?: $parsed['middle_name'];
            }

            if ($last === '' || $first === '') {
                $skipped++;
                $errors[] = 'Строка ' . ($rowNum + 2) . ': нет фамилии или имени.';
                continue;
            }

            $shortName = $this->buildShortName($last, $first, $mid);

            $employee = Employee::where('short_name', $shortName)->first() ?? new Employee();
            $employee->short_name  = $shortName;
            $employee->last_name   = $last;
            $employee->first_name  = $first;
            $employee->middle_name = $mid;

            if ($map['position']   ?? null) {
                $employee->position = trim((string) ($row[$map['position']] ?? ''));
            }
            if ($map['department'] ?? null) {
                $employee->department = trim((string) ($row[$map['department']] ?? ''));
            }

            $employee->is_active = true;
            if ($map['is_active'] ?? null) {
                $val = mb_strtolower(trim((string) ($row[$map['is_active']] ?? '')));
                $employee->is_active = !in_array($val, ['0', 'нет', 'no', 'false', 'неактивен'], true);
            }

            if ($employee->exists) $updated++; else $created++;
            $employee->save();
        }

        return redirect()->route('employees.index')
            ->with('ok', "Импорт завершён: добавлено {$created}, обновлено {$updated}, пропущено {$skipped}")
            ->with('import_errors', $errors);
    }
}