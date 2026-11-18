<?php

namespace Tests\Feature;

use App\Models\PgpRecord;
use App\Models\ResearchRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SiteHelpers;
use Tests\TestCase;

/** Замена в исследованиях (список + свой вариант), статистика замен и история борта по этапам. */
class ResearchReplacementAndBoardHistoryTest extends TestCase
{
    use RefreshDatabase, SiteHelpers;

    private function research(array $attrs = []): ResearchRecord
    {
        $r = new ResearchRecord();
        $r->forceFill(array_merge(['record_date' => now()->toDateString(), 'worker1' => 'Иванов И.И.'], $attrs))->save();

        return $r;
    }

    private ?\App\Models\User $exec = null;

    /** Запись ПГП через форму; исполнитель один на тест (справочник сотрудников кешируется на тест). */
    private function postPgp(array $override = []): PgpRecord
    {
        $exec = $this->exec ??= $this->worker();
        $this->asUser($exec)->post('/records/pgp', array_merge($this->validRecord('pgp', $this->nameOf($exec)), $override))
            ->assertSessionHasNoErrors();

        return PgpRecord::latest('id')->firstOrFail();
    }

    public function test_replacement_offers_nodes_and_keeps_custom_values(): void
    {
        $exec = $this->worker();
        $html = $this->asUser($exec)->get('/records/research/create')->assertOk()->getContent();
        $this->assertStringContainsString('list="combo-replacement"', $html);
        $this->assertStringContainsString('Без замены', $html);
        $this->assertStringContainsString(config('sheets.map.cross3.title'), $html);

        $data = array_merge($this->validRecord('research', $this->nameOf($exec)), ['replacement' => 'Кабель питания 12В']);
        $this->asUser($exec)->post('/records/research', $data)->assertSessionHasNoErrors();
        $this->assertSame('Кабель питания 12В', ResearchRecord::latest('id')->value('replacement'));

        // Вписанный вариант появляется в списке для следующих записей
        $html = $this->asUser($exec)->get('/records/research/create')->assertOk()->getContent();
        $this->assertStringContainsString('Кабель питания 12В', $html);
    }

    public function test_stats_zone7_counts_replacements(): void
    {
        $this->research(['replacement' => 'Кросс 3', 'category' => 'ПГП (борта)', 'record_date' => '2026-11-10']);
        $this->research(['replacement' => 'кросс  3 ', 'category' => 'Кросс 3', 'record_date' => '2026-11-12']);
        $this->research(['replacement' => 'Камера', 'category' => 'ПГП (борта)', 'record_date' => '2026-11-11']);
        $this->research(['replacement' => 'Без замены', 'record_date' => '2026-11-11']);
        $this->research(['replacement' => null, 'record_date' => '2026-11-11']);

        $rows = $this->asUser($this->worker('master'))->getJson('/stats/zone7?from=2026-11-01&to=2026-11-30')->assertOk()->json();

        $this->assertCount(2, $rows, '«Без замены» и пустые не считаются, разное написание — одна замена');
        $this->assertSame('Кросс 3', $rows[0]['name']);
        $this->assertSame(2, $rows[0]['count']);
        $this->assertSame(['ПГП (борта)' => 1, 'Кросс 3' => 1], $rows[0]['categories']);
        $this->assertSame('2026-11-10', $rows[0]['first_date']);
        $this->assertSame('2026-11-12', $rows[0]['last_date']);
        $this->assertSame('Камера', $rows[1]['name']);

        $html = $this->asUser($this->worker('master'))->get('/stats')->assertOk()->getContent();
        $this->assertStringContainsString('Замены в исследованиях', $html);
    }

    public function test_board_history_shows_stages_in_order(): void
    {
        $repair = $this->worker('repair');
        $pgp = $this->postPgp(['status' => 'Не сдан', 'defect_reason' => 'Нет связи']);
        $research = ResearchRecord::where('pgp_record_id', $pgp->id)->firstOrFail();

        $master = $this->worker('master');
        $html = $this->asUser($master)->get("/records/pgp/{$pgp->id}")->assertOk()->getContent();
        $this->assertStringContainsString('История борта', $html);
        $this->assertStringContainsString("Исследование №{$research->id}: Не обработан", $html);

        $this->travel(5)->minutes();
        $this->asUser($repair)->patch("/records/research/{$research->id}/research-status", ['repair_status' => 'in_work']);
        $this->travel(5)->minutes();
        $this->asUser($repair)->post("/records/research/{$research->id}/comments", ['body' => 'Меняем модуль связи']);
        $this->travel(5)->minutes();
        $this->asUser($repair)->patch("/records/research/{$research->id}/research-status", ['repair_status' => 'conveyor']);

        $html = $this->asUser($master)->get("/records/pgp/{$pgp->id}")->assertOk()->getContent();
        $this->assertStringContainsString("Исследование №{$research->id}: Отправлен в конвейер", $html);
        // Порядок — внутри ленты (в заголовке «Сейчас: …» тот же текст)
        $timeline = mb_substr($html, mb_strpos($html, '<ol'));
        $order = array_map(fn ($t) => mb_strpos($timeline, $t), [
            'Проверка на ПГП: Не сдан',
            "Исследование №{$research->id} заведено",
            "Исследование №{$research->id}: В работе",
            'Меняем модуль связи',
            "Исследование №{$research->id}: Отправлен в конвейер",
        ]);
        $this->assertNotContains(false, $order, 'все этапы есть в истории');
        $sorted = $order;
        sort($sorted);
        $this->assertSame($sorted, $order, 'этапы идут по порядку');

        // Борт вернулся на ПГП и сдан — текущий этап «Сдан на ПГП», история общая по номеру борта
        $this->travel(5)->minutes();
        $again = $this->postPgp(['status' => 'Сдан']);
        $this->assertSame($pgp->board_number, $again->board_number);
        $html = $this->asUser($master)->get("/records/pgp/{$again->id}")->assertOk()->getContent();
        $this->assertStringContainsString('Сдан на ПГП', $html);
        $this->assertStringContainsString('Проверка на ПГП: Не сдан', $html);
        $this->assertStringContainsString('Меняем модуль связи', $html);
    }
}
