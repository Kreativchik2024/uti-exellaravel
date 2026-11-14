<?php

namespace App\Http\Controllers;

use App\Models\CameraBracketRecord;
use App\Models\Employee;
use App\Models\NightCameraAssemblyRecord;
use App\Models\Section;
use App\Services\EmployeeDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * API программ на рабочих станциях (доступ — ключ станции, см. StationKey).
 *
 *  - флешер камер после успешной прошивки: POST camera-flash → «Ночная камера в сборе с дневной»;
 *  - считыватель номера камеры: POST camera-bracket → «Кронштейн с камерой»;
 *  - GET camera/{serial} — цепочка: кто прошил камеру, кто собрал с ней кронштейн.
 *
 * Исполнитель подтверждается ФИО + ПИН, как в форме на сайте. Запись создаётся
 * от имени его учётной записи (created_by, журнал действий).
 */
class StationController extends Controller
{
    private const SHEETS = [
        'night_camera_assembly' => NightCameraAssemblyRecord::class,
        'camera_bracket'        => CameraBracketRecord::class,
    ];

    public function __construct(private EmployeeDirectory $employees) {}

    public function ping(): JsonResponse
    {
        return response()->json(['ok' => true, 'server' => config('app.name'), 'time' => now()->toIso8601String()]);
    }

    /** Сотрудники с ПИН — для выпадающего списка в программе. */
    public function employees(): JsonResponse
    {
        [$withPin] = $this->employees->splitByPin();

        return response()->json(['ok' => true, 'employees' => $withPin]);
    }

    /** Вход оператора в программу на смену: ФИО + ПИН (и доступ к участку вкладки). */
    public function verify(Request $r): JsonResponse
    {
        $data = $r->validate([
            'employee' => ['required', 'string', 'max:120'],
            'pin'      => ['required', 'string'],
            'sheet'    => ['required', Rule::in(array_keys(self::SHEETS))],
        ]);

        if ($fail = $this->authorizeEmployee($data, $data['sheet'])) return $fail;

        return response()->json(['ok' => true, 'employee' => Auth::user()->employee->short_name]);
    }

    /** Флешер: камера прошита (дневная вместе с ночной). */
    public function cameraFlash(Request $r): JsonResponse
    {
        $data = $r->validate([
            'camera_serial' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._-]+$/'],
            'employee'      => ['required', 'string', 'max:120'],
            'pin'           => ['required', 'string'],
            'record_date'   => ['nullable', 'date', 'before_or_equal:today'],
            'notes'         => ['nullable', 'string', 'max:2000'],
            'uid'           => ['nullable', 'string', 'max:64'],
        ]);

        if ($fail = $this->authorizeEmployee($data, 'night_camera_assembly')) return $fail;
        if ($done = $this->alreadySaved($data, 'night_camera_assembly')) return $done;

        $date = $data['record_date'] ?? now()->toDateString();

        // Ту же камеру в тот же день повторно не считаем (повтор отправки, перезапуск прошивки)
        $existing = NightCameraAssemblyRecord::where('camera_serial', $data['camera_serial'])
            ->whereDate('record_date', $date)->first();
        if ($existing) {
            return $this->saved($data, 'night_camera_assembly', $existing->id, true);
        }

        $record = NightCameraAssemblyRecord::create([
            'record_date'      => $date,
            'camera_serial'    => $data['camera_serial'],
            'qty'              => 1,
            'flashed_with_day' => true,
            'worker1'          => Auth::user()->employee->short_name,
            'notes'            => $data['notes'] ?? null,
            'created_by'       => Auth::id(),
        ]);

        return $this->saved($data, 'night_camera_assembly', $record->id);
    }

    /** Считыватель: кронштейн с камерой собран. */
    public function cameraBracket(Request $r): JsonResponse
    {
        $cfg = config('sheets.map.camera_bracket.fields');
        $status = fn (string $f) => ['nullable', Rule::in($cfg[$f]['options'] ?? [])];

        $data = $r->validate([
            'camera_serial'       => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._-]+$/'],
            'serial_number'       => ['nullable', 'string', 'max:120'],
            'camera_status'       => $status('camera_status'),
            'night_camera_status' => $status('night_camera_status'),
            'speed_sensor_status' => $status('speed_sensor_status'),
            'suspension_status'   => $status('suspension_status'),
            'notes'               => ['nullable', 'string', 'max:2000'],
            'comments'            => ['nullable', 'string', 'max:2000'],
            'employee'            => ['required', 'string', 'max:120'],
            'pin'                 => ['required', 'string'],
            'record_date'         => ['nullable', 'date', 'before_or_equal:today'],
            'uid'                 => ['nullable', 'string', 'max:64'],
        ]);

        if ($fail = $this->authorizeEmployee($data, 'camera_bracket')) return $fail;
        if ($done = $this->alreadySaved($data, 'camera_bracket')) return $done;

        $record = CameraBracketRecord::create(array_merge(
            collect($data)->only([
                'camera_serial', 'serial_number', 'camera_status', 'night_camera_status',
                'speed_sensor_status', 'suspension_status', 'notes', 'comments',
            ])->all(),
            [
                'record_date' => $data['record_date'] ?? now()->toDateString(),
                'worker1'     => Auth::user()->employee->short_name,
                'created_by'  => Auth::id(),
            ],
        ));

        return $this->saved($data, 'camera_bracket', $record->id);
    }

    /** Цепочка по номеру камеры: все записи прошивки и сборки. */
    public function camera(string $serial): JsonResponse
    {
        $chain = [];
        foreach (self::SHEETS as $sheet => $model) {
            $title = config("sheets.map.$sheet.title");
            foreach ($model::where('camera_serial', $serial)->orderBy('record_date')->orderBy('id')->get() as $rec) {
                $chain[] = [
                    'sheet'   => $sheet,
                    'title'   => $title,
                    'id'      => $rec->id,
                    'date'    => $rec->record_date?->toDateString(),
                    'workers' => collect(['worker1', 'worker2', 'worker3', 'worker4', 'worker5'])
                        ->map(fn ($w) => $rec->{$w})->filter()->values()->all(),
                    'serial_number' => $rec->serial_number ?? null,
                    'url'     => route('records.show', [$sheet, $rec->id]),
                ];
            }
        }

        return response()->json(['ok' => true, 'camera_serial' => $serial, 'chain' => $chain]);
    }

    // ------------------------------------------------------------------

    /** ПИН сотрудника + доступ к участку вкладки. Успех — null и вход от имени сотрудника на этот запрос. */
    private function authorizeEmployee(array $data, string $sheet): ?JsonResponse
    {
        /** @var ?Employee $employee */
        [$employee, $error, $status] = $this->employees->verifyPin($data['employee'], $data['pin']);
        if (!$employee) {
            return response()->json(['ok' => false, 'error' => $error], $status);
        }

        $user = $employee->user;
        if (!$user->is_active) {
            return response()->json(['ok' => false, 'error' => 'Учётная запись сотрудника отключена.'], 403);
        }

        $sectionSlug = config("sheets.map.$sheet.section", Section::DEFAULT_SLUG);
        if (!$user->isAdmin() && !$user->sections()->where('slug', $sectionSlug)->exists()) {
            return response()->json(['ok' => false, 'error' => 'У сотрудника нет доступа к участку этой вкладки.'], 403);
        }

        Auth::setUser($user);

        return null;
    }

    /** Повторная отправка с тем же uid (очередь программы после обрыва связи) — без дубля. */
    private function alreadySaved(array $data, string $sheet): ?JsonResponse
    {
        if (empty($data['uid'])) return null;
        $id = Cache::get($this->uidKey($sheet, $data['uid']));

        return $id ? $this->saved($data, $sheet, (int) $id, true) : null;
    }

    private function saved(array $data, string $sheet, int $id, bool $duplicate = false): JsonResponse
    {
        if (!empty($data['uid'])) {
            Cache::put($this->uidKey($sheet, $data['uid']), $id, now()->addDays(30));
        }

        return response()->json([
            'ok'        => true,
            'id'        => $id,
            'duplicate' => $duplicate,
            'url'       => route('records.show', [$sheet, $id]),
        ], $duplicate ? 200 : 201);
    }

    private function uidKey(string $sheet, string $uid): string
    {
        return "station-uid:{$sheet}:" . md5($uid);
    }
}
