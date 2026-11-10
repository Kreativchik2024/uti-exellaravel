<?php

namespace App\Http\Controllers;

use App\Services\SectionContext;
use Illuminate\Http\Request;

/**
 * Поиск по номеру борта, серийнику, IP, коду неисправности — сразу во всех вкладках
 * текущего участка. Показывает, где и когда встречался номер: история изделия.
 */
class SearchController extends Controller
{
    private const PER_SHEET = 20;
    private const SKIP_FIELDS = ['worker1', 'worker2', 'worker3', 'worker4', 'worker5'];

    public function index(Request $r, SectionContext $sections)
    {
        $q = trim((string) $r->input('q', ''));
        $groups = [];
        $total = 0;

        if (mb_strlen($q) >= 2) {
            $like = '%' . addcslashes($q, '%_\\') . '%';

            foreach ($sections->sheets() as $slug => $cfg) {
                if (empty($cfg['model'])) continue;

                $fields = collect($cfg['fields'])
                    ->filter(fn ($f, $name) => in_array($f['type'] ?? 'text', ['text', 'combo', 'ip_suffix'], true)
                        && ! in_array($name, self::SKIP_FIELDS, true))
                    ->keys()->all();
                if (! $fields) continue;

                $query = $cfg['model']::query()->where(function ($w) use ($fields, $like, $q) {
                    foreach ($fields as $f) $w->orWhere($f, 'like', $like);
                    if (ctype_digit(ltrim($q, '#'))) $w->orWhere('id', (int) ltrim($q, '#'));
                });

                $count = (clone $query)->count();
                if (! $count) continue;

                $rows = $query->orderByDesc('id')->limit(self::PER_SHEET)->get()->map(function ($rec) use ($fields, $q, $cfg) {
                    $hits = [];
                    foreach ($fields as $f) {
                        $v = (string) $rec->{$f};
                        if ($v !== '' && mb_stripos($v, $q) !== false) {
                            $hits[] = ['label' => $cfg['fields'][$f]['label'] ?? $f, 'value' => $v];
                        }
                    }
                    return [
                        'id'     => $rec->id,
                        'date'   => $rec->record_date ?? $rec->created_at,
                        'hits'   => $hits,
                        'worker' => $rec->worker1 ?? null,
                        'status' => $rec->status ?? $rec->health ?? null,
                    ];
                });

                $groups[] = ['slug' => $slug, 'title' => $cfg['title'], 'count' => $count, 'rows' => $rows];
                $total += $count;
            }
        }

        return view('search.index', compact('q', 'groups', 'total'));
    }
}
