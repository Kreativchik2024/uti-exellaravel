<?php

namespace Tests\Feature;

use App\Models\PgpRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SiteHelpers;
use Tests\TestCase;

/** Отчёт по смене и анализ причин брака. */
class ReportsTest extends TestCase
{
    use RefreshDatabase, SiteHelpers;

    private function pgp(string $worker, string $status, ?string $reason = null, ?string $at = null): void
    {
        $r = PgpRecord::create(['record_date' => now()->toDateString(), 'post_number' => 1, 'worker1' => $worker,
            'board_number' => 'RA-' . uniqid(), 'ip_address' => '192.168.1.5', 'status' => $status, 'defect_reason' => $reason,
            'fuselage_number' => 'F', 'frame_serial' => 'M']);
        if ($at) $r->forceFill(['created_at' => $at])->saveQuietly();
    }

    private function master(): User
    {
        $m = User::factory()->master()->create();
        $m->sections()->attach(\App\Models\Section::first()->id);
        return $m;
    }

    public function test_shift_report_counts_workers_and_defects_inside_shift_only(): void
    {
        $day = now()->startOfDay();
        $this->pgp('Иванов И.', 'Сдан', null, $day->copy()->setTime(9, 0));
        $this->pgp('Иванов И.', 'Не сдан', 'нет связи', $day->copy()->setTime(10, 0));
        $this->pgp('Петров П.', 'Сдан', null, $day->copy()->setTime(21, 0));   // ночная смена

        $res = $this->asUser($this->master())->get('/reports/shift?date=' . $day->format('Y-m-d') . '&shift=1')->assertOk();
        $res->assertSee('Иванов И.')->assertDontSee('Петров П.');
        $this->assertSame(2, $res->viewData('data')['workers']['Иванов И.']['total']);
        $this->assertSame(1, $res->viewData('data')['workers']['Иванов И.']['defect']);

        $this->asUser($this->master())->get('/reports/shift?date=' . $day->format('Y-m-d') . '&shift=1&excel=1')
            ->assertOk()->assertDownload();
    }

    public function test_defect_reasons_are_grouped_case_insensitive(): void
    {
        $this->pgp('Иванов И.', 'Не сдан', 'Нет связи');
        $this->pgp('Иванов И.', 'Не сдан', 'нет  связи.');
        $this->pgp('Петров П.', 'Не сдан', 'Трещина рамы');
        $this->pgp('Петров П.', 'Сдан');

        $data = $this->asUser($this->master())->get('/reports/defects')->assertOk()->viewData('data');
        $this->assertSame(3, $data['defect']);
        $this->assertSame(['text' => 'Нет связи', 'count' => 2], array_intersect_key($data['reasons'][0], ['text' => 1, 'count' => 1]));
    }

    public function test_reports_are_for_master_and_admin_only(): void
    {
        $this->asUser($this->worker())->get('/reports/shift')->assertForbidden();
        $this->asUser($this->worker())->get('/reports/defects')->assertForbidden();
    }
}
