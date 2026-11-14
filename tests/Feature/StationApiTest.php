<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CameraBracketRecord;
use App\Models\NightCameraAssemblyRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SiteHelpers;
use Tests\TestCase;

/** API станций: флешер камер и считыватель номера камеры. */
class StationApiTest extends TestCase
{
    use RefreshDatabase, SiteHelpers;

    private const KEY = 'test-station-key';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stations.key' => self::KEY]);
    }

    private function api(string $method, string $uri, array $data = [])
    {
        return $this->withHeaders(['X-Station-Key' => self::KEY, 'Accept' => 'application/json'])
            ->json($method, "/api/station/$uri", $data);
    }

    public function test_key_is_required(): void
    {
        $this->getJson('/api/station/ping')->assertStatus(401);
        $this->withHeaders(['X-Station-Key' => 'wrong'])->getJson('/api/station/ping')->assertStatus(401);
        $this->api('GET', 'ping')->assertOk()->assertJson(['ok' => true]);

        config(['services.stations.key' => '']);
        $this->api('GET', 'ping')->assertStatus(403);
    }

    public function test_employees_list_only_with_pin(): void
    {
        $this->worker('executor', 'Иванов И.И.');
        $this->api('GET', 'employees')->assertOk()->assertJson(['employees' => ['Иванов И.И.']]);
    }

    public function test_flash_creates_night_camera_record_once_per_day(): void
    {
        $user = $this->worker('executor', 'Иванов И.И.');

        $res = $this->api('POST', 'camera-flash', ['camera_serial' => 'MK0773PAZ02805', 'employee' => 'Иванов И.И.', 'pin' => '1234', 'uid' => 'a1'])
            ->assertCreated()->assertJson(['ok' => true, 'duplicate' => false]);

        $rec = NightCameraAssemblyRecord::findOrFail($res->json('id'));
        $this->assertSame('MK0773PAZ02805', $rec->camera_serial);
        $this->assertSame(1, $rec->qty);
        $this->assertTrue($rec->flashed_with_day);
        $this->assertSame('Иванов И.И.', $rec->worker1);
        $this->assertSame($user->id, $rec->created_by);
        $this->assertSame($user->id, AuditLog::where('area', 'records')->where('subject_id', $rec->id)->value('user_id'));

        // повтор из очереди (тот же uid) и повторная прошивка той же камеры в тот же день — без дублей
        $this->api('POST', 'camera-flash', ['camera_serial' => 'MK0773PAZ02805', 'employee' => 'Иванов И.И.', 'pin' => '1234', 'uid' => 'a1'])
            ->assertOk()->assertJson(['duplicate' => true, 'id' => $rec->id]);
        $this->api('POST', 'camera-flash', ['camera_serial' => 'MK0773PAZ02805', 'employee' => 'Иванов И.И.', 'pin' => '1234', 'uid' => 'a2'])
            ->assertOk()->assertJson(['duplicate' => true, 'id' => $rec->id]);
        $this->assertSame(1, NightCameraAssemblyRecord::count());
    }

    public function test_wrong_pin_and_unknown_employee_rejected(): void
    {
        $this->worker('executor', 'Иванов И.И.');

        $this->api('POST', 'camera-flash', ['camera_serial' => 'MK1', 'employee' => 'Иванов И.И.', 'pin' => '9999'])
            ->assertStatus(422)->assertJson(['error' => 'Иванов И.И.: Неверный ПИН.']);
        $this->api('POST', 'camera-flash', ['camera_serial' => 'MK1', 'employee' => 'Никто Н.Н.', 'pin' => '1234'])
            ->assertStatus(404);
        $this->api('POST', 'verify', ['employee' => 'Иванов И.И.', 'pin' => '9999', 'sheet' => 'camera_bracket'])->assertStatus(422);
        $this->api('POST', 'verify', ['employee' => 'Иванов И.И.', 'pin' => '1234', 'sheet' => 'camera_bracket'])
            ->assertOk()->assertJson(['employee' => 'Иванов И.И.']);
        $this->assertSame(0, NightCameraAssemblyRecord::count());
    }

    public function test_bracket_record_and_camera_chain(): void
    {
        $this->worker('executor', 'Иванов И.И.');
        $this->worker('executor', 'Петров П.П.');

        $this->api('POST', 'camera-flash', ['camera_serial' => 'MK0773PAZ02636', 'employee' => 'Иванов И.И.', 'pin' => '1234'])->assertCreated();

        $res = $this->api('POST', 'camera-bracket', [
            'camera_serial' => 'MK0773PAZ02636', 'serial_number' => 'KR-15', 'camera_status' => 'Работает',
            'night_camera_status' => 'Работает', 'notes' => 'ок', 'employee' => 'Петров П.П.', 'pin' => '1234',
        ])->assertCreated();

        $rec = CameraBracketRecord::findOrFail($res->json('id'));
        $this->assertSame('KR-15', $rec->serial_number);
        $this->assertSame('Петров П.П.', $rec->worker1);

        $this->api('POST', 'camera-bracket', ['camera_serial' => 'MK1', 'camera_status' => 'Отлично', 'employee' => 'Петров П.П.', 'pin' => '1234'])
            ->assertStatus(422)->assertJsonValidationErrors('camera_status');

        $chain = $this->api('GET', 'camera/MK0773PAZ02636')->assertOk()->json('chain');
        $this->assertSame(['night_camera_assembly', 'camera_bracket'], array_column($chain, 'sheet'));
        $this->assertSame([['Иванов И.И.'], ['Петров П.П.']], array_column($chain, 'workers'));
    }

    public function test_up_to_three_workers_each_with_pin(): void
    {
        $first = $this->worker('executor', 'Иванов И.И.');
        $this->worker('executor', 'Петров П.П.');
        $this->worker('executor', 'Сидоров С.С.');
        $this->worker('executor', 'Кузнецов К.К.');
        $w = fn (string $n, string $pin = '1234') => ['employee' => $n, 'pin' => $pin];

        $res = $this->api('POST', 'camera-flash', ['camera_serial' => 'MK7',
            'workers' => [$w('Иванов И.И.'), $w('Петров П.П.'), $w('Сидоров С.С.')]])->assertCreated();
        $rec = NightCameraAssemblyRecord::findOrFail($res->json('id'));
        $this->assertSame(['Иванов И.И.', 'Петров П.П.', 'Сидоров С.С.', null], [$rec->worker1, $rec->worker2, $rec->worker3, $rec->worker4]);
        $this->assertSame($first->id, $rec->created_by, 'запись — от имени первого');

        $res = $this->api('POST', 'camera-bracket', ['camera_serial' => 'MK7', 'workers' => [$w('Петров П.П.'), $w('Иванов И.И.')]])->assertCreated();
        $this->assertSame('Иванов И.И.', CameraBracketRecord::findOrFail($res->json('id'))->worker2);

        // неверный ПИН второго — ничего не записано, в ошибке видно, у кого
        $this->api('POST', 'camera-flash', ['camera_serial' => 'MK8', 'workers' => [$w('Иванов И.И.'), $w('Петров П.П.', '9999')]])
            ->assertStatus(422)->assertJson(['error' => 'Петров П.П.: Неверный ПИН.']);
        $this->api('POST', 'camera-flash', ['camera_serial' => 'MK8', 'workers' => [$w('Иванов И.И.'), $w('Иванов И.И.')]])
            ->assertStatus(422);
        $this->api('POST', 'camera-flash', ['camera_serial' => 'MK8',
            'workers' => [$w('Иванов И.И.'), $w('Петров П.П.'), $w('Сидоров С.С.'), $w('Кузнецов К.К.')]])
            ->assertStatus(422)->assertJsonValidationErrors('workers');
        $this->api('POST', 'camera-flash', ['camera_serial' => 'MK8'])->assertStatus(422)->assertJsonValidationErrors('employee');
        $this->assertSame(0, NightCameraAssemblyRecord::where('camera_serial', 'MK8')->count());
    }

    public function test_site_form_accepts_camera_serial(): void
    {
        $user = $this->worker('master');
        $this->asUser($user)->get('/records/camera_bracket/create')->assertOk()->assertSee('№ камеры');
        $this->asUser($user)->get('/records/night_camera_assembly/create')->assertOk()->assertSee('№ камеры');
    }
}
