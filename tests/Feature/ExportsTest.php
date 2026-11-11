<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PgpRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\Support\SiteHelpers;
use Tests\TestCase;

/** Выгрузки в Excel: настоящие таблицы — шапка, числа числами, итоги, а не «всё в одной колонке». */
class ExportsTest extends TestCase
{
    use RefreshDatabase, SiteHelpers;

    private function book(TestResponse $res): Spreadsheet
    {
        $res->assertOk()->assertDownload();
        $copy = tempnam(sys_get_temp_dir(), 'exp') . '.xlsx';
        copy($res->baseResponse->getFile()->getPathname(), $copy);

        return IOFactory::load($copy);
    }

    /** Номер строки, где в колонке A стоит $text. */
    private function rowOf(Worksheet $ws, string $text): int
    {
        foreach ($ws->getRowIterator() as $row) {
            if ($ws->getCell('A' . $row->getRowIndex())->getValue() === $text) return $row->getRowIndex();
        }
        $this->fail("Нет строки «{$text}» на листе {$ws->getTitle()}");
    }

    private function pgp(string $worker, string $status): void
    {
        PgpRecord::create(['record_date' => now()->toDateString(), 'post_number' => 1, 'worker1' => $worker,
            'board_number' => 'RA-' . random_int(1, 99999), 'ip_address' => '192.168.1.5', 'status' => $status,
            'defect_reason' => $status === 'Сдан' ? null : 'нет связи', 'created_by' => $this->admin->id]);
    }

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create(['name' => 'Админ']);
    }

    public function test_stats_export_is_structured_table(): void
    {
        $this->pgp('Иванов И.', 'Сдан');
        $this->pgp('Иванов И.', 'Не сдан');

        $book = $this->book($this->asUser($this->admin)->get('/stats/export'));
        $ws = $book->getSheetByName('Сводка');
        $this->assertSame('Статистика', $ws->getCell('A1')->getValue());
        $this->assertSame('Участок', $ws->getCell('A2')->getValue());

        $head = $this->rowOf($ws, 'Вкладка');
        $this->assertSame(['Вкладка', 'Учёт', 'Всего', 'Исправных', 'Брак', 'Брак, %', 'Исполнителей'], $ws->rangeToArray("A{$head}:G{$head}")[0]);
        $this->assertTrue($ws->getStyle("A{$head}")->getFont()->getBold());
        $this->assertSame('A' . ($head + 1), $ws->getFreezePane());

        $pgp = $this->rowOf($ws, 'ПГП (борта)');
        $this->assertSame(2, $ws->getCell("C{$pgp}")->getValue());            // число, не текст
        $this->assertEquals(0.5, $ws->getCell("F{$pgp}")->getValue());
        $this->assertSame('0.0%', $ws->getStyle("F{$pgp}")->getNumberFormat()->getFormatCode());
        $this->rowOf($ws, 'Итого (вкладки с учётом брака)');
        $this->rowOf($ws, 'По исполнителям (все вкладки)');

        $flat = $book->getSheetByName('Исполнители по вкладкам');
        $this->assertSame(['Вкладка', 'Исполнитель', 'Записей', 'Доля во вкладке'], $flat->rangeToArray('A1:D1')[0]);
        $this->assertSame('ПГП (борта)', $flat->getCell('A2')->getValue());
        $this->assertNotSame('', $flat->getAutoFilter()->getRange());
    }

    public function test_shift_report_export_is_structured_table(): void
    {
        $this->pgp('Иванов И.', 'Не сдан');
        $book = $this->book($this->asUser($this->admin)->get('/reports/shift?date=' . now()->format('Y-m-d') . '&shift=' . (now()->hour >= 8 && now()->hour < 20 ? 1 : 2) . '&excel=1'));

        $ws = $book->getSheetByName('Смена');
        $this->assertSame('Отчёт по смене', $ws->getCell('A1')->getValue());
        $this->rowOf($ws, 'По исполнителям');
        $this->rowOf($ws, 'По вкладкам');
        $this->rowOf($ws, 'Итого');
        $this->assertSame(['Исполнитель', 'Вкладка', 'Записей', 'Брак', 'Брак, %'], $book->getSheetByName('Подробно')->rangeToArray('A1:E1')[0]);
    }

    public function test_employees_export_loads_back(): void
    {
        Employee::create(['last_name' => 'Сидоров', 'first_name' => 'Пётр', 'middle_name' => 'Ильич', 'short_name' => 'Сидоров П.И.', 'position' => 'Монтажник', 'is_active' => true]);

        $book = $this->book($this->asUser($this->admin)->get('/employees/export'));
        $ws = $book->getSheet(0);
        $this->assertSame(['Фамилия', 'Имя', 'Отчество', 'Кратко', 'Должность', 'Участок', 'Активен', 'Учётная запись'], $ws->rangeToArray('A1:H1')[0]);
        $this->assertSame('Сидоров', $ws->getCell('A2')->getValue());

        // Поправили в Excel и загрузили обратно — обновился тот же сотрудник
        $ws->setCellValue('E2', 'Мастер');
        $path = tempnam(sys_get_temp_dir(), 'emp') . '.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($path);
        $this->asUser($this->admin)->post('/employees/import', ['file' => new UploadedFile($path, 'сотрудники.xlsx', null, null, true)])
            ->assertSessionHas('ok');
        $this->assertSame(1, Employee::count());
        $this->assertSame('Мастер', Employee::first()->position);
    }

    public function test_templates_are_excel_with_instructions(): void
    {
        foreach (['/employees/template' => 'Сотрудники', '/fault-codes/template' => 'Коды'] as $url => $first) {
            $book = $this->book($this->asUser($this->admin)->get($url));
            $this->assertSame($first, $book->getSheet(0)->getTitle(), $url);
            $this->assertSame('Как заполнять', $book->getSheet(1)->getTitle(), $url);
        }
    }

    public function test_text_that_looks_like_formula_stays_text(): void
    {
        Employee::create(['last_name' => '=HYPERLINK("x")', 'first_name' => 'А', 'short_name' => 'Тест', 'is_active' => true]);
        $ws = $this->book($this->asUser($this->admin)->get('/employees/export'))->getSheet(0);

        $this->assertSame('=HYPERLINK("x")', $ws->getCell('A2')->getValue());
        $this->assertSame('s', $ws->getCell('A2')->getDataType());
    }
}
