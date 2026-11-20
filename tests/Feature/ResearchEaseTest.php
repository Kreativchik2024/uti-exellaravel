<?php

namespace Tests\Feature;

use App\Models\FaultCodeGroup;
use App\Models\ResearchRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SiteHelpers;
use Tests\TestCase;

/** Облегчения в исследовании: следующий шаг, вкладки технолога, «Отменить», счётчики ремонтника. */
class ResearchEaseTest extends TestCase
{
    use RefreshDatabase, SiteHelpers;

    private function research(array $attrs = []): ResearchRecord
    {
        $r = new ResearchRecord();
        $r->forceFill(array_merge(['record_date' => now()->toDateString(), 'worker1' => 'Иванов И.И.'], $attrs))->save();

        return $r;
    }

    private function block(): FaultCodeGroup
    {
        return FaultCodeGroup::create(['name' => 'КАМЕРА', 'prefix' => 'K', 'sort' => 1]);
    }

    public function test_next_status_and_next_step_are_highlighted(): void
    {
        $master = $this->worker('master');
        $r = $this->research(['repair_status' => 'new']);
        $this->assertSame('in_work', $r->nextStatus());

        $html = $this->asUser($master)->get("/records/research/{$r->id}")->assertOk()->getContent();
        $this->assertStringContainsString('rs-btn--next', $html);

        // Ремонт записан, в работе — дальше «на испытания», в панели кнопка
        $g = $this->block();
        $this->asUser($master)->patch("/records/research/{$r->id}/research-status", ['repair_status' => 'in_work']);
        $this->asUser($master)->patch("/records/research/{$r->id}/research-repair", ['items' => [['repair_action' => 'repair', 'repair_node' => 'g' . $g->id]]]);
        $r->refresh();
        $this->assertSame('conveyor', $r->nextStatus());
        $html = $this->asUser($master)->get("/records/research/{$r->id}")->getContent();
        $this->assertStringContainsString('Отправить на испытания', $html);

        $this->asUser($master)->patch("/records/research/{$r->id}/research-status", ['repair_status' => 'conveyor']);
        $html = $this->asUser($master)->get("/records/research/{$r->id}")->getContent();
        $this->assertStringContainsString('технолог принимает ремонт', $html);
    }

    public function test_technologist_tabs_split_acts_and_acceptance(): void
    {
        $a = $this->research(['repair_action' => 'replacement', 'repair_item' => 'A']);
        $b = $this->research(['repair_action' => 'repair', 'repair_item' => 'B', 'repair_status' => 'conveyor']);
        $this->research(['repair_action' => 'repair', 'repair_item' => 'C', 'repair_status' => 'conveyor']);
        $otk = $this->worker('technologist');

        $html = $this->asUser($otk)->get('/records/research?act=needed')->assertOk()->getContent();
        $this->assertStringContainsString('Нужен акт', $html);
        $this->assertStringContainsString('Ждёт приёмки', $html);

        $this->assertSame(['act' => 1, 'accept' => 2], app(\App\Services\PgpDefectResearch::class)->actsNeededCounts());
        $acts = $this->asUser($otk)->get('/records/research?act=needed&kind=act')->assertOk()->getContent();
        $this->assertStringContainsString("/records/research/{$a->id}\"", $acts);
        $this->assertStringNotContainsString("/records/research/{$b->id}\"", $acts);
    }

    public function test_undo_restores_repair_items_and_status(): void
    {
        $master = $this->worker('master');
        $g = $this->block();
        $r = $this->research();

        $this->asUser($master)->patch("/records/research/{$r->id}/research-repair", ['items' => [['repair_action' => 'replacement', 'repair_node' => 'g' . $g->id]]])
            ->assertSessionHas('undo');
        $html = $this->asUser($master)->get("/records/research/{$r->id}")->getContent();
        $this->assertStringContainsString('Отменить', $html);

        $this->asUser($master)->post("/records/research/{$r->id}/research-undo")->assertSessionHas('ok');
        $r->refresh();
        $this->assertNull($r->repair_action);
        $this->assertCount(0, $r->repairItems);

        // Второй раз — уже нечего отменять
        $this->asUser($master)->post("/records/research/{$r->id}/research-undo")->assertSessionHas('error');

        // Статус
        $this->asUser($master)->patch("/records/research/{$r->id}/research-status", ['repair_status' => 'analysis']);
        $this->assertSame('analysis', $r->fresh()->repair_status);
        $this->asUser($master)->post("/records/research/{$r->id}/research-undo");
        $this->assertSame('new', $r->fresh()->repair_status);

        // Чужое сохранение не отменить
        $this->asUser($master)->patch("/records/research/{$r->id}/research-status", ['repair_status' => 'in_work']);
        $this->asUser($this->worker('repair'))->post("/records/research/{$r->id}/research-undo")->assertSessionHas('error');
        $this->assertSame('in_work', $r->fresh()->repair_status);
    }

    public function test_repairer_menu_counts_by_status(): void
    {
        $pgp = \App\Models\PgpRecord::query()->forceCreate(['record_date' => now()->toDateString(), 'post_number' => 1, 'status' => 'Не сдан', 'board_type' => 'УМН', 'worker1' => 'Иванов И.И.']);
        $this->research(['pgp_record_id' => $pgp->id, 'repair_status' => 'new']);
        $this->research(['pgp_record_id' => $pgp->id, 'repair_status' => 'new']);
        $this->research(['pgp_record_id' => $pgp->id, 'repair_status' => 'in_work']);

        $repair = $this->worker('repair');
        $html = $this->asUser($repair)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('rs=new', $html);
        $this->assertStringContainsString('rs=in_work', $html);

        $list = $this->asUser($repair)->get('/records/research?repair=open&rs=in_work')->assertOk()->getContent();
        $this->assertSame(['in_work' => 1, 'new' => 2], collect(app(\App\Services\PgpDefectResearch::class)->openForRepairByStatus())->sortKeys()->all());
        $this->assertNotEmpty($list);
    }
}
