<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PgpRecord;
use App\Models\User;
use App\Support\WorkerNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SiteHelpers;
use Tests\TestCase;

/** Разные написания одного ФИО — один исполнитель в отчётах. */
class WorkerNamesTest extends TestCase
{
    use RefreshDatabase, SiteHelpers;

    public function test_spelling_variants_give_same_key(): void
    {
        $same = ['Иванов И.И.', 'Иванов И. И.', 'иванов  ии', 'ИВАНОВ И.И', ' Иванов И.И. ', 'И.И. Иванов', 'Иванов Иван Иванович', "Иванов\u{00A0}И.И."];
        foreach ($same as $v) {
            $this->assertSame('иванов ии', WorkerNames::key($v), "«{$v}»");
        }
        $this->assertSame('федоров мм', WorkerNames::key('Фёдоров М.М.'), 'ё = е');
        $this->assertNotSame(WorkerNames::key('Иванов И.И.'), WorkerNames::key('Иванов П.И.'), 'разные люди — разные ключи');

        foreach (['-', '—', 'нет', '123', '', '  '] as $junk) {
            $this->assertSame('', WorkerNames::key($junk), "«{$junk}» — не исполнитель");
        }
        $this->assertSame(['Иванов И.И.', 'Петров П.П.'], WorkerNames::split('Иванов И.И., Петров П.П.'));
    }

    public function test_incomplete_spelling_joins_the_only_matching_employee(): void
    {
        $names = new WorkerNames(['иванов ии' => 'Иванов И.И.', 'петров пп' => 'Петров П.П.', 'петров ап' => 'Петров А.П.']);

        $this->assertSame('иванов ии', $names->id('Иванов'), 'один Иванов в справочнике');
        $this->assertSame('иванов ии', $names->id('Иванов И.'));
        $this->assertSame('петров пп', $names->id('Петров П.'), 'из двух Петровых на «П» только Петров П.П.');
        $this->assertSame('петров', $names->id('Петров'), 'просто «Петров» — двух Петровых не угадываем');
        $this->assertSame('Иванов И.И.', $names->label('иванов ии'));
    }

    public function test_defect_report_groups_spellings_and_marks_unknown(): void
    {
        $owner = User::factory()->create();
        Employee::create(['last_name' => 'Иванов', 'first_name' => 'Иван', 'middle_name' => 'Иванович', 'short_name' => 'Иванов И.И.', 'is_active' => true, 'user_id' => $owner->id]);

        foreach (['Иванов И.И.', 'Иванов  И. И.', 'иванов ии', 'Иванов И.И'] as $i => $spelling) {
            PgpRecord::create(['record_date' => now()->toDateString(), 'post_number' => 1, 'worker1' => $spelling,
                'board_number' => "RA-$i", 'ip_address' => '192.168.1.5', 'status' => 'Не сдан', 'defect_reason' => 'нет связи',
                'fuselage_number' => 'F', 'frame_serial' => 'M']);
        }
        PgpRecord::create(['record_date' => now()->toDateString(), 'post_number' => 1, 'worker1' => 'Сидорофф С.С.',
            'board_number' => 'RA-x', 'ip_address' => '192.168.1.5', 'status' => 'Не сдан', 'defect_reason' => 'x', 'fuselage_number' => 'F', 'frame_serial' => 'M']);

        $master = User::factory()->master()->create();
        $master->sections()->attach(\App\Models\Section::first()->id);
        $res = $this->asUser($master)->get('/reports/defects')->assertOk();
        $workers = $res->viewData('data')['workers'];

        $this->assertSame(['Иванов И.И.', 'Сидорофф С.С.'], array_keys($workers), 'четыре написания Иванова — одна строка');
        $this->assertSame(4, $workers['Иванов И.И.']['defect']);
        $this->assertTrue($workers['Иванов И.И.']['known']);
        $this->assertFalse($workers['Сидорофф С.С.']['known']);
        $res->assertSee('нет в справочнике')->assertSee('также записан');
    }
}
