<?php

namespace Tests\Feature;

use App\Models\PgpRecord;
use App\Models\ResearchRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SiteHelpers;
use Tests\TestCase;

/**
 * Борт после ремонта возвращается на ПГП: его запись правит любой исполнитель (не только создатель, без лимита 24 ч)
 * и ставит «Сдан» — в статистике борт считается один раз, как сданный.
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

    public function test_any_executor_rechecks_returned_board_and_sets_delivered(): void
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
        $this->assertStringContainsString("/records/pgp/{$pgp->id}/edit", $card);

        // В списке — вкладка «Вернулись после ремонта» с числом и метка «После ремонта: проверить»
        $list = $this->asUser($other)->get('/records/pgp')->getContent();
        $this->assertStringContainsString('Вернулись после ремонта', $list);
        $this->assertStringContainsString('После ремонта: проверить', $list);

        // Другой исполнитель правит (несмотря на 3 дня) и ставит «Сдан»
        $this->assertStringContainsString('Борт вернулся после ремонта', $this->asUser($other)->get("/records/pgp/{$pgp->id}/edit")->assertOk()->getContent());
        $oldDate = $pgp->record_date->toDateString();
        $data = array_merge($this->validRecord('pgp', $pgp->worker1), ['status' => 'Сдан', 'technologist' => $this->technologistName(),
            'record_date' => $oldDate]);
        $this->asUser($other)->put("/records/pgp/{$pgp->id}", $data)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('Сдан', $pgp->fresh()->status);
        // Дата — день фактической сдачи, а не день ухода в ремонт
        $this->assertSame(now()->toDateString(), $pgp->fresh()->record_date->toDateString());

        // В списке «Были в ремонте» — с меткой «после ремонта»
        $repaired = $this->asUser($other)->get('/records/pgp?repaired=1')->getContent();
        $this->assertStringContainsString("/records/pgp/{$pgp->id}\"", $repaired);
        $this->assertStringContainsString('после ремонта', $repaired);

        // Статистика: один борт, сданный, без брака
        $this->actingAs($this->worker('master'));
        $types = app(\App\Services\StatsService::class)->boardTypes(now()->subDays(10), now(), null);
        $this->assertSame(1, $types['total']);
        $this->assertSame(1, $types['delivered']);
        $this->assertSame(0, app(\App\Services\PgpDefectResearch::class)->returnedFromRepairCount());

        // Сдан — больше не «после ремонта»: чужой исполнитель снова не правит
        $this->asUser($other)->get("/records/pgp/{$pgp->id}/edit")->assertRedirect();
    }
}
