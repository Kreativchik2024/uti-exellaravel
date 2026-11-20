<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SiteHelpers;
use Tests\TestCase;

/** Лицензии модулей связи: загрузка администратором, поиск S/N, выдача программам по ключу станции. */
class CommLicensesTest extends TestCase
{
    use RefreshDatabase, SiteHelpers;

    private const KEY = 'test-station-key';
    private const SN = 'fbe8b5b858995d597ce5489a2fe95311';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['services.stations.key' => self::KEY]);
    }

    /** .xlsx — zip с XML, серийники в sharedStrings (как настоящая таблица поставки). */
    private function xlsx(array $serials, string $name = 'Final 6th-480pcs-Box NO.F1#~F20#.xlsx'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xl');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('xl/sharedStrings.xml', '<sst>' . implode('', array_map(fn ($s) => "<si><t>$s</t></si>", $serials)) . '</sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet/>');
        $zip->close();

        return new UploadedFile($tmp, $name, null, null, true);
    }

    private function station(string $uri)
    {
        return $this->withHeaders(['X-Station-Key' => self::KEY, 'Accept' => 'application/json'])->get("/api/station/$uri");
    }

    public function test_admin_uploads_batch_and_finds_serial(): void
    {
        $admin = $this->worker('admin');
        $this->asUser($admin)->post('/comm-licenses', [
            'dat'  => UploadedFile::fake()->createWithContent('Licence_2610191425_480__All_Avail.dat', 'LICDATA'),
            'xlsx' => $this->xlsx([self::SN, str_repeat('a', 32)]),
        ])->assertRedirect('/comm-licenses');

        $page = $this->asUser($admin)->get('/comm-licenses')->assertOk();
        $page->assertSee('Final 6th-480pcs-Box NO.F1_F20')->assertSee('Licence_2610191425_480__All_Avail.dat');

        $this->asUser($admin)->get('/comm-licenses?sn=' . strtoupper(self::SN))->assertSee('есть в:');
        $this->asUser($admin)->get('/comm-licenses?sn=' . str_repeat('0', 32))->assertSee('нет ни в одной партии');
    }

    public function test_station_lists_and_downloads(): void
    {
        $admin = $this->worker('admin');
        $this->asUser($admin)->post('/comm-licenses', [
            'batch' => 'F1-F20',
            'dat'   => UploadedFile::fake()->createWithContent('Licence_F.dat', 'LICDATA'),
            'xlsx'  => $this->xlsx([self::SN]),
        ]);

        $files = $this->station('comm-licenses')->assertOk()->json('files');
        $this->assertEqualsCanonicalizing(['F1-F20/Licence_F.dat', 'F1-F20/Final 6th-480pcs-Box NO.F1_F20.xlsx'],
            array_column($files, 'path'));
        $dat = collect($files)->firstWhere('path', 'F1-F20/Licence_F.dat');
        $this->assertSame(hash('sha256', 'LICDATA'), $dat['sha256']);

        $res = $this->station('comm-licenses/download?path=' . urlencode('F1-F20/Licence_F.dat'))->assertOk();
        $this->assertSame('LICDATA', $res->streamedContent());

        // чужие пути и без ключа — нельзя
        $this->station('comm-licenses/download?path=' . urlencode('../.env'))->assertNotFound();
        $this->flushHeaders()->getJson('/api/station/comm-licenses')->assertStatus(401);
    }

    public function test_reupload_replaces_and_delete(): void
    {
        $admin = $this->worker('admin');
        $up = fn (string $content) => $this->asUser($admin)->post('/comm-licenses', [
            'batch' => 'C1-C21', 'dat' => UploadedFile::fake()->createWithContent("L_$content.dat", $content)]);
        $up('one');
        $up('two');
        $this->assertSame(['C1-C21/L_two.dat'], array_column($this->station('comm-licenses')->json('files'), 'path'));

        $this->asUser($admin)->delete('/comm-licenses/C1-C21')->assertRedirect('/comm-licenses');
        $this->assertSame([], $this->station('comm-licenses')->json('files'));
    }

    public function test_only_admin(): void
    {
        $this->asUser($this->worker('master'))->get('/comm-licenses')->assertForbidden();
        $this->asUser($this->worker('executor'))->post('/comm-licenses', [])->assertForbidden();
    }
}
