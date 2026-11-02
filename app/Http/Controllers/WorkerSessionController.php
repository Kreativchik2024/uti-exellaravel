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

    public function online()
    {
        $cutoff = now()->subSeconds(self::OFFLINE_AFTER_SECONDS);

        $rows = ActiveWorkerSession::query()
            ->with(['employee', 'user'])
            ->where('last_heartbeat_at', '>=', $cutoff)
            ->orderBy('last_heartbeat_at', 'desc')
            ->get();

        $seen = [];
        $result = [];
        foreach ($rows as $row) {
            if (isset($seen[$row->employee_id])) continue;
            $seen[$row->employee_id] = true;

            $result[] = [
                'employee_name' => $row->employee?->short_name,
                'sheet'         => $row->sheet,
                'record_id'     => $row->record_id,
            ];
        }

        return response()->json(['ok' => true, 'online' => $result, 'count' => count($result)]);
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

        $sessionId = $r->session()->getId();
        $cutoff = now()->subSeconds(self::OFFLINE_AFTER_SECONDS);

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