<?php

namespace Tests\Feature;

use App\Models\PgpRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SiteHelpers;
use Tests\TestCase;

/**
 * Журнал борта на карточке ПГП: этапы (прошивка и настройка → сборка → ПГП → ремонт → ОТК),
 * периферия по номерам, борт — по моторамe/фюзеляжу (номер борта при ремонте не переходит), журнал изменений.
 */
class BoardJournalTest extends TestCase
{
    use RefreshDatabase, SiteHelpers;

    /** Запись любой вкладки с правильными полями (как из формы) + свои значения. */
    private function make(string $sheet, array $attrs): Model
    {
        $model = config("sheets.map.$sheet.model");
        $data = $this->validRecord($sheet, 'Иванов И.И.');
        $r = new $model();
        $r->forceFill(array_merge($data, $attrs))->save();

        return $r;
    }

    public function test_journal_pulls_peripherals_by_numbers_and_shows_stages_and_who(): void
    {
        $this->make('cross3', ['serial_number' => 'CR3-0001', 'worker1' => 'Прошивалов П.П.']);
        $this->make('night_camera_assembly', ['camera_serial' => 'CAM-777', 'worker1' => 'Флешеров Ф.Ф.']);
        $this->make('camera_bracket', ['serial_number' => 'BR-55', 'camera_serial' => 'CAM-777', 'assembled_by' => 'Сборкин С.С.', 'worker1' => 'Сборкин С.С.']);
        $pgp = $this->make('pgp', ['board_number' => 'RA-1', 'frame_serial' => 'FR-1001', 'cross3_number' => 'CR3-0001', 'bracket_serial' => 'BR-55', 'status' => 'Сдан']);

        $html = $this->asUser($this->worker('master'))->get("/records/pgp/{$pgp->id}")->assertOk()->getContent();
        $journal = mb_substr($html, mb_strpos($html, 'Журнал борта'));

        $this->assertStringContainsString('Собрано по номерам:', $journal);
        $this->assertStringContainsString('моторама FR-1001', $journal);
        foreach (['1. Прошивка и настройка', '2. Сборка', '3. ПГП'] as $stage) $this->assertStringContainsString($stage, $journal);
        // 1. Кросс 3 и прошивка камеры (через кронштейн → № камеры), 2. сборка кронштейна, кто делал
        $this->assertStringContainsString('Кросс 3 · CR3-0001', $journal);
        $this->assertStringContainsString('Прошивалов П.П.', $journal);
        $this->assertStringContainsString('Ночная камера в сборе с дневной · CAM-777', $journal);
        $this->assertStringContainsString('Флешеров Ф.Ф.', $journal);
        $this->assertStringContainsString('Кронштейн с камерой · BR-55', $journal);
        $this->assertStringContainsString('Собрал: Сборкин С.С.', $journal);
        $this->assertStringContainsString('Проверка на ПГП: Сдан', $journal);
    }

    public function test_form_shows_what_is_known_about_board_when_frame_number_entered(): void
    {
        $this->make('cross3', ['serial_number' => 'CR3-0005', 'worker1' => 'Прошивалов П.П.']);
        $earlier = $this->make('pgp', ['board_number' => 'RA-OLD', 'frame_serial' => 'FR-5005', 'cross3_number' => 'CR3-0005',
            'status' => 'Не сдан', 'defect_reason' => 'Нет связи с модулем']);
        $exec = $this->worker();

        // В форме ПГП есть блок, который подгружается по номерам
        $form = $this->asUser($exec)->get('/records/pgp/create')->assertOk()->getContent();
        $this->assertStringContainsString('data-board-journal', $form);
        $this->assertStringContainsString(route('records.boardJournal', 'pgp'), $form);

        // Ввели № моторамы — вся история борта: прежняя проверка ПГП (под старым номером) и прошивка Кросс 3
        $html = $this->asUser($exec)->get('/records/pgp/board-journal?frame_serial=FR-5005')->assertOk()->getContent();
        $this->assertStringContainsString('Что известно об этом борте', $html);
        $this->assertStringContainsString("/records/pgp/{$earlier->id}\"", $html);
        $this->assertStringContainsString('Нет связи с модулем', $html);
        $this->assertStringContainsString('Кросс 3 · CR3-0005', $html);
        $this->assertStringContainsString('Прошивалов П.П.', $html);

        // Только номер периферии (моторамы ещё нет) — показывает, кто её шил
        $html = $this->asUser($exec)->get('/records/pgp/board-journal?cross3_number=CR3-0005')->assertOk()->getContent();
        $this->assertStringContainsString('Кросс 3 · CR3-0005', $html);
        $this->assertStringNotContainsString("/records/pgp/{$earlier->id}\"", $html);

        // Неизвестная моторама — «борт новый»; без номеров — пусто
        $this->assertStringContainsString('ничего не найдено', $this->asUser($exec)->get('/records/pgp/board-journal?frame_serial=FR-0000')->getContent());
        $this->assertSame('', $this->asUser($exec)->get('/records/pgp/board-journal')->assertOk()->getContent());
        $this->asUser($exec)->get('/records/cross3/board-journal?frame_serial=FR-5005')->assertNotFound();
    }

    public function test_comm_module_is_not_tied_to_board(): void
    {
        // Модуль связи переходит с номером борта на другой борт — в журнал физического борта не попадает
        $module = $this->make('comm_module', ['serial_number' => 'MAN-123', 'worker1' => 'Связист С.С.']);
        $pgp = $this->make('pgp', ['frame_serial' => 'FR-6006', 'manet_number' => 'MAN-123', 'status' => 'Сдан']);
        $master = $this->worker('master');

        $card = $this->asUser($master)->get("/records/pgp/{$pgp->id}")->assertOk()->getContent();
        $journal = mb_substr($card, mb_strpos($card, 'Журнал борта'));
        $this->assertStringNotContainsString("/records/comm_module/{$module->id}\"", $journal);
        $this->assertStringNotContainsString('Связист С.С.', $journal);

        // Форма на номер модуля не реагирует
        $this->assertSame('', $this->asUser($master)->get('/records/pgp/board-journal?manet_number=MAN-123')->assertOk()->getContent());
        $this->assertStringNotContainsString("'manet_number'", $this->asUser($master)->get('/records/pgp/create')->getContent());
    }

    public function test_board_is_recognised_by_frame_even_when_board_number_changed(): void
    {
        // Ушёл в ремонт под одним номером, вернулся на ПГП под другим — та же моторама
        $first = $this->make('pgp', ['board_number' => 'RA-OLD', 'frame_serial' => 'FR-2002', 'status' => 'Не сдан', 'defect_reason' => 'Нет связи']);
        $second = $this->make('pgp', ['board_number' => 'RA-NEW', 'frame_serial' => 'FR-2002', 'status' => 'Сдан']);
        // Наклейка RA-OLD ушла на другой борт — он не должен попасть в журнал
        $stranger = $this->make('pgp', ['board_number' => 'RA-OLD', 'frame_serial' => 'FR-9999', 'status' => 'Сдан']);

        $html = $this->asUser($this->worker('master'))->get("/records/pgp/{$second->id}")->assertOk()->getContent();
        $journal = mb_substr($html, mb_strpos($html, 'Журнал борта'));

        $this->assertStringContainsString("/records/pgp/{$first->id}\"", $journal);
        $this->assertStringContainsString('Нет связи', $journal);
        $this->assertStringNotContainsString("/records/pgp/{$stranger->id}\"", $journal);
    }

    public function test_lookup_hints_are_not_shown_next_to_pgp_fields(): void
    {
        $this->make('cross3', ['serial_number' => 'CR3-0002', 'worker1' => 'Прошивалов П.П.']);
        $pgp = $this->make('pgp', ['frame_serial' => 'FR-3003', 'cross3_number' => 'CR3-0002', 'cross3_worker' => 'Прошивалов П.П.', 'cross3_health' => 'green']);
        $master = $this->worker('master');

        $html = $this->asUser($master)->get("/records/pgp/{$pgp->id}")->assertOk()->getContent();
        // До журнала (в полях карточки) подсказки «В порядке · кто» нет
        $fieldsPart = mb_substr($html, 0, mb_strpos($html, 'Журнал борта'));
        $this->assertStringNotContainsString('В порядке', $fieldsPart);

        $form = $this->asUser($master)->get("/records/pgp/{$pgp->id}/edit")->assertOk()->getContent();
        $this->assertStringNotContainsString('class="lookup-result', $form);
    }

    public function test_change_log_shows_who_changed_what_for_master_only(): void
    {
        $master = $this->worker('master');
        $pgp = $this->make('pgp', ['frame_serial' => 'FR-4004', 'status' => 'Не сдан', 'defect_reason' => 'Старая причина']);
        $this->actingAs($master);
        $pgp->update(['defect_reason' => 'Новая причина']);

        $html = $this->asUser($master)->get("/records/pgp/{$pgp->id}")->assertOk()->getContent();
        $this->assertStringContainsString('Журнал изменений', $html);
        $this->assertStringContainsString('Старая причина', $html);
        $this->assertStringContainsString('Новая причина', $html);
        $this->assertStringContainsString($master->name, $html);

        $exec = $this->asUser($this->worker())->get("/records/pgp/{$pgp->id}")->assertOk()->getContent();
        $this->assertStringNotContainsString('Журнал изменений', $exec);
    }
}
