<?php

namespace Tests\Feature;

use App\Models\PgpRecord;
use App\Models\ResearchRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SiteHelpers;
use Tests\TestCase;

/**
 * Цепочка документов борта: 1) проверка «Не сдан» → 2) исследование (ремонт) → 3) повторная проверка —
 * НОВАЯ запись ПГП, связанная с ремонтом → 4) «Сдан» (или снова в ремонт). Старая запись не переписывается.
 * Борт в ремонте или уже сданный по тому же фюзеляжу новой записью не заводится.
 */
class PgpBoardDedupTest extends TestCase
{
    use RefreshDatabase, SiteHelpers;

    /** Запись ПГП «Не сдан» с исследованием, заведена 3 дня назад автором $author. */
    private function defectBoard($author, string $fuselage = 'F-100'): array
    {
        $data = array_merge($this->validRecord('pgp', $this->nameOf($author)), ['status' => 'Не сдан', 'technologist' => null,
            'created_by' => $author->id, 'fuselage_number' => $fuselage, 'board_number' => 'RA-77', 'frame_serial' => 'M-555',
            'record_date' => now()->subDays(3)->toDateString()]);
        $pgp = new PgpRecord();
        $pgp->forceFill($data)->save();
        $pgp->forceFill(['created_at' => now()->subDays(3)])->saveQuietly();
        app(\App\Services\PgpDefectResearch::class)->syncFor($pgp);

        return [$pgp, ResearchRecord::where('pgp_record_id', $pgp->id)->firstOrFail()];
    }

    private function delivery($user, string $fuselage, array $extra = []): array
    {
        return array_merge($this->validRecord('pgp', $this->nameOf($user)), ['status' => 'Сдан', 'fuselage_number' => $fuselage], $extra);
    }

    public function test_recheck_after_repair_is_a_new_linked_document(): void
    {
        $author = $this->worker('executor', 'Автор А.А.');
        $other  = $this->worker('executor', 'Другой Д.Д.');
        [$pgp, $research] = $this->defectBoard($author);
        $research->forceFill(['repair_status' => 'conveyor'])->save();
        $this->actingAs($other);
        $this->assertSame(1, app(\App\Services\PgpDefectResearch::class)->returnedFromRepairCount());

        // Старую запись чужой исполнитель не правит — проверка новой записью
        $this->asUser($other)->get("/records/pgp/{$pgp->id}/edit")->assertRedirect();
        $card = $this->asUser($other)->get("/records/pgp/{$pgp->id}")->assertOk()->getContent();
        $this->assertStringContainsString('Повторная проверка на ПГП', $card);

        // Форма повторной проверки: данные борта подставлены из прошлой записи
        $form = $this->asUser($other)->get("/records/pgp/create?from={$pgp->id}")->assertOk()->getContent();
        $this->assertStringContainsString('value="M-555"', $form);

        // Подсказка на форме по фюзеляжу
        $hint = $this->asUser($other)->get('/records/pgp/board-journal?fuselage_number=F-100')->getContent();
        $this->assertStringContainsString('Повторная проверка после ремонта', $hint);
        $this->assertStringContainsString('data-board-prefill', $hint);

        $this->asUser($other)->post('/records/pgp', $this->delivery($other, ' F-100 '))
            ->assertSessionHasNoErrors()->assertSessionHas('ok', fn ($m) => str_contains($m, "исследование №{$research->id}"));

        $this->assertSame(2, PgpRecord::count(), 'новый документ');
        $recheck = PgpRecord::latest('id')->first();
        $this->assertSame($research->id, $recheck->after_research_id);
        $this->assertSame('Сдан', $recheck->status);
        $this->assertSame('Не сдан', $pgp->fresh()->status, 'первая проверка осталась как была');

        // Борт ушёл из «Вернулись после ремонта», первая запись — «перепроверен», новая — «после ремонта»
        $this->assertSame(0, app(\App\Services\PgpDefectResearch::class)->returnedFromRepairCount());
        $list = $this->asUser($other)->get('/records/pgp')->getContent();
        $this->assertStringContainsString('перепроверен', $list);
        $this->assertStringContainsString('после ремонта', $list);

        // Документы связаны: на карточке исследования — повторная проверка, в журнале — её этап
        $this->assertStringContainsString("/records/pgp/{$recheck->id}\"", $this->asUser($other)->get("/records/research/{$research->id}")->getContent());
        $this->assertStringContainsString("Повторная проверка на ПГП после исследования №{$research->id}: Сдан",
            $this->asUser($other)->get("/records/pgp/{$recheck->id}")->getContent());

        // Теперь борт сдан — ещё одну запись не завести
        $this->asUser($other)->post('/records/pgp', $this->delivery($other, 'F-100'))->assertSessionHasErrors('fuselage_number');
        $this->assertSame(2, PgpRecord::count());
    }

    public function test_recheck_failed_again_goes_to_new_repair(): void
    {
        $author = $this->worker('executor', 'Автор А.А.');
        [, $research] = $this->defectBoard($author);
        $research->forceFill(['repair_status' => 'conveyor'])->save();

        $this->asUser($author)->post('/records/pgp', $this->delivery($author, 'F-100', ['status' => 'Не сдан', 'technologist' => null, 'defect_reason' => 'Снова не взлетел']))
            ->assertSessionHasNoErrors();
        $recheck = PgpRecord::latest('id')->first();
        $this->assertSame($research->id, $recheck->after_research_id);
        $this->assertSame(0, $recheck->researches()->count(), 'само исследование не заводится');
        $this->asUser($author)->post("/records/pgp/{$recheck->id}/start-research")->assertRedirect();
        $this->assertSame(1, $recheck->researches()->count(), 'по повторной проверке — новый ремонт');

        // Пока новый ремонт идёт — новой записи по борту нет
        $this->asUser($author)->post('/records/pgp', $this->delivery($author, 'F-100'))
            ->assertSessionHasErrors('fuselage_number');
        $this->assertStringContainsString('числится в ремонте', session('errors')->first('fuselage_number'));
    }

    public function test_board_in_repair_or_delivered_is_not_entered_again(): void
    {
        $author = $this->worker('executor', 'Автор А.А.');
        [$pgp] = $this->defectBoard($author);

        $this->asUser($author)->post('/records/pgp', $this->delivery($author, 'F-100'))->assertSessionHasErrors('fuselage_number');
        $hint = $this->asUser($author)->get('/records/pgp/board-journal?fuselage_number=F-100')->getContent();
        $this->assertStringContainsString('Новую запись по этому борту сейчас не завести', $hint);

        $pgp->forceFill(['status' => 'Сдан', 'technologist' => $this->technologistName()])->save();
        $this->asUser($author)->post('/records/pgp', $this->delivery($author, 'F-100'))
            ->assertSessionHasErrors('fuselage_number');
        $this->assertStringContainsString('уже сдан', session('errors')->first('fuselage_number'));
        $this->assertSame(1, PgpRecord::count());

        // Новый фюзеляж — обычная запись; на правке самой записи подсказки нет
        $this->asUser($author)->post('/records/pgp', $this->delivery($author, 'F-200'))->assertSessionHasNoErrors();
        $this->assertSame(2, PgpRecord::count());
        $this->assertStringNotContainsString('Открыть запись', $this->asUser($author)->get("/records/pgp/board-journal?fuselage_number=F-100&except={$pgp->id}")->getContent());
    }

    public function test_stats_show_delivered_after_repair_subcategory(): void
    {
        $author = $this->worker('executor', 'Автор А.А.');
        [, $research] = $this->defectBoard($author);
        $research->forceFill(['repair_status' => 'conveyor'])->save();
        $this->asUser($author)->post('/records/pgp', $this->delivery($author, 'F-100'))->assertSessionHasNoErrors();
        $this->asUser($author)->post('/records/pgp', $this->delivery($author, 'F-300'))->assertSessionHasNoErrors();

        $master = $this->worker('master');
        $period = 'from=' . now()->subDays(10)->toDateString() . '&to=' . now()->toDateString();
        $html = $this->asUser($master)->get("/stats?{$period}")->assertOk()->getContent();
        $this->assertStringContainsString('в т.ч. после ремонта: 1', $html);

        $list = $this->asUser($master)->get("/stats/records?sheet=pgp&kind=repaired&{$period}")->assertOk()->getContent();
        $recheck = PgpRecord::where('after_research_id', $research->id)->firstOrFail();
        $this->assertStringContainsString("/records/pgp/{$recheck->id}", $list);
        $this->assertStringNotContainsString('F-300', $list);
    }
}
