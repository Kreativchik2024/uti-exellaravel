<?php

namespace Tests\Feature;

use App\Models\PgpRecord;
use App\Models\ResearchRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SiteHelpers;
use Tests\TestCase;

/**
 * Борт после ремонта возвращается на ПГП: он в списке «Вернулись после ремонта», проверяют его НОВОЙ записью
 * (связана с исследованием), первая запись остаётся «Не сдан». В статистике: не сдан + сдан, из сданных — после ремонта.
 */
class BoardReturnAfterRepairTest extends TestCase
{
    use RefreshDatabase, SiteHelpers;

    /** Запись ПГП «Не сдан» от исполнителя $author, заведена 3 дня назад, с исследованием. */
    private function defectBoard($author): array
    {
        $data = array_merge($this->validRecord('pgp', $this->nameOf($author)), ['status' => 'Не сдан', 'technologist' => null, 'created_by' => $author->id,
            'record_date' => now()->subDays(3)->toDateString()]);
        $pgp = new PgpRecord();
        $pgp->forceFill($data)->save();
        $pgp->forceFill(['created_at' => now()->subDays(3)])->saveQuietly();
        app(\App\Services\PgpDefectResearch::class)->syncFor($pgp);

        return [$pgp, ResearchRecord::where('pgp_record_id', $pgp->id)->firstOrFail()];
    }

    public function test_returned_board_is_listed_and_rechecked_by_new_record(): void
    {
        $author = $this->worker('executor', 'Автор А.А.');
        $other  = $this->worker('executor', 'Другой Д.Д.');
        [$pgp, $research] = $this->defectBoard($author);

        // Пока в ремонте — чужой исполнитель править не может (и запись старше суток)
        $this->asUser($other)->get("/records/pgp/{$pgp->id}/edit")->assertRedirect();
        $this->assertFalse($pgp->fresh()->returnedFromRepair());

        // Ремонтник отправил в конвейер — борт вернулся на ПГП
        $research->forceFill(['repair_status' => 'conveyor'])->save();
        $this->assertTrue($pgp->fresh()->returnedFromRepair());

        // В меню и списке — «вернулись после ремонта», на карточке — кнопка перепроверки
        $this->actingAs($other);
        $this->assertSame(1, app(\App\Services\PgpDefectResearch::class)->returnedFromRepairCount());
        $this->assertStringContainsString("/records/pgp/{$pgp->id}\"", $this->asUser($other)->get('/records/pgp?returned=1')->getContent());
        $card = $this->asUser($other)->get("/records/pgp/{$pgp->id}")->assertOk()->getContent();
        $this->assertStringContainsString('Вернулся после ремонта', $card);

        // В списке — вкладка «Вернулись после ремонта» с числом и метка «После ремонта: проверить»
        $list = $this->asUser($other)->get('/records/pgp')->getContent();
        $this->assertStringContainsString('Вернулись после ремонта', $list);
        $this->assertStringContainsString('После ремонта: проверить', $list);

        // Старую запись не правят: повторная проверка — новой записью с карточки
        $this->asUser($other)->get("/records/pgp/{$pgp->id}/edit")->assertRedirect();
        $this->assertStringContainsString("/records/pgp/create?from={$pgp->id}", $card);
        $this->asUser($other)->post('/records/pgp', array_merge($this->validRecord('pgp', $this->nameOf($other)),
            ['status' => 'Сдан', 'fuselage_number' => $pgp->fuselage_number]))->assertSessionHasNoErrors();
        $recheck = \App\Models\PgpRecord::latest('id')->first();
        $this->assertSame($research->id, $recheck->after_research_id);
        $this->assertSame('Не сдан', $pgp->fresh()->status);

        // «Были в ремонте» — первая запись с пометкой «перепроверен»
        $repaired = $this->asUser($other)->get('/records/pgp?repaired=1')->getContent();
        $this->assertStringContainsString("/records/pgp/{$pgp->id}\"", $repaired);
        $this->assertStringContainsString('перепроверен', $repaired);

        // Статистика как есть: первая проверка — «не сдан», повторная — «сдан», из сданных — «после ремонта»
        $this->actingAs($this->worker('master'));
        $types = app(\App\Services\StatsService::class)->boardTypes(now()->subDays(10), now(), null);
        $this->assertSame(2, $types['total']);
        $this->assertSame(1, $types['delivered']);
        $this->assertSame(1, $types['after_repair']);
        $this->assertSame(0, app(\App\Services\PgpDefectResearch::class)->returnedFromRepairCount());
    }
}
