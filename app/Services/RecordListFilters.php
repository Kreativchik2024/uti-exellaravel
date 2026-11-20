<?php

namespace App\Services;

use App\Models\ResearchRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Особые фильтры и вкладки списков ПГП и «Исследований» (вынесено из RecordController::index).
 *  ПГП:          tech=open (рекламации), returned=1 (вернулись после ремонта), repaired=1 (были в ремонте) + пометки строк;
 *  Исследования: repair=open[&rs=…] (ремонтнику), act=needed[&kind=act|accept] (технологу), cat=… (подкатегории).
 */
class RecordListFilters
{
    public function __construct(private PgpDefectResearch $defects, private PgpComplaints $complaints) {}

    /** Применить фильтры к запросу списка. @return array переменные для records.index */
    public function apply(string $sheet, array $cfg, Builder $query, Request $request): array
    {
        $vars = [
            'techOpen' => false, 'repairOpen' => false, 'returnedOpen' => false, 'repairedOpen' => false, 'actNeeded' => false,
            'repairTabs' => null, 'researchTabs' => null, 'researchCat' => null, 'actKind' => null, 'actTabs' => null,
        ];

        if ($sheet === 'pgp') {
            // Из меню профиля: необработанные рекламации ПГП
            if ($vars['techOpen'] = $request->query('tech') === 'open') {
                $query->whereIn('id', $this->complaints->openQuery()->select('id'));
            }
            // Борта после ремонта: returned=1 — вернулись и ждут перепроверки; repaired=1 — все, кто был в ремонте
            if ($vars['returnedOpen'] = $request->query('returned') === '1') {
                $query->whereIn('id', $this->defects->returnedFromRepair()?->select('id') ?? [0]);
            }
            if ($vars['repairedOpen'] = $request->query('repaired') === '1') {
                $query->whereHas('researches');
            }
            $vars['repairTabs'] = [
                'returned' => $this->defects->returnedFromRepairCount(),
                'repaired' => $cfg['model']::query()->whereHas('researches')->count(),
            ];
        }

        if ($sheet === 'research') {
            // Из меню ремонтника: брак ПГП, ещё не отремонтированный; rs — один статус
            if ($vars['repairOpen'] = $request->query('repair') === 'open') {
                $query->whereNotNull('pgp_record_id')->where('is_repaired', false);
                $rs = $request->query('rs');
                if (is_string($rs) && isset(ResearchRecord::STATUSES[$rs])) $query->where('repair_status', $rs);
            }
            // Из меню технолога: все / нужен акт / ждёт приёмки
            if ($vars['actNeeded'] = $request->query('act') === 'needed') {
                $vars['actKind'] = in_array($request->query('kind'), ['act', 'accept'], true) ? $request->query('kind') : null;
                $src = $vars['actKind'] ? $this->defects->actsNeededOfKind($vars['actKind']) : $this->defects->actsNeeded();
                $query->whereIn('id', $src?->select('id') ?? [0]);
                $vars['actTabs'] = $this->defects->actsNeededCounts();
            }
            [$vars['researchTabs'], $vars['researchCat']] = $this->researchCategories($cfg, $query, $request);
        }

        return $vars;
    }

    /** Подкатегории исследований: борта (из ПГП или категория вкладки ПГП) и любые другие модули по категории. */
    private function researchCategories(array $cfg, Builder $query, Request $request): array
    {
        $boardTitle = (string) config('sheets.map.pgp.title');
        $isBoard = fn ($q) => $q->where(fn ($w) => $w->whereNotNull('pgp_record_id')->orWhere('category', $boardTitle));
        $noCategory = fn ($q) => $q->whereNull('pgp_record_id')->where(fn ($w) => $w->whereNull('category')->orWhere('category', ''));

        $cat = is_string($request->query('cat')) && $request->query('cat') !== '' ? $request->query('cat') : null;
        if ($cat === '_board') $isBoard($query);
        elseif ($cat === '_none') $noCategory($query);
        elseif ($cat !== null) $query->whereNull('pgp_record_id')->where('category', $cat);

        $base = fn () => $cfg['model']::query();
        $tabs = ['_board' => ['label' => 'Борта', 'count' => $isBoard($base())->count()]];
        $base()->whereNull('pgp_record_id')->where('category', '!=', $boardTitle)->whereNotNull('category')->where('category', '!=', '')
            ->selectRaw('category, COUNT(*) AS cnt')->groupBy('category')->orderByDesc('cnt')->get()
            ->each(function ($row) use (&$tabs) { $tabs[$row->category] = ['label' => $row->category, 'count' => (int) $row->cnt]; });
        if ($none = $noCategory($base())->count()) $tabs['_none'] = ['label' => 'Без категории', 'count' => $none];

        return [$tabs, $cat];
    }

    /**
     * Пометки строк ПГП о ремонте (цепочка документов борта):
     *  'after'     — сдан после ремонта (повторная проверка «Сдан» или старая запись, сданная на месте);
     *  'returned'  — вернулся после ремонта, ждёт повторной проверки;
     *  'rechecked' — по ремонту уже есть повторная проверка (новая запись);
     *  'in_repair' — в ремонте.
     * @return array<int, string> id записи => пометка
     */
    public function repairMarks(string $sheet, iterable $records): array
    {
        $ids = collect($records)->pluck('id');
        if ($sheet !== 'pgp' || $ids->isEmpty()) return [];

        $byPgp = ResearchRecord::whereIn('pgp_record_id', $ids)->withExists('recheck')
            ->get(['id', 'pgp_record_id', 'repair_status'])->groupBy('pgp_record_id');
        $marks = [];
        foreach ($records as $rec) {
            $rs = $byPgp[$rec->id] ?? collect();
            if ($rec->status === 'Сдан') {
                if ($rec->after_research_id || $rs->isNotEmpty()) $marks[$rec->id] = 'after';
                continue;
            }
            if ($rs->isEmpty()) continue;
            $last = $rs->sortByDesc('id')->first();
            $marks[$rec->id] = $last->recheck_exists ? 'rechecked' : ($last->repair_status === 'conveyor' ? 'returned' : 'in_repair');
        }

        return $marks;
    }
}
