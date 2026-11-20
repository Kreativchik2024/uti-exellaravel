<?php

namespace Tests\Feature;

use App\Models\PgpRecord;
use App\Models\ResearchRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SiteHelpers;
use Tests\TestCase;

/**
 * Сдача на ПГП: борт с уже известным номером фюзеляжа не дублируется — открывается его запись
 * (после ремонта — на правку с введёнными данными; в ремонте и сданный — карточка).
 */
class PgpBoardDedupTest extends TestCase
{
    use RefreshDatabase, SiteHelpers;

    /** Запись ПГП «Не сдан» с исследованием, заведена 3 дня назад автором $author. */
    private function defectBoard($author, string $fuselage = 'F-100'): array
    {
        $data = array_merge($this->validRecord('pgp', $this->nameOf($author)), ['status' => 'Не сдан', 'technologist' => null,
            'created_by' => $author->id, 'fuselage_number' => $fuselage, 'record_date' => now()->subDays(3)->toDateString()]);
        $pgp = new PgpRecord();
        $pgp->forceFill($data)->save();
        $pgp->forceFill(['created_at' => now()->subDays(3)])->saveQuietly();
        app(\App\Services\PgpDefectResearch::class)->syncFor($pgp);

        return [$pgp, ResearchRecord::where('pgp_record_id', $pgp->id)->firstOrFail()];
    }

    private function newDelivery($user, string $fuselage, array $extra = []): array
    {
        return array_merge($this->validRecord('pgp', $this->nameOf($user)), ['status' => 'Сдан', 'fuselage_number' => $fuselage], $extra);
    }

    public function test_returned_board_opens_its_record_instead_of_duplicate(): void
    {
        $author = $this->worker('executor', 'Автор А.А.');
        $other  = $this->worker('executor', 'Другой Д.Д.');
        [$pgp, $research] = $this->defectBoard($author);
        $research->forceFill(['repair_status' => 'conveyor'])->save();

        $this->asUser($other)->post('/records/pgp', $this->newDelivery($other, ' F-100 ', ['actions_taken' => 'Перепроверил']))
            ->assertRedirect("/records/pgp/{$pgp->id}/edit")
            ->assertSessionHas('_old_input.actions_taken', 'Перепроверил');
        $this->assertSame(1, PgpRecord::count(), 'дубль не создан');

        // На форме правки — введённое на сдаче, сохраняем «Сдан» — борт уходит из «Вернулись после ремонта»
        $this->asUser($other)->put("/records/pgp/{$pgp->id}", array_merge($this->validRecord('pgp', $this->nameOf($other)), ['fuselage_number' => 'F-100'],
            ['status' => 'Сдан', 'technologist' => $this->technologistName(), 'worker1' => $this->nameOf($other), 'record_date' => now()->toDateString()]))
            ->assertSessionHasNoErrors();
        $this->assertSame('Сдан', $pgp->fresh()->status);
        $this->actingAs($other);
        $this->assertSame(0, app(\App\Services\PgpDefectResearch::class)->returnedFromRepairCount());
    }

    public function test_board_still_in_repair_or_delivered_opens_card_without_duplicate(): void
    {
        $author = $this->worker('executor', 'Автор А.А.');
        $other  = $this->worker('executor', 'Другой Д.Д.');
        [$pgp] = $this->defectBoard($author);

        $this->asUser($other)->post('/records/pgp', $this->newDelivery($other, 'F-100'))
            ->assertRedirect("/records/pgp/{$pgp->id}")->assertSessionHas('ok', fn ($m) => str_contains($m, 'числится в ремонте'));

        $pgp->forceFill(['status' => 'Сдан', 'technologist' => $this->technologistName()])->save();
        $this->asUser($other)->post('/records/pgp', $this->newDelivery($other, 'F-100'))
            ->assertRedirect("/records/pgp/{$pgp->id}")->assertSessionHas('ok', fn ($m) => str_contains($m, 'уже сдан'));

        $this->assertSame(1, PgpRecord::count());
    }

    public function test_new_fuselage_creates_record_and_form_hints_existing_board(): void
    {
        $author = $this->worker('executor', 'Автор А.А.');
        [$pgp, $research] = $this->defectBoard($author);
        $research->forceFill(['repair_status' => 'conveyor'])->save();

        $this->asUser($author)->post('/records/pgp', $this->newDelivery($author, 'F-200'))->assertSessionHasNoErrors();
        $this->assertSame(2, PgpRecord::count());

        $html = $this->asUser($author)->get('/records/pgp/board-journal?fuselage_number=F-100')->assertOk()->getContent();
        $this->assertStringContainsString('Этот борт уже есть', $html);
        $this->assertStringContainsString('name="open_existing"', $html);

        // На правке самой записи подсказки нет
        $html = $this->asUser($author)->get("/records/pgp/board-journal?fuselage_number=F-100&except={$pgp->id}")->getContent();
        $this->assertStringNotContainsString('уже есть', $html);
    }
}
