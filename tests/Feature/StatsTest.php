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

    public function test_defects_are_counted_in_summary_and_zone5(): void
    {
        $master = $this->worker('master');
        $this->seedPgp($master);

        $zone5 = collect($this->asUser($master)->getJson('/stats/zone5')->assertOk()->json());
        $pgp = $zone5->firstWhere('slug', 'pgp');
        $this->assertSame(3, $pgp['total']);
        $this->assertSame(1, $pgp['defects']);   // один «Не сдан»
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
