<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SiteHelpers;
use Tests\TestCase;

/**
 * Статистика: страница, все зоны (JSON для графиков и таблиц), выгрузка в Excel.
 */
class StatsTest extends TestCase
{
    use RefreshDatabase, SiteHelpers;

    /** Немного записей ПГП: «Сдан» и «Не сдан» на разных постах. */
    private function seedPgp($user): void
    {
        foreach ([['Сдан', 3], ['Не сдан', 3], ['Сдан', 5]] as [$status, $post]) {
            $data = array_merge($this->validRecord('pgp', $this->nameOf($user)), ['status' => $status, 'post_number' => $post]);
            if ($status === 'Не сдан') {
                $data = array_merge($data, ['fault_code' => 'FC-01', 'defect_reason' => 'не включается']);
            }
            $this->asUser($user)->post('/records/pgp', $data)->assertSessionHasNoErrors();
        }
    }

    public function test_stats_page_and_all_zones_respond(): void
    {
        $master = $this->worker('master');
        $this->seedPgp($master);
        $name = $this->nameOf($master);
        $range = ['from' => now()->subDays(7)->toDateString(), 'to' => now()->toDateString()];

        $this->asUser($master)->get('/stats')->assertOk()->assertSee('Сводка по вкладкам');
        $this->asUser($master)->get('/stats?' . http_build_query($range))->assertOk();

        $endpoints = [
            '/stats/zone1'         => [],
            '/stats/zone2'         => ['sheet' => 'pgp'] + $range,
            '/stats/zone2-chart'   => ['sheet' => 'pgp', 'executor' => $name] + $range,
            '/stats/zone2-worker'  => ['executor' => $name, 'sheet' => 'pgp'] + $range,
            '/stats/zone3'         => ['sheet' => 'pgp'] + $range,
            '/stats/zone4'         => ['sheet' => 'pgp'] + $range,
            '/stats/zone4-records' => ['sheet' => 'pgp', 'post' => 3, 'date' => now()->toDateString()],
            '/stats/zone5'         => $range,
            '/stats/zone5-chart'   => ['sheet' => 'pgp'] + $range,
            '/stats/zone6'         => $range,
        ];

        foreach ($endpoints as $url => $params) {
            $this->asUser($master)->getJson($url . '?' . http_build_query($params))
                ->assertOk()
                ->assertHeader('Content-Type', 'application/json');
        }
    }

    /** Сводка по исполнителю считает все вкладки участка и разные написания ФИО, а не только ПГП. */
    public function test_executor_summary_counts_all_sheets_and_name_variants(): void
    {
        $master = $this->worker('master');
        foreach ([['Иванов', 'Иван', 'Иванов И.И.'], ['Петров', 'Пётр', 'Петров П.П.']] as [$last, $first, $short]) {
            \App\Models\Employee::create(['last_name' => $last, 'first_name' => $first, 'short_name' => $short, 'is_active' => true]);
        }
        $today = now()->toDateString();
        \Illuminate\Database\Eloquent\Model::unguarded(function () use ($today) {
            // ПГП: Иванов в двух записях (одна — с другим написанием), Петров — в одной вторым исполнителем
            \App\Models\PgpRecord::create(['record_date' => $today, 'worker1' => 'Иванов И.И.', 'status' => 'Сдан']);
            \App\Models\PgpRecord::create(['record_date' => $today, 'worker1' => 'иванов и. и.', 'worker2' => 'Петров П.П.', 'status' => 'Сдан']);
            // Другая вкладка: Петров работал только здесь — раньше в сводку не попадал
            \App\Models\CommModuleRecord::create(['record_date' => $today, 'worker1' => 'Петров П.П.', 'serial_number' => 'SN-1']);
            \App\Models\CommModuleRecord::create(['record_date' => $today, 'worker1' => 'Петров П.П.', 'serial_number' => 'SN-2']);
        });

        $range = ['from' => now()->subDay()->toDateString(), 'to' => $today];
        $data = $this->asUser($master)->getJson('/stats/zone2?' . http_build_query($range))->assertOk()->json();
        $rows = collect($data['rows'])->pluck('cnt', 'name');
        $this->assertSame(2, $rows['Иванов И.И.'] ?? null, 'оба написания — один человек');
        $this->assertSame(3, $rows['Петров П.П.'] ?? null, 'ПГП + модуль связи');
        $this->assertSame(4, $data['total'], 'записей с исполнителями по всем вкладкам');

        $chart = $this->asUser($master)->getJson('/stats/zone2-chart?' . http_build_query($range + ['executor' => 'Петров П.П.']))->assertOk()->json();
        $this->assertSame(3, array_sum($chart['values']));
        $all = $this->asUser($master)->getJson('/stats/zone2-chart?' . http_build_query($range))->assertOk()->json();
        $this->assertSame(4, array_sum($all['values']));
    }

    public function test_defects_are_counted_in_summary_and_zone5(): void
    {
        $master = $this->worker('master');
        $this->seedPgp($master);

        $zone5 = collect($this->asUser($master)->getJson('/stats/zone5')->assertOk()->json());
        $pgp = $zone5->firstWhere('slug', 'pgp');
        $this->assertSame(3, $pgp['total']);
        $this->assertSame(1, $pgp['defects']);   // один «Не сдан»
    }

    public function test_summary_card_numbers_open_record_lists(): void
    {
        $master = $this->worker('master');
        $this->seedPgp($master);

        $this->asUser($master)->get('/stats?from=2000-01-01')->assertOk()->assertSee('/stats/records?', false);

        $count = fn (string $kind) => $this->asUser($master)->get('/stats/records?sheet=pgp&kind=' . $kind)
            ->assertOk()->viewData('records')->total();
        $this->assertSame(3, $count('all'));
        $this->assertSame(2, $count('good'));
        $this->assertSame(1, $count('defect'));

        $this->asUser($master)->get('/stats/records?sheet=pgp')->assertSee('/records/pgp/', false);
        $this->asUser($master)->get('/stats/records?sheet=nope')->assertNotFound();

        // карточки «Борта по типам»: ✓ — сдано, ✕ — не сдано
        $boards = fn (string $q) => $this->asUser($master)->get('/stats/records?sheet=pgp&' . $q)->assertOk()->viewData('records')->total();
        $this->assertSame(3, $boards('board=_all'));
        $this->assertSame(2, $boards('board=_all&kind=good'));
        $this->assertSame(1, $boards('board=_all&kind=defect'));
        $types = \App\Models\PgpRecord::query()->selectRaw("COALESCE(NULLIF(board_type, ''), '_none') as t, COUNT(*) as c")
            ->groupBy('t')->pluck('c', 't');
        foreach ($types as $t => $c) $this->assertSame((int) $c, $boards('board=' . urlencode($t)));
    }

    /** Раньше без дат («за всё время» — как страница открывается по умолчанию) падало с 500. */
    public function test_excel_export_downloads_with_and_without_dates(): void
    {
        $master = $this->worker('master');
        $this->seedPgp($master);

        foreach (['/stats/export', '/stats/export?from=' . now()->subWeek()->toDateString() . '&to=' . now()->toDateString()] as $url) {
            $response = $this->asUser($master)->get($url);
            $response->assertOk();
            $this->assertStringContainsString('spreadsheetml', $response->headers->get('Content-Type'), $url);
            $this->assertStringContainsString('.xlsx', $response->headers->get('Content-Disposition'), $url);
        }
    }

    public function test_executor_sees_only_his_own_statistics(): void
    {
        $exec = $this->worker();
        $other = $this->worker();
        $this->seedPgp($other);       // чужие записи
        $this->seedPgp($exec);        // свои

        $pgp = collect($this->asUser($exec)->getJson('/stats/zone5')->json())->firstWhere('slug', 'pgp');
        $this->assertSame(3, $pgp['total'], 'исполнитель видит только записи, где он исполнитель');
    }
}
