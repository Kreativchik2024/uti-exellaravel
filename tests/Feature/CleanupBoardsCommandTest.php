<?php

namespace Tests\Feature;

use App\Models\PgpRecord;
use App\Models\ResearchRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SiteHelpers;
use Tests\TestCase;

class CleanupBoardsCommandTest extends TestCase
{
    use RefreshDatabase, SiteHelpers;

    private $author = null;

    private function pgp(string $fuselage, string $status, int $daysAgo): PgpRecord
    {
        $p = new PgpRecord();
        $p->forceFill(array_merge($this->validRecord('pgp', 'Автор А.А.'), [
            'status' => $status, 'technologist' => $status === 'Сдан' ? $this->technologistName() : null,
            'fuselage_number' => $fuselage, 'record_date' => now()->subDays($daysAgo)->toDateString(),
        ]))->saveQuietly();

        return $p;
    }

    private function research(array $attrs): ResearchRecord
    {
        $r = new ResearchRecord();
        $this->author ??= $this->worker('repair');
        $r->forceFill(array_merge(['worker1' => 'Ремонт Р.Р.', 'repair_status' => 'new', 'is_repaired' => false, 'created_by' => $this->author->id,
            'record_date' => now()->toDateString()], $attrs))->saveQuietly();

        return $r;
    }

    public function test_report_does_not_change_and_apply_cleans_up(): void
    {
        // 1. Брак с исследованием, борт потом сдан
        $bad = $this->pgp('F-1', 'Не сдан', 30);
        $r1 = $this->research(['pgp_record_id' => $bad->id, 'fuselage_no' => 'F-1', 'record_date' => $bad->record_date]);
        $ok = $this->pgp(' f-1 ', 'Сдан', 20);
        // Старый «Не сдан» без исследования, борт потом проверили снова — исследование не заводим
        $old = $this->pgp('F-2', 'Не сдан', 40);
        $old2 = $this->pgp('F-2', 'Сдан', 35);
        // 2. По результату
        $r2 = $this->research(['fuselage_no' => 'F-3', 'result' => 'Устранено']);
        $r3 = $this->research(['fuselage_no' => 'F-4', 'result' => 'в работе']);
        $r6 = $this->research(['result' => 'Моторама передана в сборку и дальнейшее испытание']);
        $r7 = $this->research(['result' => 'Рама в разбор']);
        $r10 = $this->research(['result' => 'Моторама отдана на сборку, борт собран ожидает испытания']);
        $r11 = $this->research(['result' => 'Мотораму на иследование']);
        // 2б. Загружено из таблицы: без автора; пустой результат → в разбор
        $r8 = $this->research(['created_by' => null, 'result' => '']);
        $r9 = $this->research(['created_by' => null, 'result' => 'Борт передан на испытания.']);
        // 3. Давний, без признаков; и свежий — не трогаем
        $r4 = $this->research(['fuselage_no' => 'F-5', 'record_date' => now()->subMonths(4)->toDateString()]);
        $r5 = $this->research(['fuselage_no' => 'F-6']);

        $this->artisan('boards:cleanup')->assertSuccessful();
        $this->assertSame('new', $r1->fresh()->repair_status, 'без --apply ничего не меняется');
        $this->assertSame(11, ResearchRecord::count(), 'и исследования не заводятся');

        $this->artisan('boards:cleanup', ['--apply' => true, '--force' => true])->assertSuccessful();

        $this->assertSame('conveyor', $r1->fresh()->repair_status);
        $this->assertTrue($r1->fresh()->is_repaired);
        $this->assertSame($r1->id, $ok->fresh()->after_research_id);

        $this->assertSame(0, ResearchRecord::where('pgp_record_id', $old->id)->count(), 'новых исследований команда не заводит');
        $this->assertNull($old2->fresh()->after_research_id);
        $this->assertSame(11, ResearchRecord::count());

        $this->assertSame('conveyor', $r2->fresh()->repair_status);
        $this->assertSame('in_work', $r3->fresh()->repair_status);
        $this->assertSame('conveyor', $r6->fresh()->repair_status);
        $this->assertSame('analysis', $r7->fresh()->repair_status);
        $this->assertSame('conveyor', $r10->fresh()->repair_status);
        $this->assertSame('analysis', $r11->fresh()->repair_status);
        $this->assertSame('analysis', $r8->fresh()->repair_status);
        $this->assertStringContainsString('загружено из таблицы', $r8->fresh()->analysis_actions);
        $this->assertSame('conveyor', $r9->fresh()->repair_status);
        $this->assertSame('analysis', $r4->fresh()->repair_status);
        $this->assertStringContainsString('чистке базы', $r4->fresh()->analysis_actions);
        $this->assertSame('new', $r5->fresh()->repair_status);

        $this->assertSame(0, app(\App\Services\PgpDefectResearch::class)->returnedFromRepairCount());
        $this->assertDatabaseHas('audit_logs', ['area' => 'research', 'action' => 'cleanup']);

        // Повторный запуск — делать уже нечего
        $this->artisan('boards:cleanup')->expectsOutputToContain("нет")->assertSuccessful();
    }

    /** Реальные тексты «Результата» с сервера → статус. */
    public function test_result_keywords_on_real_texts(): void
    {
        $cases = [
            'Борт передан на испытания' => 'conveyor', 'Моторама передана в сборку' => 'conveyor', 'Дефект не подтвердился.' => 'conveyor',
            'Перепрошивка ПК, все в норме,отдал на регулировку' => 'conveyor', 'Компас заменён. Проблема устанена.' => 'conveyor',
            'Проверена, все ок' => 'conveyor', 'Моторама собрана и ожидает технолога' => 'conveyor',
            'Моторама отдана на сборку, борт собран ожидает испытания' => 'conveyor',
            'Ожидает проверку' => 'in_work', 'Ожидает замены дата17 потом на проверку' => 'in_work', 'Кронштейн с камерами на ремонт.' => 'in_work',
            'Рама в разбор' => 'analysis', 'Мотораму на иследование' => 'analysis', 'Отдан в настройку. Вернулся со сборки, не работает' => 'analysis',
            'возврат' => null, 'Технологическая камера' => null, '' => null,
        ];
        foreach ($cases as $text => $want) {
            $this->assertSame($want, \App\Console\Commands\CleanupBoards::statusByResult($text), $text);
        }
    }

    public function test_pgp_complaints_are_marked_processed(): void
    {
        $again   = $this->pgp('C-1', 'Не сдан', 20);
        $this->pgp('C-1', 'Не сдан', 10);                                  // борт снова проверяли (и снова брак)
        $repaired = $this->pgp('C-2', 'Не сдан', 20);
        $this->research(['pgp_record_id' => $repaired->id, 'repair_status' => 'conveyor', 'is_repaired' => true]);
        $old   = $this->pgp('C-3', 'Не сдан', 40);
        $fresh = $this->pgp('C-4', 'Не сдан', 2);

        $complaints = fn () => $this->artisan('boards:cleanup', ['--apply' => true, '--force' => true, '--complaints-before' => now()->subDays(30)->toDateString()]);
        $complaints()->assertSuccessful();

        $this->assertTrue($again->fresh()->tech_processed);
        $this->assertStringContainsString('чистке базы', $again->fresh()->tech_note);
        $this->assertTrue($repaired->fresh()->tech_processed);
        $this->assertTrue($old->fresh()->tech_processed);
        $this->assertFalse($fresh->fresh()->tech_processed, 'свежая рекламация без признаков остаётся');
        $this->assertDatabaseHas('audit_logs', ['area' => 'pgp', 'action' => 'cleanup', 'subject_id' => $old->id]);
    }
}
