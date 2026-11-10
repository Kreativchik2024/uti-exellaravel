<?php

namespace Tests\Feature;

use App\Models\Cross3Record;
use App\Models\PgpRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SiteHelpers;
use Tests\TestCase;

/** Поиск по номеру сразу во всех вкладках участка. */
class SearchTest extends TestCase
{
    use RefreshDatabase, SiteHelpers;

    public function test_finds_board_number_across_sheets_with_highlight(): void
    {
        $user = $this->worker();
        PgpRecord::create(['record_date' => now()->toDateString(), 'post_number' => 1, 'worker1' => 'Иванов И.',
            'board_number' => 'RA-41027', 'ip_address' => '192.168.1.5', 'status' => 'Сдан']);
        PgpRecord::create(['record_date' => now()->toDateString(), 'post_number' => 2, 'worker1' => 'Иванов И.',
            'board_number' => 'RA-99999', 'ip_address' => '192.168.1.6', 'status' => 'Сдан']);

        $this->asUser($user)->get('/search?q=41027')
            ->assertOk()->assertSee('ПГП (борта)')->assertSee('<mark>41027</mark>', false)->assertDontSee('RA-99999');

        $this->asUser($user)->get('/search?q=нет-такого')->assertOk()->assertSee('ничего не найдено');
    }

    public function test_query_with_sql_wildcards_is_literal(): void
    {
        PgpRecord::create(['record_date' => now()->toDateString(), 'post_number' => 1, 'worker1' => 'Иванов И.',
            'board_number' => 'RA-1', 'ip_address' => '192.168.1.5', 'status' => 'Сдан']);

        $this->asUser($this->worker())->get('/search?q=%25%25')->assertOk()->assertSee('ничего не найдено');
    }
}
