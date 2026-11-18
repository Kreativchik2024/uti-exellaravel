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

    /** Дерево блоков, как в справочнике кодов: МОТОРАМА → Кросс 3 → МИНИ ПК. @return array{0:\App\Models\FaultCodeGroup,1:\App\Models\FaultCodeGroup,2:\App\Models\FaultCodeGroup} */
    private function blocks(): array
    {
        $top  = \App\Models\FaultCodeGroup::create(['name' => 'МОТОРАМА', 'prefix' => 'M', 'sort' => 1]);
        $mid  = \App\Models\FaultCodeGroup::create(['name' => 'Кросс 3', 'prefix' => 'C3', 'sort' => 1, 'parent_id' => $top->id]);
        $leaf = \App\Models\FaultCodeGroup::create(['name' => 'МИНИ ПК', 'prefix' => 'C3.1', 'sort' => 1, 'parent_id' => $mid->id]);

        return [$top, $mid, $leaf];
    }

    private function repairPatch(\App\Models\User $u, ResearchRecord $r, array $data)
    {
        return $this->asUser($u)->patch("/records/research/{$r->id}/research-repair", $data);
    }

    public function test_repairer_picks_repair_or_replacement_and_block_from_code_tree(): void
    {
        [, , $leaf] = $this->blocks();
        $pgp = $this->postPgp(['status' => 'Не сдан']);
        $research = ResearchRecord::where('pgp_record_id', $pgp->id)->firstOrFail();
        $repair = $this->worker('repair');

        // На карточке — панель «Ремонт» с деревом блоков
        $html = $this->asUser($repair)->get("/records/research/{$research->id}")->assertOk()->getContent();
        $this->assertStringContainsString('repairPicker(', $html);
        $this->assertStringContainsString('МИНИ ПК', $html);
        $this->assertStringContainsString('name="repair_action"', $html);

        // Ремонт — акт не нужен
        $this->repairPatch($repair, $research, ['repair_action' => 'repair', 'repair_node' => 'g' . $leaf->id])->assertSessionHasNoErrors();
        $research->refresh();
        $this->assertSame('repair', $research->repair_action);
        $this->assertSame('МОТОРАМА · Кросс 3 · МИНИ ПК', $research->repair_item);
        $this->assertFalse($research->needsAct());

        // Замена — технологу нужен акт
        $this->repairPatch($repair, $research, ['repair_action' => 'replacement', 'repair_node' => 'g' . $leaf->id])->assertSessionHasNoErrors();
        $this->assertTrue($research->fresh()->needsAct());

        // Без выбора узла и с чужим узлом — ошибка
        $this->repairPatch($repair, $research, ['repair_action' => 'replacement'])->assertSessionHasErrors('repair_node');
        $this->repairPatch($repair, $research, ['repair_action' => 'replacement', 'repair_node' => 'g999999'])->assertSessionHasErrors('repair_node');

        // Исполнитель и ОТК ремонт не указывают
        foreach (['executor', 'otk'] as $role) {
            $this->repairPatch($this->worker($role), $research, ['repair_action' => 'repair', 'repair_node' => 'g' . $leaf->id])->assertForbidden();
        }
    }

    public function test_replacement_puts_pgp_into_otk_act_list_until_act_done(): void
    {
        [, , $leaf] = $this->blocks();
        $pgp = $this->postPgp(['status' => 'Не сдан']);
        $research = ResearchRecord::where('pgp_record_id', $pgp->id)->firstOrFail();
        $this->repairPatch($this->worker('repair'), $research, ['repair_action' => 'replacement', 'repair_node' => 'g' . $leaf->id]);
        $otk = $this->worker('otk');

        // В меню ОТК — строка со списком, в списке — это исследование
        $menu = $this->asUser($otk)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('ПГП: нужен акт на замену', $menu);
        $list = $this->asUser($otk)->get('/records/research?act=needed')->assertOk()->getContent();
        $this->assertStringContainsString("/records/research/{$research->id}\"", $list);
        $this->assertStringContainsString('нужен акт', $list);
        $this->assertStringContainsString('Нужен акт', $this->asUser($otk)->get("/records/research/{$research->id}")->getContent());

        // Ремонтник акт не отмечает, ОТК — отмечает
        $this->asUser($this->worker('repair'))->patch("/records/research/{$research->id}/research-act")->assertForbidden();
        $this->asUser($otk)->patch("/records/research/{$research->id}/research-act")->assertRedirect();
        $this->assertNotNull($research->fresh()->act_done_at);

        $this->actingAs($otk);
        $this->assertSame(0, app(\App\Services\PgpDefectResearch::class)->actsNeededCount());
        $list = $this->asUser($otk)->get('/records/research?act=needed')->assertOk()->getContent();
        $this->assertStringNotContainsString("/records/research/{$research->id}\"", $list);

        // В истории борта — замена и акт
        $history = $this->asUser($this->worker('master'))->get("/records/pgp/{$pgp->id}")->getContent();
        $this->assertStringContainsString("Исследование №{$research->id}: замена", $history);
        $this->assertStringContainsString("Исследование №{$research->id}: акт на замену оформлен", $history);
    }

    public function test_custom_variant_goes_to_admin_review(): void
    {
        [, $mid] = $this->blocks();
        $pgp = $this->postPgp(['status' => 'Не сдан']);
        $research = ResearchRecord::where('pgp_record_id', $pgp->id)->firstOrFail();
        $other = $this->research();
        $repair = $this->worker('repair');

        $this->repairPatch($repair, $research, ['repair_action' => 'replacement', 'custom_name' => 'разъём питания', 'custom_group' => $mid->id])
            ->assertSessionHasNoErrors();
        $item = \App\Models\RepairItem::firstOrFail();
        $this->assertFalse($item->is_approved);
        $this->assertSame('Разъём питания', $item->name);
        $this->assertSame('МОТОРАМА · Кросс 3 · Разъём питания', $research->fresh()->repair_item);

        // До проверки: в этом исследовании — с пометкой, в других — нет в списке, выбрать нельзя
        $this->assertStringContainsString('на проверке', $this->asUser($repair)->get("/records/research/{$research->id}")->getContent());
        $this->assertSame([], app(\App\Services\RepairCatalog::class)->pickerData($other)['items']);
        $this->repairPatch($repair, $other, ['repair_action' => 'repair', 'repair_node' => 'i' . $item->id])->assertSessionHasErrors('repair_node');

        // Повторное предложение того же — без дубля
        $this->repairPatch($repair, $other, ['repair_action' => 'repair', 'custom_name' => 'Разъём  питания', 'custom_group' => $mid->id]);
        $this->assertSame(1, \App\Models\RepairItem::count());

        // Администратор видит и подтверждает — вариант доступен всем
        $admin = $this->worker('admin');
        $this->asUser($admin)->get('/repair-items')->assertOk()->assertSee('Разъём питания')->assertSee('Ждут проверки · 1');
        $this->asUser($repair)->get('/repair-items')->assertForbidden();
        $this->asUser($admin)->patch("/repair-items/{$item->id}/approve", ['name' => 'Разъём питания 12В'])->assertRedirect();
        $this->assertTrue($item->fresh()->is_approved);
        $this->assertSame('МОТОРАМА · Кросс 3 · Разъём питания 12В', $research->fresh()->repair_item);
        $this->repairPatch($repair, $other, ['repair_action' => 'repair', 'repair_node' => 'i' . $item->id])->assertSessionHasNoErrors();
    }

    public function test_rejected_variant_is_removed_but_text_stays(): void
    {
        $research = $this->research();
        $this->repairPatch($this->worker('repair'), $research, ['repair_action' => 'repair', 'custom_name' => 'Непонятный узел']);
        $item = \App\Models\RepairItem::firstOrFail();

        $this->asUser($this->worker('admin'))->delete("/repair-items/{$item->id}")->assertRedirect();
        $this->assertSame(0, \App\Models\RepairItem::count());
        $research->refresh();
        $this->assertNull($research->repair_item_id);
        $this->assertSame('Непонятный узел', $research->repair_item);
    }

    public function test_stats_zone7_counts_repairs_and_replacements_by_item(): void
    {
        $this->research(['repair_action' => 'replacement', 'repair_item' => 'МОТОРАМА · Кросс 3', 'category' => 'ПГП (борта)', 'record_date' => '2026-11-10']);
        $this->research(['repair_action' => 'replacement', 'repair_item' => 'МОТОРАМА · Кросс 3', 'category' => 'Кросс 3', 'record_date' => '2026-11-12']);
        $this->research(['repair_action' => 'repair', 'repair_item' => 'МОТОРАМА · Кросс 3', 'category' => 'Кросс 3', 'record_date' => '2026-11-11']);
        $this->research(['repair_action' => 'repair', 'repair_item' => 'Камера', 'category' => 'ПГП (борта)', 'record_date' => '2026-11-11']);
        $this->research(['record_date' => '2026-11-11']);   // ремонт не указан

        $rows = $this->asUser($this->worker('master'))->getJson('/stats/zone7?from=2026-11-01&to=2026-11-30')->assertOk()->json();

        $this->assertCount(2, $rows);
        $this->assertSame('МОТОРАМА · Кросс 3', $rows[0]['name']);
        $this->assertSame([3, 2, 1], [$rows[0]['count'], $rows[0]['replacements'], $rows[0]['repairs']]);
        $this->assertSame(['Кросс 3' => 2, 'ПГП (борта)' => 1], $rows[0]['categories']);
        $this->assertSame(['2026-11-10', '2026-11-12'], [$rows[0]['first_date'], $rows[0]['last_date']]);
        $this->assertSame(['Камера', 0, 1], [$rows[1]['name'], $rows[1]['replacements'], $rows[1]['repairs']]);

        $html = $this->asUser($this->worker('master'))->get('/stats')->assertOk()->getContent();
        $this->assertStringContainsString('Ремонт и замена в исследованиях', $html);
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
