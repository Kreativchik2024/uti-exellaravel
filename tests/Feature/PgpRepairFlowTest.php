<?php

namespace Tests\Feature;

use App\Models\PgpRecord;
use App\Models\ResearchRecord;
use App\Services\PgpDefectResearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SiteHelpers;
use Tests\TestCase;

/** Брак ПГП → исследование → ремонтник; комментарии и история детали; «Отремонтировано» зелёным. */
class PgpRepairFlowTest extends TestCase
{
    use RefreshDatabase, SiteHelpers;

    /** Запись ПГП через форму, как её вводят на участке. */
    private function postPgp(array $override = []): PgpRecord
    {
        $exec = $this->worker();
        $data = array_merge($this->validRecord('pgp', $this->nameOf($exec)), $override);
        $this->asUser($exec)->post('/records/pgp', $data)->assertSessionHasNoErrors();

        return PgpRecord::latest('id')->firstOrFail();
    }

    private function research(array $attrs = []): ResearchRecord
    {
        $r = new ResearchRecord();
        $r->forceFill(array_merge(['record_date' => now()->toDateString(), 'worker1' => 'Иванов И.И.', 'is_repaired' => false], $attrs))->save();

        return $r;
    }

    public function test_pgp_defect_creates_research_once(): void
    {
        $pgp = $this->postPgp(['status' => 'Не сдан', 'defect_reason' => 'Нет связи с модулем', 'frame_serial' => 'FR-77']);

        $research = ResearchRecord::where('pgp_record_id', $pgp->id)->first();
        $this->assertNotNull($research, 'по браку ПГП должно появиться исследование');
        $this->assertStringContainsString('Нет связи с модулем', $research->reported_defect);
        $this->assertSame('FR-77', $research->frame_serial);
        $this->assertFalse($research->is_repaired);

        // Повторное сохранение той же записи не плодит исследования
        app(PgpDefectResearch::class)->syncFor($pgp);
        $this->assertSame(1, ResearchRecord::where('pgp_record_id', $pgp->id)->count());
    }

    public function test_pgp_without_defect_creates_no_research(): void
    {
        $this->postPgp(['status' => 'Сдан']);

        $this->assertSame(0, ResearchRecord::count());
    }

    public function test_repair_role_sees_open_defects_until_marked_repaired(): void
    {
        $pgp = $this->postPgp(['status' => 'Не сдан']);
        $research = ResearchRecord::where('pgp_record_id', $pgp->id)->firstOrFail();
        $repair = $this->worker('repair');

        $html = $this->asUser($repair)->get('/records/research?repair=open')->assertOk()->getContent();
        $this->assertStringContainsString("/records/research/{$research->id}\"", $html);

        $this->actingAs($repair);
        $this->assertSame(1, app(PgpDefectResearch::class)->openForRepairCount());

        // Промежуточные статусы — ещё в списке ремонтника
        foreach (['in_work', 'analysis'] as $status) {
            $this->asUser($repair)->patch("/records/research/{$research->id}/research-status", ['repair_status' => $status])->assertRedirect();
            $this->assertSame($status, $research->fresh()->repair_status);
            $this->assertFalse($research->fresh()->is_repaired);
        }

        $this->asUser($repair)->patch("/records/research/{$research->id}/research-status", ['repair_status' => 'conveyor'])->assertRedirect();
        $this->assertTrue($research->fresh()->is_repaired);

        $this->actingAs($repair);
        $this->assertSame(0, app(PgpDefectResearch::class)->openForRepairCount());
        $html = $this->asUser($repair)->get('/records/research?repair=open')->assertOk()->getContent();
        $this->assertStringNotContainsString("/records/research/{$research->id}\"", $html);
    }

    public function test_only_repair_master_admin_can_change_status(): void
    {
        $pgp = $this->postPgp(['status' => 'Не сдан']);
        $research = ResearchRecord::where('pgp_record_id', $pgp->id)->firstOrFail();

        foreach (['executor', 'otk'] as $role) {
            $this->asUser($this->worker($role))->patch("/records/research/{$research->id}/research-status", ['repair_status' => 'conveyor'])->assertForbidden();
        }
        $this->assertSame('new', $research->fresh()->repair_status);

        $this->asUser($this->worker('master'))->patch("/records/research/{$research->id}/research-status", ['repair_status' => 'bogus'])
            ->assertSessionHasErrors('repair_status');
        $this->assertSame('new', $research->fresh()->repair_status);
    }

    public function test_status_buttons_and_colors_on_research_card(): void
    {
        $research = $this->research(['repair_status' => 'analysis', 'category' => 'Кросс 3']);

        $html = $this->asUser($this->worker('repair'))->get("/records/research/{$research->id}")->assertOk()->getContent();
        foreach (['Не обработан' => 'bad', 'В работе' => 'neutral', 'Отправлен в разбор' => 'warn', 'Отправлен в конвейер' => 'good'] as $label => $tone) {
            $this->assertStringContainsString($label, $html);
            $this->assertStringContainsString("ui-pill--{$tone} rs-btn", $html);
        }
        $this->assertStringContainsString('rs-btn--current', $html);
        $this->assertStringContainsString('Кросс 3', $html);

        // Исполнитель видит метку статуса, но без кнопок
        $plain = $this->asUser($this->worker())->get("/records/research/{$research->id}")->assertOk()->getContent();
        $this->assertStringContainsString('Отправлен в разбор', $plain);
        $this->assertStringNotContainsString('rs-btn', $plain);
    }

    public function test_research_category_lists_all_nodes_and_accepts_custom(): void
    {
        $options = config('sheets.map.research.fields.category.options');
        $this->assertContains(config('sheets.map.pgp.title'), $options);
        $this->assertContains(config('sheets.map.cross3.title'), $options);
        $this->assertNotContains(config('sheets.map.research.title'), $options);

        $html = $this->asUser($this->worker())->get('/records/research/create')->assertOk()->getContent();
        $this->assertStringContainsString('list="combo-category"', $html);
        $this->assertStringContainsString(config('sheets.map.cross3.title'), $html);

        $exec = $this->worker();
        $data = array_merge($this->validRecord('research', $this->nameOf($exec)), ['category' => 'Свой узел — кабель питания', 'repair_status' => 'in_work']);
        $this->asUser($exec)->post('/records/research', $data)->assertSessionHasNoErrors();
        $saved = ResearchRecord::latest('id')->firstOrFail();
        $this->assertSame('Свой узел — кабель питания', $saved->category);
        $this->assertSame('in_work', $saved->repair_status);
        $this->assertFalse($saved->is_repaired);
    }

    public function test_repaired_pgp_is_shown_green(): void
    {
        $pgp = $this->postPgp(['status' => 'Не сдан']);
        $research = ResearchRecord::where('pgp_record_id', $pgp->id)->firstOrFail();
        $research->forceFill(['repair_status' => 'conveyor'])->save();
        $master = $this->worker('master');

        $show = $this->asUser($master)->get("/records/pgp/{$pgp->id}")->assertOk()->getContent();
        $this->assertStringContainsString('Отремонтировано', $show);
        $this->assertStringContainsString('ui-pill--good', $show);

        $list = $this->asUser($master)->get('/records/pgp')->assertOk()->getContent();
        $this->assertStringContainsString('Отремонтировано', $list);
    }

    public function test_research_keeps_many_comments_and_shows_part_history(): void
    {
        $first = $this->research(['frame_serial' => 'FR-1', 'reported_defect' => 'Первый возврат', 'is_repaired' => true]);
        $second = $this->research(['frame_serial' => 'FR-1', 'reported_defect' => 'Второй возврат']);
        $other = $this->research(['frame_serial' => 'FR-2', 'reported_defect' => 'Чужая деталь']);
        $user = $this->worker('repair');

        $this->asUser($user)->post("/records/research/{$first->id}/comments", ['body' => 'Заменён разъём'])->assertRedirect();
        $this->asUser($user)->post("/records/research/{$second->id}/comments", ['body' => 'Снова отказ связи'])->assertRedirect();
        $this->asUser($user)->post("/records/research/{$second->id}/comments", ['body' => 'Меняем кабель'])->assertRedirect();
        $this->assertSame(2, $second->comments()->count());

        $html = $this->asUser($user)->get("/records/research/{$second->id}")->assertOk()->getContent();
        foreach (['Первый возврат', 'Заменён разъём', 'Снова отказ связи', 'Меняем кабель'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringNotContainsString('Чужая деталь', $html);
        $this->assertStringContainsString("/records/research/{$first->id}\"", $html);
        unset($other);
    }

    public function test_empty_comment_is_rejected(): void
    {
        $research = $this->research();

        $this->asUser($this->worker())->post("/records/research/{$research->id}/comments", ['body' => '  '])
            ->assertSessionHasErrors('body');
        $this->assertSame(0, $research->comments()->count());
    }
}
