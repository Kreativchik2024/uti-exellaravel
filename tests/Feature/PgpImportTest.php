<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PgpRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Support\SiteHelpers;
use Tests\TestCase;

/** Загрузка бортов ПГП из Excel-журнала: предпросмотр, дубли, сопоставление ФИО. */
class PgpImportTest extends TestCase
{
    use RefreshDatabase, SiteHelpers;

    private function csv(array $lines): UploadedFile
    {
        $text = implode("\n", array_map(fn ($l) => implode(';', $l), $lines));
        return UploadedFile::fake()->createWithContent('журнал.csv', $text);
    }

    public function test_preview_matches_workers_skips_duplicates_and_saves(): void
    {
        $master = $this->worker('master');
        Employee::create(['last_name' => 'Кузнецов', 'first_name' => 'Пётр', 'middle_name' => 'Ильич', 'short_name' => 'Кузнецов П.И.', 'is_active' => true]);
        $existing = PgpRecord::create(['record_date' => '2026-11-01', 'post_number' => 1, 'worker1' => 'Кузнецов П.И.',
            'board_number' => 'УМН-100', 'status' => 'Сдан']);

        $file = $this->csv([
            ['Отчёт ПГП'],
            ['Дата', 'Номер поста', 'ФИО 1', 'ФИО 2', 'ФИО 3', 'Время начала работ', 'Время завершения', 'Время ожидания ОТК', 'Номер борта',
             'IP адрес модуля связи типа MANET/MESH', 'Номер Фезюляжа', 'Сдан', 'Неисправен', 'Причина неисправности', 'Действия предпринятые для анализа отказа'],
            ['10.11.2026', '3', 'Кузнецоф П.', 'Сидорчук А.А.', '', '08:30', '09:15', '10', 'УМН-200', '15', 'Ф-1', '1', '', '', ''],
            ['10.11.2026', '3', 'кузнецов п.и.', '', '', '09:30', '10:00', '', 'УМН-100', '16', 'Ф-2', '1', '', '', ''],   // уже в базе
            ['10.11.2026', '4', 'Кузнецов П.И.', '', '', '10:00', '21:30', '', 'МН-7', '', 'Ф-3', '', '+', 'Нет связи', 'Замена модуля'],
            ['10.11.2026', '4', 'Кузнецов П.И.', '', '', '10:00', '11:00', '', 'мн-7', '', '', '1', '', '', ''],   // повтор в файле
            ['', '4', 'Кузнецов П.И.', '', '', '', '', '', 'СОН-1', '', '', '1', '', '', ''],   // нет даты
        ]);

        $res = $this->asUser($master)->post('/records/pgp/import/preview', ['file' => $file])->assertOk();
        $rows = $res->viewData('result')['rows'];
        $this->assertCount(5, $rows);
        $this->assertSame(['Кузнецов П.И.', 'similar'], [$rows[0]['workers'][0]['name'], $rows[0]['workers'][0]['kind']]);
        $this->assertSame('unknown', $rows[0]['workers'][1]['kind']);
        $this->assertStringContainsString('#' . $existing->id, $rows[1]['duplicate']);
        $this->assertNull($rows[2]['duplicate']);
        $this->assertSame('Не сдан', $rows[2]['data']['status']);
        $this->assertStringContainsString('повтор', $rows[3]['duplicate']);
        $this->assertNotNull($rows[4]['error']);
        $this->assertSame(1, PgpRecord::count(), 'предпросмотр ничего не пишет');

        $this->asUser($master)->post('/records/pgp/import', ['token' => $res->viewData('token')])
            ->assertRedirect(route('records.index', 'pgp'));

        $this->assertSame(3, PgpRecord::count());
        $a = PgpRecord::where('board_number', 'УМН-200')->first();
        $this->assertSame(['Кузнецов П.И.', 'Сидорчук А.А.', 'УМН', '192.168.1.15', 3],
            [$a->worker1, $a->worker2, $a->board_type, $a->ip_address, $a->post_number]);
        $this->assertSame('2026-11-10 09:15', $a->created_at->format('Y-m-d H:i'));
        $b = PgpRecord::where('board_number', 'МН-7')->first();
        $this->assertSame(['Не сдан', 'Нет связи', 'МН'], [$b->status, $b->defect_reason, $b->board_type]);

        // повторная загрузка того же предпросмотра ничего не добавит
        $this->asUser($master)->post('/records/pgp/import', ['token' => $res->viewData('token')])->assertRedirect();
        $this->assertSame(3, PgpRecord::count());
    }

    public function test_ods_file_picks_pgp_sheet_and_sheet_can_be_switched(): void
    {
        $master = $this->worker('master');
        Employee::create(['last_name' => 'Кузнецов', 'first_name' => 'Пётр', 'middle_name' => 'Ильич', 'short_name' => 'Кузнецов П.И.', 'is_active' => true]);

        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $book->getActiveSheet()->setTitle('Сводка')->fromArray([['Итого за месяц', 42]]);
        $pgp = $book->createSheet()->setTitle('ПГП');
        $pgp->fromArray([
            ['Дата', 'Номер поста', 'ФИО 1', 'ФИО 2', 'ФИО 3', 'Время начала работ', 'Время завершения', 'Номер борта', 'Сдан', 'Неисправен'],
            [\PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new \DateTime('2026-11-05')), 2, 'Кузнецов П.И.', '', '', 8.5 / 24, 9.75 / 24, 'СОН-55', 1, ''],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'pgp') . '.ods';
        (new \PhpOffice\PhpSpreadsheet\Writer\Ods($book))->save($path);
        $file = new UploadedFile($path, 'журнал.ods', null, null, true);

        $res = $this->asUser($master)->post('/records/pgp/import/preview', ['file' => $file])->assertOk();
        $this->assertSame('ПГП', $res->viewData('sheet'), 'выбран лист «ПГП», а не первый');
        $this->assertSame(['Сводка' => false, 'ПГП' => true], $res->viewData('sheets'));
        $row = $res->viewData('result')['rows'][0];
        $this->assertSame(['2026-11-05', 2, '08:30', '09:45', 'СОН'],
            [$row['data']['record_date'], $row['data']['post_number'], $row['data']['work_start'], $row['data']['work_end'], $row['data']['board_type']]);

        $token = $res->viewData('token');
        // другой лист — ошибка «нет заголовков», загружать нечего
        $other = $this->asUser($master)->post('/records/pgp/import/sheet', ['token' => $token, 'sheet' => 'Сводка'])->assertOk();
        $this->assertNotNull($other->viewData('result')['error']);
        // обратно на ПГП и загрузка
        $this->asUser($master)->post('/records/pgp/import/sheet', ['token' => $token, 'sheet' => 'ПГП'])->assertOk();
        $this->asUser($master)->post('/records/pgp/import', ['token' => $token])->assertRedirect(route('records.index', 'pgp'));

        $rec = PgpRecord::firstOrFail();
        $this->assertSame(['СОН-55', 'Кузнецов П.И.', '2026-11-05 09:45'], [$rec->board_number, $rec->worker1, $rec->created_at->format('Y-m-d H:i')]);
        @unlink($path);
    }

    public function test_import_is_for_master_and_admin_only(): void
    {
        $this->asUser($this->worker('executor'))->get('/records/pgp/import')->assertForbidden();
        $this->asUser($this->worker('master'))->get('/records/pgp/import')->assertOk();
    }
}
