<?php

namespace App\Http\Controllers;

use App\Models\ActiveWorkerSession;
use App\Models\Employee;
use Illuminate\Http\Request;

class WorkerSessionController extends Controller
{
    private const OFFLINE_AFTER_SECONDS = 180; // 3 минуты без heartbeat = offline

    public function heartbeat(Request $r)
    {
        $user = auth()->user();
        if (!$user || !$user->employee) return response()->json(['ok' => true, 'skipped' => true]);

        $sessionId = $r->session()->getId();
        $now = now();

        ActiveWorkerSession::updateOrCreate(
            ['employee_id' => $user->employee->id, 'session_id' => $sessionId],
            ['user_id' => $user->id, 'last_heartbeat_at' => $now]
        );

        return response()->json(['ok' => true]);
    }

    public function occupied(Request $r)
    {
        $sessionId = $r->session()->getId();
        $cutoff = now()->subSeconds(self::OFFLINE_AFTER_SECONDS);

        $rows = ActiveWorkerSession::query()
            ->with('employee')
            ->where('last_heartbeat_at', '>=', $cutoff)
            ->where('session_id', '!=', $sessionId)
            ->whereNotNull('sheet')
            // «своего» сотрудника не показываем занятым — себя можно поставить всегда
            ->when($r->user()?->employee, fn ($q, $own) => $q->where('employee_id', '!=', $own->id))
            ->get();

        $map = [];
        foreach ($rows as $row) {
            if ($row->employee) {
                $map[$row->employee->short_name] = [
                    'sheet' => $row->sheet,
                    'record_id' => $row->record_id,
                ];
            }
        }

        return response()->json(['ok' => true, 'occupied' => $map]);
    }

    public function claim(Request $r)
    {
        $user = auth()->user();
        $shortName = trim((string) $r->input('short_name', ''));
        $sheet     = trim((string) $r->input('sheet', ''));
        
        if (!$user || $shortName === '') return response()->json(['ok' => false]);

        $employee = Employee::where('short_name', $shortName)->active()->first();
        if (!$employee) return response()->json(['ok' => false]);

        // Отметить «работает» можно себя, сотрудника, подтверждённого ПИН в этой сессии,
        // или любого — мастеру/админу. Иначе любой мог бы пометить чужих занятыми.
        $verifiedUntil = (int) ($r->session()->get('verified_workers', [])[$employee->id] ?? 0);
        $allowed = $employee->user_id === $user->id
            || $user->canManageContent()
            || $verifiedUntil >= now()->timestamp;
        if (!$allowed) return response()->json(['ok' => false, 'error' => 'Подтвердите ПИН сотрудника'], 403);

        $sessionId = $r->session()->getId();
        $cutoff = now()->subSeconds(self::OFFLINE_AFTER_SECONDS);

        // Себя можно поставить всегда: человек пересел за этот компьютер — прежнее место освобождается
        if ($employee->user_id === $user->id) {
            ActiveWorkerSession::releaseEmployeeElsewhere($employee->id, $sessionId);
        }

        // Проверяем, не занят ли он другим
        $busy = ActiveWorkerSession::where('employee_id', $employee->id)
            ->where('session_id', '!=', $sessionId)
            ->where('last_heartbeat_at', '>=', $cutoff)
            ->whereNotNull('sheet')
            ->exists();

        if ($busy) return response()->json(['ok' => false, 'error' => 'Занят другим пользователем'], 409);

        ActiveWorkerSession::updateOrCreate(
            ['employee_id' => $employee->id, 'session_id' => $sessionId],
            [
                'user_id' => $user->id,
                'sheet' => $sheet,
                'record_id' => $r->input('record_id') ?: null,
                'last_heartbeat_at' => now(),
            ]
        );

        return response()->json(['ok' => true]);
    }

    public function release(Request $r)
    {
        $shortName = trim((string) $r->input('short_name', ''));
        if ($shortName === '') return response()->json(['ok' => true]);

        $employee = Employee::where('short_name', $shortName)->first();
        if (!$employee) return response()->json(['ok' => true]);

        $sessionId = $r->session()->getId();

        ActiveWorkerSession::where('employee_id', $employee->id)
            ->where('session_id', $sessionId)
            ->whereNotNull('sheet')
            ->delete();

        return response()->json(['ok' => true]);
    }
}