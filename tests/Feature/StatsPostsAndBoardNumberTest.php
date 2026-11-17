<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PgpRecord;
use App\Support\BoardNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SiteHelpers;
use Tests\TestCase;

/**
 * Детализация по постам (топ по сданным, кликабельные цифры), номер борта в общем виде,
 * просмотр сотрудников мастером.
 */
class StatsPostsAndBoardNumberTest extends TestCase
{
    use RefreshDatabase, SiteHelpers;

    private function pgp(int $post, string $status, string $board): void
    {
        PgpRecord::create(['record_date' => now()->toDateString(), 'post_number' => $post, 'worker1' => 'X',
                           'board_number' => $board, 'board_type' => 'УМН', 'status' => $status]);
    }

    public function test_posts_top_is_ordered_by_delivered_boards(): void
    {
        // Пост 1: больше прогон (3), но сдан 1; пост 2: прогон 2, сдано 2
        $this->pgp(1, 'Сдан', 'RA-1');
        $this->pgp(1, 'Не сдан', 'RA-2');
        $this->pgp(1, 'Не сдан', 'RA-3');
        $this->pgp(2, 'Сдан', 'RA-4');
        $this->pgp(2, 'Сдан', 'RA-5');

        $rows = $this->asUser($this->worker('master'))->getJson('/stats/zone3?sheet=pgp')->assertOk()->json();

        $this->assertSame([2, 1], array_column($rows, 'post_number'));
        $this->assertSame(['post_number' => 2, 'cnt' => 2, 'not_done' => 0, 'total' => 2], $rows[0]);
        $this->assertSame(['post_number' => 1, 'cnt' => 1, 'not_done' => 2, 'total' => 3], $rows[1]);
    }

    public function test_post_numbers_open_list_of_that_post(): void
    {
        $this->pgp(1, 'Сдан', 'RA-111');
        $this->pgp(1, 'Не сдан', 'RA-222');
        $this->pgp(2, 'Не сдан', 'RA-333');

        $html = $this->asUser($this->worker('master'))
            ->get('/stats/records?sheet=pgp&board=_all&kind=defect&post=1')->assertOk()->getContent();

        $this->assertStringContainsString('RA-222', $html);
        $this->assertStringNotContainsString('RA-111', $html);   // сдан
        $this->assertStringNotContainsString('RA-333', $html);   // другой пост
        $this->assertStringContainsString('пост 1', $html);
    }

    public function test_board_number_is_normalized(): void
    {
        foreach (['S50Z100844MH-02T', 's50z100844mh-02t', 'S50Z100844МН-02Т', ' S50Z 100844 MH - 02T ',
                  'S50Z100844MH–02T', 'S50Z100844MH_02T', 'S50Z100844MH02T',
                  'S50Z-100844-MH-02T', 'S-50-Z-100844-MH-02-T', 'S50Z100844-MH-02T', 'S50Z--100844MH--02T'] as $in) {
            $this->assertSame('S50Z100844MH-02T', BoardNumber::normalize($in), $in);
        }
        foreach (['S50z-100-903-мн-02t-008-manet', 'S50Z100903MH02T008MANET', 's50z 100903 mh-02t-008 manet',
                  'S50Z100903MH-02T-008-MANET'] as $in) {
            $this->assertSame('S50Z100903MH-02T-008-MANET', BoardNumber::normalize($in), $in);
        }
        $this->assertNull(BoardNumber::normalize(null));

        $this->pgp(1, 'Сдан', 's50z100844мн02т');
        $rec = PgpRecord::firstOrFail();
        $this->assertSame('S50Z100844MH-02T', $rec->board_number, 'нормализуется при сохранении');
        $this->assertSame('МН', \App\Support\BoardTypes::detect($rec->board_number), 'тип по номеру определяется');
    }

    public function test_command_normalizes_existing_board_numbers(): void
    {
        $this->pgp(1, 'Сдан', 'X');
        $this->pgp(1, 'Сдан', 'Y');
        // «старые» номера, записанные до нормализации, — в обход модели
        \DB::table('pgp_records')->where('board_number', 'X')->update(['board_number' => 's50z-100844-мн-02т']);
        \DB::table('pgp_records')->where('board_number', 'Y')->update(['board_number' => 'УМН-101']);

        $this->artisan('boards:normalize --dry-run')->assertSuccessful();
        $this->assertSame(1, PgpRecord::where('board_number', 's50z-100844-мн-02т')->count(), 'dry-run ничего не меняет');

        $this->artisan('boards:normalize --force')->expectsOutputToContain('изменено: 1')->assertSuccessful();
        $this->assertSame(1, PgpRecord::where('board_number', 'S50Z100844MH-02T')->count());
        $this->assertSame(1, PgpRecord::where('board_number', 'УМН-101')->count(), 'не эталонный — не тронут');
        $this->assertTrue(\App\Models\AuditLog::where('changes', 'like', '%S50Z100844MH-02T%')->exists(), 'есть в журнале');
    }

    public function test_master_can_view_employees_but_not_edit_executor_cannot_view(): void
    {
        Employee::create(['last_name' => 'Яковлев', 'first_name' => 'Я', 'short_name' => 'Яковлев Я.', 'is_active' => true]);
        Employee::create(['last_name' => 'Абрамов', 'first_name' => 'А', 'short_name' => 'Абрамов А.', 'is_active' => true]);

        $html = $this->asUser($this->worker('master'))->get('/employees')->assertOk()->getContent();
        $this->assertLessThan(strpos($html, 'Яковлев'), strpos($html, 'Абрамов'), 'по алфавиту');
        $this->assertStringNotContainsString('Добавить сотрудника', $html);
        $this->assertStringNotContainsString('Удалить', $html);

        $this->asUser($this->worker('master'))->get('/employees/create')->assertForbidden();
        $this->asUser($this->worker('executor'))->get('/employees')->assertForbidden();
    }
}
