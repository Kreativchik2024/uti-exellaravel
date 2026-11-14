<?php

namespace Tests\Feature;

use App\Models\Cross3Record;
use App\Models\Employee;
use App\Models\NightCameraAssemblyRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Support\SiteHelpers;
use Tests\TestCase;

/** Загрузка из файла в любую вкладку: колонки по настройкам вкладки, лишние пропускаются, дубли не грузятся. */
class RecordImportTest extends TestCase
{
    use RefreshDatabase, SiteHelpers;

    private function ods(array $sheets): UploadedFile
    {
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $book->removeSheetByIndex(0);
        foreach ($sheets as $title => $rows) $book->createSheet()->setTitle($title)->fromArray($rows, null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'imp') . '.ods';
        (new \PhpOffice\PhpSpreadsheet\Writer\Ods($book))->save($path);

        return new UploadedFile($path, 'учёт.ods', null, null, true);
    }

    public function test_cross3_maps_columns_by_labels_and_skips_unknown_columns_and_duplicates(): void
    {
        $master = $this->worker('master');
        Employee::create(['last_name' => 'Кузнецов', 'first_name' => 'Пётр', 'middle_name' => 'Ильич', 'short_name' => 'Кузнецов П.И.', 'is_active' => true]);
        Cross3Record::create(['record_date' => '2026-11-03', 'serial_number' => 'K3-001', 'worker1' => 'Кузнецов П.И.', 'is_working' => 'Исправен']);

        $file = $this->ods([
            'ПГП' => [['Дата', 'ФИО 1', 'Номер борта'], ['01.11.2026', 'Кузнецов', 'УМН-1']],
            'Кросс 3' => [
                ['Дата', 'Заводской номер', 'ПК версия', 'ФИО 1', 'Исправен', 'Барометр', 'Цвет корпуса'],
                ['03.11.2026', 'K3-001', '', 'Кузнецов П.И.', 'Исправен', '', 'синий'],          // уже в базе
                ['03.11.2026', 'K3-002', '1.4', 'Кузнецоф П.', 'нет', 'работает', 'красный'],
                ['04.11.2026', 'K3-003', '', 'Кузнецов П.И.', 'что-то', '', ''],              // обязательное «Исправен» не распознано
            ],
        ]);

        $res = $this->asUser($master)->post('/records/cross3/import/preview', ['file' => $file])->assertOk();
        $this->assertSame('Кросс 3', $res->viewData('sheet'), 'выбран лист с названием вкладки');
        $this->assertSame(['ПГП' => false, 'Кросс 3' => true], $res->viewData('sheets'), 'лист ПГП для вкладки «Кросс 3» не подходит');
        $rows = $res->viewData('result')['rows'];
        $this->assertNotNull($rows[0]['duplicate']);
        $this->assertNull($rows[1]['error']);
        $this->assertSame(['K3-002', '1.4', 'Неисправен', 'Работает', 'Кузнецов П.И.'],
            [$rows[1]['data']['serial_number'], $rows[1]['data']['pc_version'], $rows[1]['data']['is_working'], $rows[1]['data']['barometer_status'], $rows[1]['data']['worker1']]);
        $this->assertArrayNotHasKey('Цвет корпуса', $rows[1]['data']);
        $this->assertNotNull($rows[2]['error']);

        $this->asUser($master)->post('/records/cross3/import', ['token' => $res->viewData('token')])->assertRedirect(route('records.index', 'cross3'));
        $this->assertSame(2, Cross3Record::count());
        $this->assertSame('2026-11-03 12:00', Cross3Record::where('serial_number', 'K3-002')->first()->created_at->format('Y-m-d H:i'));
    }

    public function test_bool_field_and_qty_default(): void
    {
        $master = $this->worker('master');
        $file = $this->ods(['Лист1' => [
            ['Дата', '№ камеры', 'Дневная камера прошита', 'Исполнитель 1'],
            ['05.11.2026', 'CAM-9', 'да', $this->nameOf($master)],
        ]]);

        $res = $this->asUser($master)->post('/records/night_camera_assembly/import/preview', ['file' => $file])->assertOk();
        $this->asUser($master)->post('/records/night_camera_assembly/import', ['token' => $res->viewData('token')])->assertRedirect();

        $rec = NightCameraAssemblyRecord::firstOrFail();
        $this->assertSame(['CAM-9', 1, true], [$rec->camera_serial, (int) $rec->qty, (bool) $rec->flashed_with_day]);
    }

    public function test_tab_must_exist_and_import_is_for_master_only(): void
    {
        $this->asUser($this->worker('master'))->get('/records/no_such_tab/import')->assertNotFound();
        $this->asUser($this->worker('executor'))->get('/records/cross3/import')->assertForbidden();
        $this->asUser($this->worker('master'))->get('/records/cross3/import')->assertOk()->assertSee('Кросс 3');

        $master = $this->worker('master');
        foreach (\App\Models\Section::where('slug', \App\Models\Section::DEFAULT_SLUG)->first()->sheets() as $slug => $cfg) {
            if (empty($cfg['model'])) continue;
            $this->asUser($master)->get("/records/$slug/import")->assertOk();
        }
    }
}
