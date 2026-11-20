<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SiteHelpers;
use Tests\TestCase;

/** Профиль модулей связи (параметры + .msconf) и загрузка папки лицензий целиком. */
class CommProfileTest extends TestCase
{
    use RefreshDatabase, SiteHelpers;

    private const KEY = 'test-station-key';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['services.stations.key' => self::KEY]);
    }

    private function station(string $uri)
    {
        $res = $this->flushHeaders()->withHeaders(['X-Station-Key' => self::KEY, 'Accept' => 'application/json'])
            ->get("/api/station/$uri");
        $this->flushHeaders();                    // заголовки программы не должны прилипнуть к запросам админа

        return $res;
    }

    private function msconf(string $name = 'MeshSameParams.msconf', string $mesh = 'AyaksMesh'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, json_encode([
            'version' => "2.12.2-rc16-M0095\n", 'uniformityParam' => ['meshName' => $mesh, 'freqMode' => 'adaptiveHopping2']]));
    }

    public function test_defaults_and_save_params(): void
    {
        $admin = $this->worker('admin');
        $p = $this->station('comm-profile')->assertOk()->json('params');
        $this->assertSame([1650, 36, 9, 18, 9], [$p['rf_freq_mhz'], $p['rf_atten'], $p['min_atten'], $p['tfci'], $p['cal_atten']]);
        $this->assertFalse($p['dhcp_server']);

        $this->asUser($admin)->get('/comm-profile')->assertOk()->assertSee('RF Attenuation TX1 / TX2');
        $form = array_merge($p, ['rf_freq_mhz' => '1660', 'cal_atten' => '10', 'dhcp_server' => '0', 'msconf_global' => '1']);
        $this->asUser($admin)->post('/comm-profile/params', $form)->assertRedirect();
        $p = $this->station('comm-profile')->json('params');
        $this->assertSame([1660, 10, false, true], [$p['rf_freq_mhz'], $p['cal_atten'], $p['dhcp_server'], $p['msconf_global']]);

        // История изменений: что поменяли, было → стало
        $page = $this->asUser($admin)->get('/comm-profile')->assertOk();
        $page->assertSee('История изменений')->assertSee('Значения прошивки модулей связи: изменено 2')->assertSee('RF Frequency, МГц');
        $log = \App\Models\AuditLog::where('area', 'comm_profile')->latest('id')->firstOrFail();
        $this->assertSame(['rf_freq_mhz' => ['1650', '1660'], 'cal_atten' => ['9', '10']], $log->changes);

        $this->asUser($admin)->post('/comm-profile/params', array_merge($form, ['net_prefix' => '192.168']))
            ->assertSessionHasErrors('net_prefix');
    }

    public function test_msconf_upload_activate_download(): void
    {
        $admin = $this->worker('admin');
        $this->asUser($admin)->post('/comm-profile/msconf', ['msconf' => $this->msconf()])->assertRedirect();
        $this->asUser($admin)->post('/comm-profile/msconf', ['msconf' => $this->msconf('Other.msconf', 'Test'), 'make_active' => '0']);

        $ms = $this->station('comm-profile')->json('msconf');
        $this->assertSame('MeshSameParams.msconf', $ms['name'], 'первый загруженный — рабочий');
        $body = $this->station('comm-profile/msconf')->assertOk()->streamedContent();
        $this->assertSame($ms['sha256'], hash('sha256', $body));

        $this->asUser($admin)->post('/comm-profile/msconf/Other.msconf/activate')->assertRedirect();
        $this->assertSame('Other.msconf', $this->station('comm-profile')->json('msconf.name'));

        // не бэкап — отклоняется
        $bad = UploadedFile::fake()->createWithContent('x.msconf', '{"a":1}');
        $this->asUser($admin)->post('/comm-profile/msconf', ['msconf' => $bad])->assertSessionHasErrors('msconf');

        $this->asUser($admin)->delete('/comm-profile/msconf/Other.msconf');
        $this->assertNull($this->station('comm-profile')->json('msconf'));
        $this->asUser($admin)->get('/comm-profile')->assertSee('Загружен бэкап MeshSameParams.msconf')->assertSee('Рабочий бэкап: Other.msconf')->assertSee('Удалён бэкап Other.msconf');
        $this->station('comm-profile/msconf')->assertNotFound();
    }

    public function test_license_folder_upload(): void
    {
        $admin = $this->worker('admin');
        $f = fn ($n, $c = 'x') => UploadedFile::fake()->createWithContent($n, $c);
        $this->asUser($admin)->post('/comm-licenses-folder', [
            'files' => [$f('Licence_A.dat', 'A'), $f('6th Box NO.F1~F20.xlsx'), $f('License Tutorial.pdf'),
                        $f('Licence_B.dat', 'B'), $f('Licence_C.dat'), $f('Licence_D.dat')],
            'paths' => ['files/4/Licence_A.dat', 'files/4/6th Box NO.F1~F20.xlsx', 'files/4/License Tutorial.pdf',
                        'files/1/Licence_B.dat', 'files/9/Licence_C.dat', 'files/9/Licence_D.dat'],
        ])->assertRedirect('/comm-licenses')->assertSessionHas('status', fn ($s) => str_contains($s, 'Загружено партий: 2')
            && str_contains($s, 'files/9'));

        $paths = array_column($this->station('comm-licenses')->json('files'), 'path');
        $this->assertContains('6th Box NO.F1_F20/Licence_A.dat', $paths);
        $this->assertContains('6th Box NO.F1_F20/6th Box NO.F1_F20.xlsx', $paths);
        $this->assertContains('1/Licence_B.dat', $paths);
        $this->assertCount(3, $paths);
    }

    public function test_only_admin(): void
    {
        $this->asUser($this->worker('master'))->get('/comm-profile')->assertForbidden();
        $this->asUser($this->worker('executor'))->post('/comm-licenses-folder', [])->assertForbidden();
    }
}
