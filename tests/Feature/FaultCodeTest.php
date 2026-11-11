<?php

namespace Tests\Feature;

use App\Models\FaultCode;
use App\Models\PgpRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\SiteHelpers;
use Tests\TestCase;

/** Справочник кодов неисправностей: загрузка из файла и выпадающий список в формах. */
class FaultCodeTest extends TestCase
{
    use RefreshDatabase, SiteHelpers;

    private function csv(string $content, string $name = 'codes.csv'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'fc');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }

    public function test_csv_from_russian_excel_cp1251_semicolon_is_imported(): void
    {
        $admin = User::factory()->admin()->create();
        $csv = mb_convert_encoding("Код;Описание неисправности\nE01;Нет связи с модулем\nE02;Не определяется камера\n;пустой код\nE01;повтор\n", 'Windows-1251', 'UTF-8');

        $this->asUser($admin)->post('/fault-codes/import', ['file' => $this->csv($csv)])
            ->assertSessionHas('ok', fn ($m) => str_contains($m, 'добавлено 2'));

        $this->assertSame('Нет связи с модулем', FaultCode::where('code', 'E01')->value('description'));
        $this->assertSame(2, FaultCode::count());

        // Повторная загрузка обновляет описание, а не дублирует
        $this->asUser($admin)->post('/fault-codes/import', ['file' => $this->csv("Код;Описание\nE01;Нет связи (новое)\n")])
            ->assertSessionHas('ok', fn ($m) => str_contains($m, 'обновлено 1'));
        $this->assertSame(2, FaultCode::count());
        $this->assertSame('Нет связи (новое)', FaultCode::where('code', 'E01')->value('description'));
    }

    public function test_xlsx_without_header_is_imported(): void
    {
        $admin = User::factory()->admin()->create();
        $book = new Spreadsheet();
        $book->getActiveSheet()->fromArray([['101', 'Перегрев двигателя'], ['102', 'Обрыв провода']]);
        $path = tempnam(sys_get_temp_dir(), 'fc') . '.xlsx';
        (new Xlsx($book))->save($path);

        $this->asUser($admin)->post('/fault-codes/import', ['file' => new UploadedFile($path, 'коды.xlsx', null, null, true)])
            ->assertSessionHas('ok');
        $this->assertSame('Перегрев двигателя', FaultCode::where('code', '101')->value('description'));
    }

    public function test_codes_drop_down_in_record_form_and_description_on_record_page(): void
    {
        FaultCode::create(['code' => 'E07', 'description' => 'Сбой калибровки']);
        $user = $this->worker();

        $this->asUser($user)->get('/records/pgp/create')->assertOk()
            ->assertSee('faultCode(', false)->assertSee('E07', false)->assertSee('Сбой калибровки', false);
        // Код есть во всех вкладках, не только в ПГП
        $this->asUser($user)->get('/records/cross1/create')->assertOk()->assertSee('E07', false);

        $rec = PgpRecord::create(['record_date' => now()->toDateString(), 'post_number' => 1, 'worker1' => $this->nameOf($user),
            'board_number' => 'RA-1', 'ip_address' => '192.168.1.5', 'status' => 'Не сдан', 'fault_code' => 'E07', 'defect_reason' => 'x',
            'fuselage_number' => 'F', 'frame_serial' => 'M']);
        $this->asUser($user)->get("/records/pgp/{$rec->id}")->assertSee('Сбой калибровки');
    }

    public function test_only_admin_manages_codes(): void
    {
        $this->asUser($this->worker())->get('/fault-codes')->assertForbidden();
        $this->asUser($this->worker())->post('/fault-codes', ['code' => 'X'])->assertForbidden();

        $admin = User::factory()->admin()->create();
        $this->asUser($admin)->post('/fault-codes', ['code' => 'E09', 'description' => 'Тест'])->assertSessionHas('ok');
        $code = FaultCode::where('code', 'E09')->firstOrFail();
        $this->asUser($admin)->patch("/fault-codes/{$code->id}", ['code' => 'E09', 'description' => 'Новое', 'is_active' => '1'])->assertSessionHas('ok');
        $this->assertSame('Новое', $code->fresh()->description);
        $this->asUser($admin)->get('/fault-codes')->assertOk()->assertSee('E09');
        $this->asUser($admin)->delete("/fault-codes/{$code->id}")->assertSessionHas('ok');
        $this->assertSame(0, FaultCode::count());
    }
}
