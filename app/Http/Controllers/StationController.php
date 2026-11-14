<?php

namespace App\Http\Controllers;

use App\Models\CameraBracketRecord;
use App\Models\Employee;
use App\Models\NightCameraAssemblyRecord;
use App\Models\Section;
use App\Services\EmployeeDirectory;
use App\Services\FaultCodes;
use App\Services\SectionContext;
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

    /** До 3 исполнителей, у каждого свой ПИН: workers = [{employee, pin}, …]; один — можно employee + pin. */
    private const MAX_WORKERS = 3;
    private const WORKER_RULES = [
        'employee'           => ['required_without:workers', 'nullable', 'string', 'max:120'],
        'pin'                => ['required_with:employee', 'nullable', 'string'],
        'workers'            => ['nullable', 'array', 'min:1', 'max:' . self::MAX_WORKERS],
        'workers.*.employee' => ['required', 'string', 'max:120'],
        'workers.*.pin'      => ['required', 'string'],
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

        [$user, $fail] = $this->authorizeEmployee($data['employee'], $data['pin'], $data['sheet']);
        if ($fail) return $fail;

        return response()->json(['ok' => true, 'employee' => $user->employee->short_name]);
    }

    /** Флешер: камера прошита (дневная вместе с ночной). */
    public function cameraFlash(Request $r): JsonResponse
    {
        $data = $r->validate([
            'camera_serial' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._-]+$/'],
            'record_date'   => ['nullable', 'date', 'before_or_equal:today'],
            'notes'         => ['nullable', 'string', 'max:2000'],
            // брак: оператор решил занести камеру с кодом неисправности (код / описание — как в форме сайта)
            'fault_code'    => ['nullable', 'string', 'max:100'],
            'uid'           => ['nullable', 'string', 'max:64'],
        ] + self::WORKER_RULES);

        [$workers, $fail] = $this->authorizeWorkers($data, 'night_camera_assembly');
        if ($fail) return $fail;
        if ($done = $this->alreadySaved($data, 'night_camera_assembly')) return $done;

        $date = $data['record_date'] ?? now()->toDateString();
        $fault = null;
        if (filled($data['fault_code'] ?? null)) {
            $this->pinSection('night_camera_assembly');
            $fault = app(FaultCodes::class)->resolve('night_camera_assembly', $data['fault_code'], Auth::id());
        }

        // Ту же камеру в тот же день — одна запись (повтор отправки, перезапуск прошивки). Если её
        // состояние сменилось (была брак → после ремонта прошита, или наоборот) — запись обновляется.
        $existing = NightCameraAssemblyRecord::where('camera_serial', $data['camera_serial'])
            ->whereDate('record_date', $date)->first();
        if ($existing) {
            if ((string) $existing->fault_code !== (string) $fault) {
                $existing->update(['fault_code' => $fault, 'notes' => $data['notes'] ?? $existing->notes,
                                   'updated_by' => Auth::id()] + $workers);
            }
            return $this->saved($data, 'night_camera_assembly', $existing->id, true);
        }

        $record = NightCameraAssemblyRecord::create([
            'record_date'      => $date,
            'camera_serial'    => $data['camera_serial'],
            'qty'              => 1,
            'flashed_with_day' => true,
            'notes'            => $data['notes'] ?? null,
            'fault_code'       => $fault,
            'created_by'       => Auth::id(),
        ] + $workers);

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
            'assembled_by'        => ['nullable', 'string', 'max:120'],
            'camera_status'       => $status('camera_status'),
            'night_camera_status' => $status('night_camera_status'),
            'speed_sensor_status' => $status('speed_sensor_status'),
            'suspension_status'   => $status('suspension_status'),
            'notes'               => ['nullable', 'string', 'max:2000'],
            'comments'            => ['nullable', 'string', 'max:2000'],
            'fault_code'          => ['nullable', 'string', 'max:100'],
            'record_date'         => ['nullable', 'date', 'before_or_equal:today'],
            'uid'                 => ['nullable', 'string', 'max:64'],
        ] + self::WORKER_RULES);

        [$workers, $fail] = $this->authorizeWorkers($data, 'camera_bracket');
        if ($fail) return $fail;
        if ($done = $this->alreadySaved($data, 'camera_bracket')) return $done;

        // Код неисправности — как в форме сайта: код из справочника, код похожей неисправности
        // или новый код, если вписано описание новой
        if (filled($data['fault_code'] ?? null)) {
            $this->pinSection('camera_bracket');
            $data['fault_code'] = app(FaultCodes::class)->resolve('camera_bracket', $data['fault_code'], Auth::id());
        }

        $record = CameraBracketRecord::create(array_merge(
            collect($data)->only([
                'camera_serial', 'serial_number', 'assembled_by', 'camera_status', 'night_camera_status',
                'speed_sensor_status', 'suspension_status', 'notes', 'comments', 'fault_code',
            ])->all(),
            [
                'record_date' => $data['record_date'] ?? now()->toDateString(),
                'created_by'  => Auth::id(),
            ],
            $workers,
            $this->flashSnapshot($data['camera_serial']),
        ));

        return $this->saved($data, 'camera_bracket', $record->id);
    }

    /** Справочник кодов неисправностей вкладки (как выпадающий список в форме сайта). */
    public function faultCodes(Request $r): JsonResponse
    {
        $data = $r->validate(['sheet' => ['required', Rule::in(array_keys(self::SHEETS))]]);
        $this->pinSection($data['sheet']);

        return response()->json(['ok' => true, 'codes' => array_map(
            fn ($c) => array_intersect_key($c, array_flip(['code', 'description', 'hint', 'group'])),
            app(FaultCodes::class)->optionsForSheet($data['sheet']),
        )]);
    }

    /** Участок вкладки — для справочника кодов (у запроса станции нет сессии с выбранным участком). */
    private function pinSection(string $sheet): void
    {
        $slug = config("sheets.map.$sheet.section", Section::DEFAULT_SLUG);
        $section = Section::where('slug', $slug)->first();
        if ($section) app(SectionContext::class)->pin($section);
    }

    /** Подсказка «Прошил» для кронштейна (как при сохранении формы на сайте): кто и когда прошил камеру. */
    private function flashSnapshot(string $serial): array
    {
        $rec = NightCameraAssemblyRecord::where('camera_serial', $serial)
            ->orderByDesc('record_date')->orderByDesc('id')->first();
        if (!$rec) return [];

        $workers = collect(['worker1', 'worker2', 'worker3', 'worker4', 'worker5'])
            ->map(fn ($w) => $rec->{$w})->filter()->implode(', ');

        return [
            'flash_worker'      => $workers ?: null,
            'flash_record_date' => $rec->record_date?->toDateString(),
            'flash_health'      => filled($rec->fault_code) ? 'red' : 'green',
            'flash_status_text' => filled($rec->fault_code) ? 'Неисправность: ' . $rec->fault_code : 'Всё в порядке',
        ];
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

    /**
     * Все исполнители записи: ПИН каждого + доступ к участку. Успех — [['worker1' => …, …], null]
     * и вход от имени первого исполнителя на этот запрос (created_by, журнал действий).
     *
     * @return array{0: array<string, string>, 1: ?JsonResponse}
     */
    private function authorizeWorkers(array $data, string $sheet): array
    {
        $list = $data['workers'] ?? [['employee' => $data['employee'], 'pin' => $data['pin']]];

        $workers = [];
        $first = null;
        foreach (array_values($list) as $i => $w) {
            [$user, $fail] = $this->authorizeEmployee($w['employee'], $w['pin'], $sheet);
            if ($fail) {
                $err = $fail->getData(true);
                $err['error'] = trim($w['employee']) . ': ' . ($err['error'] ?? 'ошибка');
                return [[], response()->json($err, $fail->getStatusCode())];
            }
            $name = $user->employee->short_name;
            if (in_array($name, $workers, true)) {
                return [[], response()->json(['ok' => false, 'error' => "{$name} указан дважды."], 422)];
            }
            $workers['worker' . ($i + 1)] = $name;
            $first ??= $user;
        }

        Auth::setUser($first);

        return [$workers, null];
    }

    /**
     * ПИН сотрудника + доступ к участку вкладки.
     *
     * @return array{0: ?\App\Models\User, 1: ?JsonResponse}
     */
    private function authorizeEmployee(string $name, string $pin, string $sheet): array
    {
        /** @var ?Employee $employee */
        [$employee, $error, $status] = $this->employees->verifyPin($name, $pin);
        if (!$employee) {
            return [null, response()->json(['ok' => false, 'error' => $error], $status)];
        }

        $user = $employee->user;
        if (!$user->is_active) {
            return [null, response()->json(['ok' => false, 'error' => 'Учётная запись сотрудника отключена.'], 403)];
        }

        $sectionSlug = config("sheets.map.$sheet.section", Section::DEFAULT_SLUG);
        if (!$user->isAdmin() && !$user->sections()->where('slug', $sectionSlug)->exists()) {
            return [null, response()->json(['ok' => false, 'error' => 'У сотрудника нет доступа к участку этой вкладки.'], 403)];
        }

        return [$user, null];
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
