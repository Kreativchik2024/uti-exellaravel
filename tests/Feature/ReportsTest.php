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

    public function test_shift_records_list_opens_from_report_numbers(): void
    {
        $day = now()->startOfDay();
        $this->pgp('Иванов И.', 'Сдан', null, $day->copy()->setTime(9, 0));
        $this->pgp('Иванов И.', 'Не сдан', 'нет связи', $day->copy()->setTime(10, 0));
        $this->pgp('Петров П.', 'Сдан', null, $day->copy()->setTime(11, 0));
        $this->pgp('Сидоров С.', 'Сдан', null, $day->copy()->setTime(21, 0));   // ночная смена
        $base = '/reports/shift/records?date=' . $day->format('Y-m-d') . '&shift=1';
        $count = fn ($res) => collect($res->viewData('groups'))->sum(fn ($g) => $g['records']->count());

        $this->asUser($this->master())->get('/reports/shift?date=' . $day->format('Y-m-d') . '&shift=1')
            ->assertOk()->assertSee('/reports/shift/records', false);

        $all = $this->asUser($this->master())->get($base)->assertOk();
        $this->assertSame(3, $count($all));
        $all->assertSee('/records/pgp/', false);

        $this->assertSame(2, $count($this->asUser($this->master())->get($base . '&worker=' . urlencode('Иванов И.'))));
        $this->assertSame(1, $count($this->asUser($this->master())->get($base . '&worker=' . urlencode('Иванов И.') . '&defect=1')));
        $this->assertSame(3, $count($this->asUser($this->master())->get($base . '&sheet=pgp')));
        $this->assertSame(0, $count($this->asUser($this->master())->get($base . '&sheet=nope')));
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

    public function test_defect_report_is_readable_with_comparison_insights_and_codes(): void
    {
        \App\Models\FaultCode::create(['code' => 'E01', 'description' => 'Нет связи с модулем связи борта', 'is_active' => true]);
        $mk = fn (string $status, ?string $code, string $date, ?string $reason = null) => PgpRecord::create([
            'record_date' => $date, 'post_number' => 1, 'worker1' => 'Иванов И.', 'board_number' => 'RA-' . uniqid(),
            'status' => $status, 'fault_code' => $code, 'defect_reason' => $reason]);

        // текущий период (последние 30 дней): 4 записи, 3 брака — 2 с кодом E01, 1 без кода
        $mk('Не сдан', 'E01', now()->toDateString());
        $mk('Не сдан', 'e01', now()->subDays(2)->toDateString());
        $mk('Не сдан', null, now()->subDays(3)->toDateString(), 'Трещина рамы');
        $mk('Сдан', null, now()->toDateString());
        // прошлый период: 4 записи, 1 брак
        $mk('Не сдан', 'E01', now()->subDays(40)->toDateString());
        foreach (range(1, 3) as $i) $mk('Сдан', null, now()->subDays(40)->toDateString());

        $res = $this->asUser($this->master())->get('/reports/defects')->assertOk();
        $html = $res->getContent();

        $this->assertSame(['total' => 4, 'defect' => 1], array_intersect_key($res->viewData('prev'), ['total' => 1, 'defect' => 1]));
        $this->assertSame(1, $res->viewData('data')['uncoded']);

        // показатели и сравнение: доля брака 75% против 25% — рост на 50 п.п.
        $res->assertSee('Доля брака')->assertSee('75%')->assertSee('▲ +50 п.п. к прошлому периоду');
        // главное словами, код с расшифровкой, длинный текст не обрезается
        $res->assertSee('Главное за период')->assertSee('Чаще всего — код E01')->assertSee('2 случая (67% всего брака)');
        $res->assertSee('Нет связи с модулем связи борта');
        $res->assertSee('Без кода неисправности — 1 запись');
        $res->assertSee('Трещина рамы');
        // недели, вкладки, быстрые периоды
        $res->assertSee('Доля брака по неделям')->assertSee('dr-week', false);
        $res->assertSee('По вкладкам')->assertSee('3 месяца');
        $this->assertStringNotContainsString('ui-bar-row__label', $html, 'старые обрезаемые строки не используются');
    }

    public function test_defect_report_without_defects_shows_clear_message(): void
    {
        $this->pgp('Иванов И.', 'Сдан');

        $this->asUser($this->master())->get('/reports/defects')->assertOk()
            ->assertSee('За период брака нет')->assertSee('Проверено записей: 1')->assertDontSee('Главное за период');
    }

    public function test_reports_are_for_master_and_admin_only(): void
    {
        $this->asUser($this->worker())->get('/reports/shift')->assertForbidden();
        $this->asUser($this->worker())->get('/reports/defects')->assertForbidden();
    }
}
