<?php

namespace App\Imports;

use App\Models\Employee;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;

class EmployeesImport implements ToCollection, SkipsEmptyRows
{
    public int $created = 0;
    public int $updated = 0;
    public array $errors = [];

    private const FIELD_ALIASES = [
        'last_name'   => ['фамилия', 'last_name', 'lastname', 'surname', 'фамилияи'],
        'first_name'  => ['имя', 'first_name', 'firstname', 'name', 'имяи'],
        'middle_name' => ['отчество', 'middle_name', 'middlename', 'patronymic'],
        'short_name'  => ['кратко', 'short_name', 'shortname', 'фио', 'fio'],
        'position'    => ['должность', 'position'],
        'department'  => ['участок', 'department', 'отдел'],
        'is_active'   => ['активен', 'is_active', 'active', 'активный'],
    ];

    public function collection(Collection $rows): void
    {
        if ($rows->isEmpty()) {
            $this->errors[] = 'Файл пустой.';
            return;
        }

        $headerRowIndex = null;
        $headerMap = [];
        foreach ($rows->take(5) as $i => $row) {
            $map = $this->buildHeaderMap($row->toArray());
            if (isset($map['last_name']) && isset($map['first_name'])) {
                $headerRowIndex = $i;
                $headerMap = $map;
                break;
            }
        }

        if ($headerRowIndex === null) {
            $headerMap = [
                'last_name'   => 0,
                'first_name'  => 1,
                'middle_name' => 2,
                'position'    => 3,
                'department'  => 4,
                'is_active'   => 5,
            ];
            $dataRows = $rows;
        } else {
            $dataRows = $rows->slice($headerRowIndex + 1);
        }

        foreach ($dataRows as $row) {
            $cells = array_values($row->toArray());

            $last   = $this->cell($cells, $headerMap, 'last_name');
            $first  = $this->cell($cells, $headerMap, 'first_name');
            $middle = $this->cell($cells, $headerMap, 'middle_name');
            $short  = $this->cell($cells, $headerMap, 'short_name');
            $pos    = $this->cell($cells, $headerMap, 'position');
            $dep    = $this->cell($cells, $headerMap, 'department');
            $active = $this->cell($cells, $headerMap, 'is_active');

            $allEmpty = true;
            foreach ($cells as $v) {
                if (trim((string) $v) !== '') { $allEmpty = false; break; }
            }
            if ($allEmpty) continue;

            if (($last === '' || $first === '') && $short !== '') {
                [$last, $first] = $this->parseShort($short);
            }

            if ($last === '' || $first === '') {
                $preview = implode(' | ', array_map(
                    fn($v) => mb_substr(trim((string) $v), 0, 30),
                    array_slice($cells, 0, 6)
                ));
                $this->errors[] = 'Пропущена строка: ' . ($preview ?: '(пустая)');
                continue;
            }

            $isActive  = $this->toBool($active, true);
            $shortName = $short !== '' ? $short : Employee::buildShortName($last, $first, $middle ?: null);

            $existing = Employee::query()
                ->where('last_name', $last)
                ->where('first_name', $first)
                ->where(function ($q) use ($middle) {
                    if ($middle === '') {
                        $q->whereNull('middle_name')->orWhere('middle_name', '');
                    } else {
                        $q->where('middle_name', $middle);
                    }
                })
                ->first();

            if ($existing) {
                $existing->update([
                    'middle_name' => $middle ?: $existing->middle_name,
                    'short_name'  => $shortName,
                    'position'    => $pos ?: $existing->position,
                    'department'  => $dep ?: $existing->department,
                    'is_active'   => $isActive,
                ]);
                $this->updated++;
            } else {
                Employee::create([
                    'last_name'   => $last,
                    'first_name'  => $first,
                    'middle_name' => $middle ?: null,
                    'short_name'  => $shortName,
                    'position'    => $pos ?: null,
                    'department'  => $dep ?: null,
                    'is_active'   => $isActive,
                ]);
                $this->created++;
            }
        }
    }

    private function buildHeaderMap(array $headerRow): array
    {
        $map = [];

        foreach (array_values($headerRow) as $i => $h) {
            $key = $this->normalizeKey((string) $h);
            if ($key === '') continue;

            foreach (self::FIELD_ALIASES as $field => $aliases) {
                if (isset($map[$field])) continue;
                foreach ($aliases as $alias) {
                    if ($this->normalizeKey($alias) === $key) {
                        $map[$field] = $i;
                        break;
                    }
                }
            }
        }

        return $map;
    }

    private function cell(array $cells, array $map, string $field): string
    {
        if (!isset($map[$field])) return '';
        $idx = $map[$field];
        return trim((string) ($cells[$idx] ?? ''));
    }

    private function parseShort(string $short): array
    {
        $parts = preg_split('/\s+/u', trim($short)) ?: [];
        if (count($parts) === 0) return ['', ''];
        return [$parts[0] ?? '', trim(implode(' ', array_slice($parts, 1)))];
    }

    private function toBool($v, bool $default): bool
    {
        if ($v === '' || $v === null) return $default;
        $s = mb_strtolower(trim((string) $v));
        return in_array($s, ['1', 'да', 'yes', 'true', 'y', 'истина', '+'], true);
    }

    private function normalizeKey(string $key): string
    {
        $key = mb_strtolower(trim($key), 'UTF-8');
        $key = str_replace(['_', '-', ' ', '.', '«', '»', '"', "'", '(', ')'], '', $key);

        $map = [
            'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh',
            'з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o',
            'п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'c',
            'ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
        ];

        return strtr($key, $map);
    }
}