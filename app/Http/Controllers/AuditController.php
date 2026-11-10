<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

/** Журнал действий (только администратор). */
class AuditController extends Controller
{
    public function index(Request $r)
    {
        $f = $r->validate([
            'area'    => ['nullable', 'string', 'max:30'],
            'action'  => ['nullable', 'string', 'max:40'],
            'user_id' => ['nullable', 'integer'],
            'from'    => ['nullable', 'date'],
            'to'      => ['nullable', 'date'],
            'q'       => ['nullable', 'string', 'max:100'],
        ]);

        $logs = AuditLog::query()
            ->with('section:id,name')
            ->when($f['area'] ?? null, fn ($q, $v) => $q->where('area', $v))
            ->when($f['action'] ?? null, fn ($q, $v) => $q->where('action', $v))
            ->when($f['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', \Carbon\Carbon::parse($v)->startOfDay()))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', \Carbon\Carbon::parse($v)->endOfDay()))
            ->when($f['q'] ?? null, function ($q, $v) {
                $q->where(fn ($w) => $w->where('summary', 'like', '%' . addcslashes($v, '%_\\') . '%')
                    ->when(ctype_digit($v), fn ($x) => $x->orWhere('subject_id', (int) $v)));
            })
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        $users = User::orderBy('name')->get(['id', 'name']);

        return view('audit.index', [
            'logs'   => $logs,
            'users'  => $users,
            'filter' => $f,
            'labels' => self::fieldLabels(),
        ]);
    }

    /** Названия полей вкладок для показа изменений: [slug][field] => label. */
    public static function fieldLabels(): array
    {
        $out = [];
        foreach (config('sheets.map', []) as $slug => $cfg) {
            foreach ($cfg['fields'] ?? [] as $field => $fcfg) {
                $out[$slug][$field] = $fcfg['label'] ?? $field;
            }
        }

        return $out;
    }
}
