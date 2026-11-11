<?php

namespace Tests\Feature;

use App\Models\FaultCode;
use App\Models\FaultCodeGroup;
use App\Models\User;
use App\Services\FaultCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\SiteHelpers;
use Tests\TestCase;

/** Коды неисправностей по таблице предприятия: подгруппы, разделы, нумерация, подбор по описанию, выгрузка. */
class FaultCodeHierarchyTest extends TestCase
{
    use RefreshDatabase, SiteHelpers;

    private const TABLE = "порядковый номер;код подгруппы;Раздел;Описание;общий вид;Разъяснение\n"
        . "1;09_Кросс 3 (Cross 3);01_БЧ;Неисправна БЧ;C(3).1.1;\n"
        . "2;09_Кросс 3 (Cross 3);03_МИНИ ПК;Неисправен мини ПК;C(3).3.1;\n"
        . "3;09_Кросс 3 (Cross 3);03_МИНИ ПК;Не загружается операционная система;C(3).3.10;\n"
        . "4;10_Кросс 1 (Cross 1);Нет;Не включается кросс;C(1).1.5;\n"
        . "5;11_Кросс 1 (Cross 1);Нет;Сгорел регулятор;C(1).1.5;\n"
        . "6;05_Роутер;Нет;Не пингуется роутер;R.1.1;Проверить кабель и питание\n";

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    private function upload(string $content, string $name = 'коды.csv'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'fc');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function importTable(): void
    {
        $this->asUser($this->admin)->post('/fault-codes/import', ['file' => $this->upload(self::TABLE)])
            ->assertSessionHas('ok')
            ->assertSessionHasErrors('file');   // C(1).1.5 повторяется — об этом сказано
    }

    public function test_import_builds_subgroups_and_sections(): void
    {
        $this->importTable();

        $cross3 = FaultCodeGroup::whereNull('parent_id')->where('name', 'Кросс 3 (Cross 3)')->firstOrFail();
        $this->assertSame(9, (int) $cross3->sort);
        $this->assertContains('cross3', $cross3->sheets);
        $this->assertContains('pgp', $cross3->sheets);

        $mini = FaultCodeGroup::where('parent_id', $cross3->id)->where('name', 'МИНИ ПК')->firstOrFail();
        $this->assertSame('C(3).3', $mini->prefix);
        $this->assertSame($mini->id, FaultCode::where('code', 'C(3).3.10')->value('group_id'));

        // «10_Кросс 1» и «11_Кросс 1» — одна подгруппа; повтор кода не перезаписал первую неисправность
        $this->assertSame(1, FaultCodeGroup::where('name', 'Кросс 1 (Cross 1)')->count());
        $this->assertSame('Не включается кросс', FaultCode::where('code', 'C(1).1.5')->value('description'));
        $this->assertSame('Проверить кабель и питание', FaultCode::where('code', 'R.1.1')->value('hint'));
        $this->assertSame(5, FaultCode::count());
    }

    public function test_merged_cells_and_reupload_move_codes_to_right_groups(): void
    {
        // Объединённые ячейки: подгруппа и раздел написаны только в первой строке
        $table = "код подгруппы;Раздел;Описание;общий вид\n"
            . "09_Кросс 3 (Cross 3);03_МИНИ ПК;Неисправен мини ПК;C(3).3.1\n"
            . ";;Не загружается ОС;C(3).3.2\n"
            . ";02_КОМПАС;Неисправен компас;C(3).2.1\n"
            . "05_Роутер;Нет;Не пингуется роутер;R.1.1\n"
            . ";;Нет питания роутера;R.1.2\n";
        $this->asUser($this->admin)->post('/fault-codes/import', ['file' => $this->upload($table)])->assertSessionHasNoErrors();

        $groupOf = fn ($code) => FaultCode::where('code', $code)->first()->group?->label();
        $this->assertSame('Кросс 3 (Cross 3) · МИНИ ПК', $groupOf('C(3).3.2'));
        $this->assertSame('Кросс 3 (Cross 3) · КОМПАС', $groupOf('C(3).2.1'));
        $this->assertSame('Роутер', $groupOf('R.1.2'));

        // Исправили таблицу: R.1.2 — в ХАБ, мини ПК — без раздела. Коды переезжают, пустой раздел убирается
        $fixed = "код подгруппы;Раздел;Описание;общий вид\n"
            . "09_Кросс 3 (Cross 3);Нет;Неисправен мини ПК;C(3).3.1\n"
            . "09_Кросс 3 (Cross 3);Нет;Не загружается ОС;C(3).3.2\n"
            . "09_Кросс 3 (Cross 3);02_КОМПАС;Неисправен компас;C(3).2.1\n"
            . "05_Роутер;Нет;Не пингуется роутер;R.1.1\n"
            . "07_ХАБ;Нет;Нет питания хаба;R.1.2\n";
        $this->asUser($this->admin)->post('/fault-codes/import', ['file' => $this->upload($fixed)])
            ->assertSessionHas('ok', fn ($m) => str_contains($m, 'убрано пустых'));

        $this->assertSame('Кросс 3 (Cross 3)', $groupOf('C(3).3.2'));
        $this->assertSame('ХАБ', $groupOf('R.1.2'));
        $this->assertSame(0, FaultCodeGroup::where('name', 'МИНИ ПК')->count());
    }

    public function test_system_code_column_is_not_taken_for_fault_code(): void
    {
        // Две колонки «код…»: код системы повторяется, код неисправности — свой у каждой строки
        $both = "Код системы;Описание;Код неисправности\n"
            . "SYS_CAM_N;Неисправна ночная камера;G3.1\n"
            . "SYS_CAM_N;Чрезмерно греется ночная камера;G3.4\n"
            . "SYS_LINK;Не пингуется роутер;G.5.5\n"
            . "SYS_LINK;Неисправен роутер;G.5.1\n";
        $this->asUser($this->admin)->post('/fault-codes/import', ['file' => $this->upload($both)])->assertSessionHasNoErrors();
        $this->assertSame(['G.5.1', 'G.5.5', 'G3.1', 'G3.4'], FaultCode::orderBy('code')->pluck('code')->all());

        // Только код системы — ничего не загружается, понятное объяснение
        $rows = "Код;Описание\n";
        foreach (range(1, 20) as $n) $rows .= ($n % 2 ? 'SYS_CAM_N' : 'SYS_PWR') . ";Неисправность {$n}\n";
        $this->asUser($this->admin)->post('/fault-codes/import', ['file' => $this->upload($rows)])
            ->assertSessionHasErrors('file');
        $msg = session('errors')->first('file');
        $this->assertStringContainsString('Ничего не загружено', $msg);
        $this->assertStringContainsString('SYS_CAM_N — 10 раз', $msg);
        $this->assertSame(0, FaultCode::where('code', 'like', 'SYS%')->count());
    }

    public function test_wipe_keeps_codes_in_records_and_relinks_them_on_next_upload(): void
    {
        $this->importTable();
        $rec = fn (string $code) => \App\Models\PgpRecord::create(['record_date' => now()->toDateString(), 'post_number' => 1, 'worker1' => 'Иванов И.',
            'board_number' => 'RA-' . random_int(1, 99999), 'ip_address' => '192.168.1.5', 'status' => 'Не сдан', 'defect_reason' => 'x', 'fault_code' => $code]);
        $same = $rec('C(3).3.1');      // код в новой таблице тот же
        $moved = $rec('R.1.1');        // в новой таблице у этой неисправности другой код
        $orphan = $rec('C(3).1.1');    // в новой таблице такой неисправности нет

        // Без подтверждения не стирается
        $this->asUser($this->admin)->post('/fault-codes/wipe', ['confirm' => 'да'])->assertSessionHasErrors('confirm');
        $this->assertSame(5, FaultCode::count());

        $this->asUser($this->admin)->post('/fault-codes/wipe', ['confirm' => 'СТЕРЕТЬ'])->assertSessionHas('ok');
        $this->assertSame(0, FaultCode::count());
        $this->assertSame(0, FaultCodeGroup::count());
        $this->assertSame('R.1.1', $moved->fresh()->fault_code);                       // записи не тронуты
        $this->assertSame('Не пингуется роутер', FaultCode::mapFor(null)['R.1.1']);    // расшифровка — из архива

        $new = "НПП;Р1;Р2;Р3;Описание;общий вид;Разъяснение\n"
            . "1;01_МОТОРАМА;01_Кросс 3 (Cross 3);03_МИНИ ПК;Неисправен мини ПК;C(3).3.1;\n"
            . "2;02_КРОНШТЕЙН КАМЕРЫ;05_Роутер;СМ ОПИСАНИЕ;Не пингуется роутер;G.5.5;\n";
        $this->asUser($this->admin)->post('/fault-codes/import', ['file' => $this->upload($new)])
            ->assertSessionHas('ok', fn ($m) => str_contains($m, 'привязано') && str_contains($m, 'без пары'));

        $this->assertSame('C(3).3.1', $same->fresh()->fault_code);
        $this->assertSame('G.5.5', $moved->fresh()->fault_code);                       // перепривязан по описанию
        $this->assertSame('C(3).1.1', $orphan->fresh()->fault_code);                   // пары нет — как был
        $this->assertSame('Проверить кабель и питание', FaultCode::where('code', 'G.5.5')->value('hint'));   // разъяснение перенеслось
        $this->assertSame(['C(3).1.1'], \App\Models\FaultCodeArchive::where('code', 'C(3).1.1')->pluck('code')->all());
    }

    public function test_failure_analysis_journal_builds_codes_from_system_and_root_cause(): void
    {
        $journal = "Отказ заявленный при первичном предьявлении;Действия предпринятые для анализа отказа;Дефект при анализе отказа;Результат;Код;Код первопричины;Формализованный дефект\n"
            . "Нет изображения;Прозвонка разъёма;Нет контакта;Ремонт;SYS_CAM_N;HW-CONN-PIN;Нет контакта в разъёме камеры\n"
            . "Нет картинки;;Пин загнут;Ремонт;SYS_CAM_N;HW-CONN-PIN;Нет контакта в разъёме камеры.\n"   // повтор — тот же код
            . "Камера не видна;Проверка версии ПО;ПО нет;Прошивка;SYS_CAM_N;SW-FIRM;Отсутствует ПО камеры\n"
            . "Не греется;Осмотр;Загнут пин;Ремонт;SYS_CAM_N;HW-CONN-PIN;Загнут пин сигнального кабеля\n"
            . "Нет связи;Пинг роутера;Обрыв;Замена;SYS_LINK;HW-CONN-LINE;Обрыв кабеля роутера\n"
            . ";;;;;;\n";

        $this->asUser($this->admin)->post('/fault-codes/import', ['file' => $this->upload($journal)])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('ok', fn ($m) => str_contains($m, 'новых кодов 4') && str_contains($m, 'повторов склеено 1'));

        $code = fn ($desc) => FaultCode::where('description', $desc)->first();
        $this->assertSame('SYS_CAM_N.HW-CONN-PIN.1', $code('Нет контакта в разъёме камеры')->code);
        $this->assertSame('SYS_CAM_N.HW-CONN-PIN.2', $code('Загнут пин сигнального кабеля')->code);
        $this->assertSame('SYS_CAM_N.SW-FIRM.1', $code('Отсутствует ПО камеры')->code);
        $this->assertSame('SYS_LINK.HW-CONN-LINE.1', $code('Обрыв кабеля роутера')->code);
        $this->assertSame('SYS_CAM_N · HW-CONN-PIN', $code('Загнут пин сигнального кабеля')->group->label());
        $this->assertSame('Прозвонка разъёма', $code('Нет контакта в разъёме камеры')->hint);

        // Повторная загрузка того же журнала ничего не дублирует; новый дефект продолжает нумерацию
        $this->asUser($this->admin)->post('/fault-codes/import', ['file' => $this->upload($journal
            . "Мигает;Осмотр;Окисление;Чистка;SYS_CAM_N;HW-CONN-PIN;Окисление контактов разъёма\n")])->assertSessionHasNoErrors();
        $this->assertSame(5, FaultCode::count());
        $this->assertSame('SYS_CAM_N.HW-CONN-PIN.3', $code('Окисление контактов разъёма')->code);
        $this->assertSame('SYS_CAM_N.HW-CONN-PIN.4', app(FaultCodes::class)->nextCode(FaultCodeGroup::where('name', 'HW-CONN-PIN')->first()));
    }

    public function test_code_table_is_taken_from_any_sheet_and_cyrillic_c_becomes_latin(): void
    {
        // Первый лист — журнал отказов, второй — таблица кодов с русской «С» в кодах
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $book->getActiveSheet()->setTitle('Лог разметки ОТК')->fromArray([
            ['Отказ заявленный', 'Действия', 'Дефект при анализе отказа', 'Результат', 'Код ', 'Код первопричины', 'Формализованный дефект'],
            ['нет инициации', 'Ремонт', 'Провалился пин', 'ок', 'SYS_ENG', 'HW-CONN-PIN', 'Механическое повреждение пина'],
            ['нет инициации', 'Ремонт', 'Провалился пин', 'ок', 'SYS_ENG', 'HW-CONN-PIN', 'Механическое повреждение пина'],
        ]);
        $book->createSheet()->setTitle('Лист1')->fromArray([
            ['НПП', 'Р1', 'Р2', 'Р3', 'Описание', 'общий вид', 'Разъяснение'],
            [1, '01_МОТОРАМА', '02_КРОСС_1', 'СМ ОПИСАНИЕ', 'Неисправен Cross 1', 'С(1).1.1', null],
            [2, '01_МОТОРАМА', '01_Кросс 3 (Cross 3)', '03_МИНИ ПК', 'Неисправен мини ПК', 'С(3).3.1', null],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'fc') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);

        $this->asUser($this->admin)->post('/fault-codes/import', ['file' => new UploadedFile($path, 'коды.xlsx', null, null, true)])
            ->assertSessionHasNoErrors()->assertSessionHas('ok', fn ($m) => str_contains($m, 'Лист «Лист1»'));

        $this->assertSame(['C(1).1.1', 'C(3).3.1'], FaultCode::orderBy('code')->pluck('code')->all());   // латинская C
        $this->assertSame(0, FaultCode::where('code', 'like', 'SYS%')->count());                        // журнал не грузился
        // «МОТОРАМА» — не вкладка «Регулятор мотора»; Кросс 1 — своя вкладка
        $this->assertSame(['pgp'], FaultCodeGroup::where('name', 'МОТОРАМА')->first()->sheets);
        $this->assertSame(['cross1'], FaultCodeGroup::where('name', 'КРОСС 1')->first()->sheets);
        $this->assertSame('C(1).1.1', \App\Http\Controllers\FaultCodeController::latinCode('С(1).1.1'));
        $this->assertSame('Кросс-1', \App\Http\Controllers\FaultCodeController::latinCode('Кросс-1'));   // русское слово не трогаем
    }

    public function test_form_gets_tree_paths_only_sheet_codes_and_finds_by_one_word(): void
    {
        $this->asUser($this->admin)->post('/fault-codes/import', ['file' => $this->upload(self::LEVELS)]);
        FaultCode::create(['code' => 'X.1', 'description' => 'Код без раздела']);
        $fc = app(FaultCodes::class);

        // Путь по дереву для выбора блок → подблок → коды
        $item = collect($fc->optionsForSheet('pgp'))->firstWhere('code', 'C(3).3.8');
        $this->assertSame(['МОТОРАМА', 'Кросс 3 (Cross 3)', 'МИНИ ПК'], $item['path']);

        // Код без раздела — только в ПГП, во вкладке «Кросс 3» его нет
        $this->assertContains('X.1', array_column($fc->optionsForSheet('pgp'), 'code'));
        $this->assertNotContains('X.1', array_column($fc->optionsForSheet('cross3'), 'code'));

        // Поиск одним словом из описания
        $this->assertContains('C(3).3.8', array_column($fc->suggest('cross3', 'кулер')['items'], 'code'));
    }

    public function test_new_code_continues_numbering_of_the_section(): void
    {
        $this->importTable();
        $mini = FaultCodeGroup::where('name', 'МИНИ ПК')->firstOrFail();

        $this->assertSame('C(3).3.11', app(FaultCodes::class)->nextCode($mini));
    }

    public function test_drop_down_shows_only_codes_of_the_sheet(): void
    {
        $this->importTable();
        $codes = fn ($sheet) => app(FaultCodes::class)->codesForSheet($sheet)->pluck('code')->all();

        $this->assertContains('C(1).1.5', $codes('cross1'));
        $this->assertNotContains('C(3).3.1', $codes('cross1'));
        $this->assertNotContains('R.1.1', $codes('cross1'));
        $this->assertContains('C(3).3.1', $codes('cross3'));
        // ПГП — сборка всего борта: там все коды
        $this->assertContains('R.1.1', $codes('pgp'));
        $this->assertContains('C(3).3.1', $codes('pgp'));
    }

    public function test_resolve_code_by_code_or_description(): void
    {
        $this->importTable();
        $fc = app(FaultCodes::class);

        $this->assertSame('C(3).3.1', $fc->resolve('cross3', 'c(3).3.1'));            // код в другом регистре
        $this->assertSame('C(3).3.1', $fc->resolve('cross3', 'неисправный мини пк'));  // описание своими словами

        // Новая неисправность — новый код в разделе, с пометкой «проверить»
        $code = $fc->resolve('cross3', 'Мини ПК перегревается под нагрузкой', $this->admin->id);
        $this->assertStringStartsWith('C(3).', $code);
        $new = FaultCode::where('code', $code)->firstOrFail();
        $this->assertTrue((bool) $new->needs_review);
        $this->assertSame($this->admin->id, $new->created_by);

        // Одинаковое описание в разных подгруппах — не угадываем
        FaultCode::create(['code' => 'C(3).3.20', 'description' => 'Отсутствует ПО', 'group_id' => FaultCodeGroup::where('name', 'МИНИ ПК')->value('id')]);
        FaultCode::create(['code' => 'R.1.2', 'description' => 'Отсутствует ПО', 'group_id' => FaultCodeGroup::where('name', 'Роутер')->value('id')]);
        $this->expectException(ValidationException::class);
        $fc->resolve('pgp', 'отсутствует ПО');
    }

    /** Таблица предприятия с уровнями Р1–Р3 (строки из настоящей таблицы). */
    private const LEVELS = "НПП;Р1;Р2;Р3;Описание;общий вид;Разъяснение\n"
        . "1;01_МОТОРАМА;02_КРОСС_1;СМ ОПИСАНИЕ;Неисправен Cross 1;C(1).1.1;\n"
        . "2;01_МОТОРАМА;02_КРОСС_1;СМ ОПИСАНИЕ;Неисправен разъём DATA 15;C(1).1.10;\n"
        . "14;04_ВМР;СМ Р3;СМ ОПИСАНИЕ;12_ВМР;C.1.1;\n"
        . "15;04_ВМР;СМ Р3;СМ ОПИСАНИЕ;Отсутствует ПО;C.1.2;\n"
        . "22;01_МОТОРАМА;01_Кросс 3 (Cross 3);01_БЧ;Не горят светодиоды 1-3, 2-4;C(3).1.2;\n"
        . "28;01_МОТОРАМА;01_Кросс 3 (Cross 3);02_КОМПАС;Отсутствует;C(3).2.2;При частом моргании синего светодиода на компасе он не исправен\n"
        . "31;01_МОТОРАМА;01_Кросс 3 (Cross 3);03_МИНИ ПК;Неисправен мини ПК;C(3).3.1;\n"
        . "32;01_МОТОРАМА;01_Кросс 3 (Cross 3);03_МИНИ ПК;Демонтированна Wi-Fi антенна;C(3).3.10;\n"
        . "39;01_МОТОРАМА;01_Кросс 3 (Cross 3);03_МИНИ ПК;Неисправен кулер охлаждения;C(3).3.8;\n"
        . "59;02_КРОНШТЕЙН КАМЕРЫ;05_Роутер;СМ ОПИСАНИЕ;Неисправен роутер;G.5.1;\n"
        . "80;02_КРОНШТЕЙН КАМЕРЫ;01_Дневная камера;СМ ОПИСАНИЕ;Неисправна дневная камера;G1.1;\n"
        . "94;05_КОММУТАЦИЯ;СМ Р3;СМ ОПИСАНИЕ;Неисправен кабель DATA 17;H.1.1;\n"
        . "111;06_ФЮЗЕЛЯЖ;01_КРЫЛЬЯ;СМ ОПИСАНИЕ;Неисправен кабель крыла;W1.2;\n";

    public function test_levels_r1_r2_r3_from_enterprise_table(): void
    {
        $this->asUser($this->admin)->post('/fault-codes/import', ['file' => $this->upload(self::LEVELS)])
            ->assertSessionHas('ok', fn ($m) => str_contains($m, 'добавлено 13'))->assertSessionHasNoErrors();

        $label = fn ($code) => FaultCode::where('code', $code)->first()->group?->label();
        $this->assertSame('МОТОРАМА · Кросс 3 (Cross 3) · МИНИ ПК', $label('C(3).3.8'));
        $this->assertSame('МОТОРАМА · Кросс 3 (Cross 3) · КОМПАС', $label('C(3).2.2'));
        $this->assertSame('МОТОРАМА · КРОСС 1', $label('C(1).1.10'));          // «СМ ОПИСАНИЕ» — третьего уровня нет
        $this->assertSame('ВМР', $label('C.1.2'));                             // «СМ Р3» — второго нет
        $this->assertSame('КРОНШТЕЙН КАМЕРЫ · Роутер', $label('G.5.1'));
        $this->assertSame('ФЮЗЕЛЯЖ · КРЫЛЬЯ', $label('W1.2'));
        $this->assertSame(0, FaultCodeGroup::where('name', 'like', 'СМ%')->count());

        // Новый код мини ПК продолжает его нумерацию
        $this->assertSame('C(3).3.11', app(FaultCodes::class)->nextCode(FaultCodeGroup::where('name', 'МИНИ ПК')->first()));

        // Вкладки: Кросс 3 — во вкладке «Кросс 3», мини ПК — ещё и в «Мини ПК», чужие коды не выпадают
        $codes = fn ($sheet) => app(FaultCodes::class)->codesForSheet($sheet)->pluck('code')->all();
        $this->assertContains('C(3).3.8', $codes('cross3'));
        $this->assertContains('C(3).3.8', $codes('mini_pc'));
        $this->assertNotContains('C(3).2.2', $codes('mini_pc'));
        $this->assertNotContains('C(1).1.1', $codes('cross3'));
        $this->assertContains('C(1).1.1', $codes('cross1'));
        $this->assertContains('G.5.1', $codes('router_firmware'));
        $this->assertContains('W1.2', $codes('pgp'));
        // В списке — по порядку таблицы: C(3).3.1, C(3).3.8, C(3).3.10
        $mini = array_values(array_filter($codes('mini_pc'), fn ($c) => str_starts_with($c, 'C(3).3')));
        $this->assertSame(['C(3).3.1', 'C(3).3.8', 'C(3).3.10'], $mini);

        // Страница справочника: дерево уровней и фильтр по верхнему уровню берёт все вложенные
        $moto = FaultCodeGroup::where('name', 'МОТОРАМА')->value('id');
        $this->asUser($this->admin)->get('/fault-codes?group=' . $moto)->assertOk()
            ->assertSee('МОТОРАМА · Кросс 3 (Cross 3) · МИНИ ПК')->assertSee('C(3).3.8')->assertDontSee('G.5.1');
    }

    public function test_export_opens_in_excel_and_loads_back(): void
    {
        $this->asUser($this->admin)->post('/fault-codes/import', ['file' => $this->upload(self::LEVELS)]);
        FaultCode::where('code', 'G.5.1')->update(['needs_review' => true]);

        $res = $this->asUser($this->admin)->get('/fault-codes/export')->assertOk()->assertDownload();
        $copy = tempnam(sys_get_temp_dir(), 'fcx') . '.xlsx';
        copy($res->baseResponse->getFile()->getPathname(), $copy);

        $book = IOFactory::load($copy);
        $ws = $book->getSheet(0);
        $this->assertSame('Коды', $ws->getTitle());
        $this->assertSame(['НПП', 'Р1', 'Р2', 'Р3', 'Описание', 'общий вид', 'Разъяснение', 'Вкладки', 'Статус'], $ws->rangeToArray('A1:I1')[0]);
        $this->assertTrue($ws->getStyle('A1')->getFont()->getBold());
        $this->assertSame('A2', $ws->getFreezePane());
        $this->assertNotSame('', $ws->getAutoFilter()->getRange());

        $data = $ws->rangeToArray('A2:I14');
        $rows = collect($data)->keyBy(5);
        $this->assertSame(['01_МОТОРАМА', '01_Кросс 3 (Cross 3)', '03_МИНИ ПК'], array_slice($rows['C(3).3.8'], 1, 3));
        $this->assertSame(['04_ВМР', 'СМ Р3', 'СМ ОПИСАНИЕ'], array_slice($rows['C.1.2'], 1, 3));
        $this->assertSame('Новый, не проверен', $rows['G.5.1'][8]);
        // Порядок как в таблице предприятия
        $this->assertSame(['C(3).1.2', 'C(3).2.2', 'C(3).3.1', 'C(3).3.8', 'C(3).3.10', 'C(1).1.1', 'C(1).1.10'], array_slice(array_column($data, 5), 0, 7));
        $this->assertSame('Уровни', $book->getSheet(1)->getTitle());

        // Выгруженный файл загружается обратно без потерь
        FaultCode::query()->delete();
        FaultCodeGroup::query()->delete();
        $this->asUser($this->admin)->post('/fault-codes/import', ['file' => new UploadedFile($copy, 'коды.xlsx', null, null, true)])
            ->assertSessionHas('ok')->assertSessionHasNoErrors();
        $this->assertSame(13, FaultCode::count());
        $this->assertSame('МОТОРАМА · Кросс 3 (Cross 3) · МИНИ ПК', FaultCode::where('code', 'C(3).3.8')->first()->group->label());
        $this->assertSame('C(3).3', FaultCodeGroup::where('name', 'МИНИ ПК')->value('prefix'));
        $this->assertSame('При частом моргании синего светодиода на компасе он не исправен', FaultCode::where('code', 'C(3).2.2')->value('hint'));
    }

    public function test_hint_and_group_shown_in_record_form(): void
    {
        $this->importTable();

        $this->asUser($this->admin)->get('/records/router_firmware/create')->assertOk()
            ->assertSee('R.1.1', false)->assertSee('Проверить кабель и питание', false)
            ->assertDontSee('C(3).3.10', false);
    }
}
