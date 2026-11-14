<?php

namespace Tests\Feature;

use App\Models\CameraBracketRecord;
use App\Models\NightCameraAssemblyRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SiteHelpers;
use Tests\TestCase;

/** Цепочка по камере: кто прошил (ночная камера в сборе) → кто собрал кронштейн → ПГП. */
class CameraChainTest extends TestCase
{
    use RefreshDatabase, SiteHelpers;

    private function flashed(string $serial = 'MK0773PAZ02628'): NightCameraAssemblyRecord
    {
        return NightCameraAssemblyRecord::create(['record_date' => '2026-11-14', 'camera_serial' => $serial, 'qty' => 1,
            'flashed_with_day' => true, 'worker1' => 'Смирнов А.В.', 'worker2' => 'Скрябин Р.М.']);
    }

    public function test_bracket_camera_serial_shows_who_flashed(): void
    {
        $this->flashed();
        $user = $this->worker('master');

        $this->asUser($user)->getJson('/records/camera_bracket/lookup?field=camera_serial&value=MK0773PAZ02628')
            ->assertOk()->assertJson(['found' => true, 'caption' => 'Прошил',
                'workers' => 'Смирнов А.В., Скрябин Р.М.', 'record_date' => '14.11.2026', 'health' => 'green']);
        $this->asUser($user)->getJson('/records/camera_bracket/lookup?field=camera_serial&value=NOPE')
            ->assertOk()->assertJson(['found' => false]);
    }

    public function test_bracket_form_has_assembler_with_employees_and_saves_snapshot(): void
    {
        $this->flashed();
        $user = $this->worker('master', 'Петров П.П.');
        $this->asUser($user)->get('/records/camera_bracket/create')->assertOk()
            ->assertSee('Кто собирал кронштейн')->assertSee('<option value="Петров П.П.">', false);

        $this->asUser($user)->post('/records/camera_bracket', [
            'record_date' => '2026-11-14', 'serial_number' => 'KR-1', 'camera_serial' => 'MK0773PAZ02628',
            'assembled_by' => 'Петров П.П.', 'worker1' => 'Петров П.П.',
        ])->assertRedirect();

        $rec = CameraBracketRecord::firstOrFail();
        $this->assertSame('Петров П.П.', $rec->assembled_by);
        $this->assertSame('Смирнов А.В., Скрябин Р.М.', $rec->flash_worker, 'снимок «Прошил» сохранён с записью');
        $this->asUser($user)->get("/records/camera_bracket/{$rec->id}")->assertOk()->assertSee('Прошил: Смирнов А.В., Скрябин Р.М.');
    }

    public function test_pgp_camera_lookup_shows_assembler_and_flasher(): void
    {
        $this->flashed();
        CameraBracketRecord::create(['record_date' => '2026-11-14', 'serial_number' => 'KR-7', 'camera_serial' => 'MK0773PAZ02628',
            'assembled_by' => 'Петров П.П.', 'camera_status' => 'Работает', 'night_camera_status' => 'Работает',
            'speed_sensor_status' => 'Работает', 'suspension_status' => 'Работает', 'worker1' => 'Иванов И.И.']);
        $user = $this->worker('master');

        $res = $this->asUser($user)->getJson('/records/pgp/lookup?field=bracket_serial&value=KR-7')->assertOk()
            ->assertJson(['found' => true, 'workers' => 'Иванов И.И.']);
        $this->assertSame([
            ['label' => 'Собрал', 'value' => 'Петров П.П.'],
            ['label' => 'Прошил камеру', 'value' => 'Смирнов А.В., Скрябин Р.М. · 14.11.2026'],
        ], $res->json('details'));

        // кронштейн без № камеры и сборщика — лишних строк нет
        CameraBracketRecord::create(['record_date' => '2026-11-14', 'serial_number' => 'KR-8', 'worker1' => 'Иванов И.И.']);
        $this->assertSame([], $this->asUser($user)->getJson('/records/pgp/lookup?field=bracket_serial&value=KR-8')->json('details'));
    }

    public function test_reader_gets_fault_codes_and_saves_code_like_site(): void
    {
        config(['services.stations.key' => 'k']);
        $section = \App\Models\Section::where('slug', \App\Models\Section::DEFAULT_SLUG)->first();
        $cam = \App\Models\FaultCodeGroup::create(['name' => 'Камера', 'prefix' => 'K', 'sheets' => ['camera_bracket'], 'section_id' => $section->id]);
        $other = \App\Models\FaultCodeGroup::create(['name' => 'Роутер', 'prefix' => 'R', 'sheets' => ['router_firmware'], 'section_id' => $section->id]);
        \App\Models\FaultCode::create(['code' => 'K.1', 'description' => 'Нет изображения', 'group_id' => $cam->id, 'section_id' => $section->id]);
        \App\Models\FaultCode::create(['code' => 'K.2', 'description' => 'Не работает подвес', 'group_id' => $cam->id, 'section_id' => $section->id]);
        \App\Models\FaultCode::create(['code' => 'R.1', 'description' => 'Не грузится роутер', 'group_id' => $other->id, 'section_id' => $section->id]);
        $this->worker('executor', 'Петров П.П.');
        $api = fn () => $this->withHeaders(['X-Station-Key' => 'k', 'Accept' => 'application/json']);

        $codes = $api()->getJson('/api/station/fault-codes?sheet=camera_bracket')->assertOk()->json('codes');
        $this->assertSame(['K.1', 'K.2'], array_column($codes, 'code'), 'только коды категории кронштейна');
        $this->assertSame('Нет изображения', $codes[0]['description']);
        $api()->getJson('/api/station/fault-codes?sheet=pgp')->assertStatus(422);

        $w = [['employee' => 'Петров П.П.', 'pin' => '1234']];
        $id = $api()->postJson('/api/station/camera-bracket', ['camera_serial' => 'MK1', 'fault_code' => 'K.2', 'workers' => $w])
            ->assertCreated()->json('id');
        $this->assertSame('K.2', CameraBracketRecord::find($id)->fault_code);

        // вписано описание известной неисправности — её код; новой — новый код в категории (как в форме сайта)
        $id = $api()->postJson('/api/station/camera-bracket', ['camera_serial' => 'MK2', 'fault_code' => 'нет изображения', 'workers' => $w])
            ->assertCreated()->json('id');
        $this->assertSame('K.1', CameraBracketRecord::find($id)->fault_code);
        $id = $api()->postJson('/api/station/camera-bracket', ['camera_serial' => 'MK3', 'fault_code' => 'Треснул корпус кронштейна', 'workers' => $w])
            ->assertCreated()->json('id');
        $this->assertSame('K.3', CameraBracketRecord::find($id)->fault_code);
    }

    public function test_flasher_defect_recorded_with_fault_code_and_cleared_after_repair(): void
    {
        config(['services.stations.key' => 'k']);
        $section = \App\Models\Section::where('slug', \App\Models\Section::DEFAULT_SLUG)->first();
        $g = \App\Models\FaultCodeGroup::create(['name' => 'Ночная камера', 'prefix' => 'N', 'sheets' => ['night_camera_assembly'], 'section_id' => $section->id]);
        \App\Models\FaultCode::create(['code' => 'N.1', 'description' => 'Шум тепловизора', 'group_id' => $g->id, 'section_id' => $section->id]);
        $this->worker('executor', 'Петров П.П.');
        $api = fn ($data) => $this->withHeaders(['X-Station-Key' => 'k', 'Accept' => 'application/json'])
            ->postJson('/api/station/camera-flash', $data + ['camera_serial' => 'MK9', 'workers' => [['employee' => 'Петров П.П.', 'pin' => '1234']]]);

        $id = $api(['fault_code' => 'N.1', 'notes' => 'БРАК: шум'])->assertCreated()->json('id');
        $this->assertSame('N.1', NightCameraAssemblyRecord::find($id)->fault_code);
        $this->assertSame('БРАК: шум', NightCameraAssemblyRecord::find($id)->notes);

        // в тот же день после ремонта прошита успешно — та же запись, без кода неисправности
        $this->assertSame($id, $api(['notes' => 'прошита'])->assertOk()->json('id'));
        $this->assertNull(NightCameraAssemblyRecord::find($id)->fault_code);
        $this->assertSame(1, NightCameraAssemblyRecord::count());
    }

    public function test_reader_api_fills_flash_snapshot_and_assembler(): void
    {
        config(['services.stations.key' => 'k']);
        $this->flashed();
        $this->worker('executor', 'Петров П.П.');

        $res = $this->withHeaders(['X-Station-Key' => 'k'])->postJson('/api/station/camera-bracket', [
            'camera_serial' => 'MK0773PAZ02628', 'assembled_by' => 'Петров П.П.',
            'workers' => [['employee' => 'Петров П.П.', 'pin' => '1234']],
        ])->assertCreated();

        $rec = CameraBracketRecord::findOrFail($res->json('id'));
        $this->assertSame('Петров П.П.', $rec->assembled_by);
        $this->assertSame('Смирнов А.В., Скрябин Р.М.', $rec->flash_worker);
        $this->assertSame('2026-11-14', $rec->flash_record_date->toDateString());
    }
}
