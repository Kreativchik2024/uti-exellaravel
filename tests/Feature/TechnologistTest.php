<?php

namespace Tests\Feature;

use App\Models\PgpRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SiteHelpers;
use Tests\TestCase;

/** Технолог в записи ПГП (обязательный, из группы ОТК), время ожидания и статистика по технологам. */
class TechnologistTest extends TestCase
{
    use RefreshDatabase, SiteHelpers;

    /** Технолог — сотрудник с ролью ОТК; $active — активна ли карточка сотрудника. */
    private function tech(string $short, bool $active = true): User
    {
        $u = $this->worker('otk', $short);
        $u->employee->forceFill(['is_active' => $active])->save();

        return $u;
    }

    private function pgp(array $attrs): PgpRecord
    {
        $r = new PgpRecord();
        $r->forceFill(array_merge(['record_date' => '2026-11-10', 'post_number' => 1, 'worker1' => 'Иванов И.И.', 'board_type' => 'МН', 'status' => 'Сдан'], $attrs))->save();

        return $r;
    }

    public function test_form_lists_only_active_technologists(): void
    {
        $this->tech('Петрова А.А.');
        $this->tech('Ушедший У.У.', false);
        $this->worker('executor', 'Исполнитель И.И.');

        $html = $this->asUser($this->worker('master'))->get('/records/pgp/create')->assertOk()->getContent();
        $this->assertStringNotContainsString('name="wait_minutes"', $html, 'время ожидания пока убрано из формы');

        // Только сам список технологов (исполнители есть в других списках формы)
        $start = mb_strpos($html, 'name="technologist"');
        $this->assertNotFalse($start);
        $select = mb_substr($html, $start, mb_strpos($html, '</select>', $start) - $start);
        $this->assertStringContainsString('<option value="Петрова А.А."', $select);
        $this->assertStringNotContainsString('Ушедший У.У.', $select);
        $this->assertStringNotContainsString('Исполнитель И.И.', $select);
    }

    public function test_otk_role_set_on_employee_appears_in_technologist_list(): void
    {
        $admin = $this->worker('admin');
        $worker = $this->worker('executor', 'Новикова Н.Н.');
        $card = $worker->employee;
        $select = function () use ($admin) {
            $html = $this->asUser($admin)->get('/records/pgp/create')->assertOk()->getContent();
            $start = mb_strpos($html, 'name="technologist"');
            return mb_substr($html, $start, mb_strpos($html, '</select>', $start) - $start);
        };
        $this->assertStringNotContainsString('Новикова Н.Н.', $select());
        $this->assertStringNotContainsString($card->last_name, $select());

        // Роль ОТК в карточке сотрудника — сразу в списке технологов
        $this->asUser($admin)->put("/employees/{$card->id}", [
            'last_name' => $card->last_name, 'first_name' => $card->first_name, 'middle_name' => $card->middle_name,
            'is_active' => 1, 'user_id' => $worker->id, 'role' => 'otk',
        ])->assertSessionHasNoErrors();
        // короткое ФИО при сохранении карточки собирается из фамилии и инициалов
        $this->assertStringContainsString('<option value="' . $card->fresh()->short_name . '"', $select());

        // Сотрудник ОТК без учётной записи — тоже технолог
        $this->asUser($admin)->post('/employees', ['last_name' => 'Безучёткин', 'first_name' => 'Олег', 'is_active' => 1, 'role' => 'otk'])
            ->assertSessionHasNoErrors();
        $this->assertStringContainsString('<option value="Безучёткин О."', $select());
    }

    public function test_technologist_is_required_and_must_be_from_list(): void
    {
        $this->tech('Петрова А.А.');
        $exec = $this->worker();
        $base = $this->validRecord('pgp', $this->nameOf($exec));

        $this->asUser($exec)->post('/records/pgp', array_merge($base, ['technologist' => '']))->assertSessionHasErrors('technologist');
        $this->asUser($exec)->post('/records/pgp', array_merge($base, ['technologist' => 'Кто-то Левый']))->assertSessionHasErrors('technologist');
        $this->assertSame(0, PgpRecord::count());

        // Время ожидания пока не принимается: даже если прислать — не сохраняется
        $this->asUser($exec)->post('/records/pgp', array_merge($base, ['technologist' => 'Петрова А.А.', 'wait_minutes' => '15']))->assertSessionHasNoErrors();
        $rec = PgpRecord::firstOrFail();
        $this->assertSame('Петрова А.А.', $rec->technologist);
        $this->assertNull($rec->wait_minutes);
    }

    public function test_technologist_is_optional_when_board_not_delivered(): void
    {
        $this->tech('Петрова А.А.');
        $exec = $this->worker();
        $base = array_merge($this->validRecord('pgp', $this->nameOf($exec)), ['technologist' => '']);

        // «Сдан» — без технолога нельзя
        $this->asUser($exec)->post('/records/pgp', array_merge($base, ['status' => 'Сдан']))->assertSessionHasErrors('technologist');
        // «Не сдан» — можно без технолога
        $this->asUser($exec)->post('/records/pgp', array_merge($base, ['status' => 'Не сдан', 'board_number' => '', 'ip_address' => '']))
            ->assertSessionHasNoErrors();
        $this->assertNull(PgpRecord::firstOrFail()->technologist);

        // В форме поле помечено «обязательно, кроме „Не сдан“»
        $form = $this->asUser($exec)->get('/records/pgp/create')->getContent();
        $start = mb_strpos($form, 'data-required-when-field="status"', mb_strpos($form, 'Приёмка технологом'));
        $this->assertNotFalse($start);
    }

    public function test_old_record_with_departed_technologist_can_be_saved(): void
    {
        $tech = $this->tech('Петрова А.А.');
        $rec = $this->pgp(['technologist' => 'Петрова А.А.']);
        $tech->employee->forceFill(['role' => 'executor'])->save();   // больше не технолог

        $master = $this->worker('master');
        $data = array_merge($this->validRecord('pgp', 'Иванов И.И.'), ['technologist' => 'Петрова А.А.', 'worker1' => $rec->worker1]);
        $html = $this->asUser($master)->get("/records/pgp/{$rec->id}/edit")->assertOk()->getContent();
        $this->assertStringContainsString('<option value="Петрова А.А."', $html, 'текущий технолог остаётся в списке');

        $this->asUser($master)->put("/records/pgp/{$rec->id}", $data)->assertSessionDoesntHaveErrors('technologist');
    }

    public function test_stats_count_boards_per_technologist(): void
    {
        $this->pgp(['technologist' => 'Петрова А.А.']);
        $this->pgp(['technologist' => 'Петрова А.А.']);
        $this->pgp(['technologist' => 'Петрова А.А.', 'status' => 'Не сдан']);
        $this->pgp(['technologist' => 'Сидоров С.С.']);
        $this->pgp(['technologist' => 'Сидоров С.С.', 'record_date' => '2026-10-01']);   // вне периода
        $this->pgp(['technologist' => null]);

        $rows = $this->asUser($this->worker('master'))->getJson('/stats/zone8?from=2026-11-01&to=2026-11-30')->assertOk()->json();

        $this->assertSame([
            ['name' => 'Петрова А.А.', 'boards' => 3, 'delivered' => 2],
            ['name' => 'Сидоров С.С.', 'boards' => 1, 'delivered' => 1],
        ], $rows);

        $html = $this->asUser($this->worker('master'))->get('/stats')->assertOk()->getContent();
        $this->assertStringNotContainsString('Среднее ожидание', $html);
    }

    public function test_summary_shows_boards_by_technologist_sorted_and_drills_down(): void
    {
        $p1 = $this->pgp(['technologist' => 'Петрова А.А.', 'status' => 'Не сдан']);
        $s1 = $this->pgp(['technologist' => 'Сидоров С.С.']);
        $s2 = $this->pgp(['technologist' => 'Сидоров С.С.']);
        $s3 = $this->pgp(['technologist' => 'Сидоров С.С.', 'status' => 'Не сдан']);
        $period = 'from=2026-11-01&to=2026-11-30';
        $master = $this->worker('master');

        $html = $this->asUser($master)->get("/stats?{$period}")->assertOk()->getContent();
        $this->assertStringContainsString('Борта по представителям ОТК', $html);
        $block = mb_substr($html, mb_strpos($html, 'Борта по представителям ОТК'));
        $this->assertLessThan(mb_strpos($block, 'Петрова А.А.'), mb_strpos($block, 'Сидоров С.С.'), 'больше бортов — выше');

        // Клик по «✕ не сдано» у технолога — только его несданные борта
        $list = $this->asUser($master)->get('/stats/records?' . $period . '&sheet=pgp&kind=defect&board=_all&tech=' . urlencode('Сидоров С.С.'))
            ->assertOk()->getContent();
        $this->assertStringContainsString('технолог Сидоров С.С.', $list);
        $this->assertStringContainsString("/records/pgp/{$s3->id}\"", $list);
        foreach ([$s1, $s2, $p1] as $other) {
            $this->assertStringNotContainsString("/records/pgp/{$other->id}\"", $list);
        }

        // Отдельный блок «Сводка по представителям ОТК» — только мастеру и админу; технологу — нет
        $this->assertStringContainsString('Сводка по представителям ОТК', $html);
        $this->assertStringContainsString('Сводка по представителям ОТК', $this->asUser($this->worker('admin'))->get("/stats?{$period}")->getContent());
        $this->assertStringNotContainsString('Борта по представителям ОТК', $this->asUser($this->worker('technologist'))->get("/stats?{$period}&mode=all")->assertOk()->getContent());

        // Исполнителю разрез по технологам не показывается и не открывается
        $exec = $this->worker();
        $this->assertStringNotContainsString('Борта по представителям ОТК', $this->asUser($exec)->get("/stats?{$period}&mode=all")->assertOk()->getContent());
        $this->asUser($exec)->get('/stats/records?' . $period . '&sheet=pgp&tech=' . urlencode('Сидоров С.С.'))->assertForbidden();
    }

    public function test_zone8_numbers_link_to_boards_of_technologist(): void
    {
        $ok   = $this->pgp(['technologist' => 'Сидоров С.С.']);
        $bad  = $this->pgp(['technologist' => 'Сидоров С.С.', 'status' => 'Не сдан']);
        $other = $this->pgp(['technologist' => 'Петрова А.А.']);
        $otk = $this->worker('technologist');

        $html = $this->asUser($otk)->get('/stats')->assertOk()->getContent();
        $this->assertStringContainsString('zone8Url(row.name, \'all\')', $html);
        $this->assertStringContainsString('zone8Url(row.name, \'defect\')', $html);

        // Та же ссылка, что строит zone8Url: ОТК видит борта технолога целиком, не только свои записи
        $url = fn ($kind) => '/stats/records?' . http_build_query(['sheet' => 'pgp', 'board' => '_all', 'tech' => 'Сидоров С.С.',
            'kind' => $kind, 'mode' => 'all', 'from' => '2026-11-01', 'to' => '2026-11-30']);

        $all = $this->asUser($otk)->get($url('all'))->assertOk()->getContent();
        $this->assertStringContainsString("/records/pgp/{$ok->id}\"", $all);
        $this->assertStringContainsString("/records/pgp/{$bad->id}\"", $all);
        $this->assertStringNotContainsString("/records/pgp/{$other->id}\"", $all);

        $defect = $this->asUser($otk)->get($url('defect'))->assertOk()->getContent();
        $this->assertStringContainsString("/records/pgp/{$bad->id}\"", $defect);
        $this->assertStringNotContainsString("/records/pgp/{$ok->id}\"", $defect);
    }

    public function test_technologist_stats_visible_only_to_master_otk_admin(): void
    {
        foreach (['executor', 'repair'] as $role) {
            $u = $this->worker($role);
            $this->asUser($u)->getJson('/stats/zone8')->assertForbidden();
            $this->assertStringNotContainsString('8. Представители ОТК', $this->asUser($u)->get('/stats')->getContent());
        }

        foreach (['master', 'technologist', 'admin'] as $role) {
            $u = $this->worker($role);
            $this->asUser($u)->getJson('/stats/zone8')->assertOk();
            $this->assertStringContainsString('8. Представители ОТК', $this->asUser($u)->get('/stats')->assertOk()->getContent());
        }
    }
}
