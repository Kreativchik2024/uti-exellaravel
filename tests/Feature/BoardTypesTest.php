<?php

namespace Tests\Feature;

use App\Models\PgpRecord;
use App\Support\BoardTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SiteHelpers;
use Tests\TestCase;

/**
 * Типы бортов ПГП (УМН / МН / СОН): форма, автоопределение по номеру, фильтр списка, статистика.
 */
class BoardTypesTest extends TestCase
{
    use RefreshDatabase, SiteHelpers;

    public function test_type_is_detected_from_board_number(): void
    {
        $this->assertSame('УМН', BoardTypes::detect('УМН-12'));
        $this->assertSame('МН', BoardTypes::detect('мн 7'));          // регистр не важен
        $this->assertSame('СОН', BoardTypes::detect('RA-СОН-3'));
        $this->assertSame('МН', BoardTypes::detect('MH-5'));          // латиница-двойник
        $this->assertSame('УМН', BoardTypes::detect('борт умн'));     // «УМН» раньше «МН»
        $this->assertNull(BoardTypes::detect('RA-77438'));
        $this->assertNull(BoardTypes::detect(null));
    }

    public function test_new_type_is_picked_up_from_config_only(): void
    {
        // Новый тип = одна строка в config/board_types.php
        config(['board_types.types' => config('board_types.types') + ['ТСТ' => ['label' => 'Тестовый', 'match' => ['ТСТ']]]]);

        $this->assertSame(['УМН', 'МН', 'СОН', 'ТСТ'], BoardTypes::codes());
        $this->assertSame('ТСТ', BoardTypes::detect('ТСТ-1'));
        $this->assertSame('Тестовый', BoardTypes::label('ТСТ'));

        PgpRecord::create(['record_date' => now()->toDateString(), 'post_number' => 1, 'worker1' => 'X',
                           'board_number' => 'ТСТ-1', 'status' => 'Сдан']);
        $this->actingAs($this->worker('master'));    // статистика считается по участку вошедшего
        $data = app(\App\Services\StatsService::class)->boardTypes(now()->startOfDay(), now()->endOfDay(), null);
        $this->assertSame(1, $data['types']['ТСТ']['total']);
        $this->assertSame('Тестовый', $data['types']['ТСТ']['label']);
    }

    public function test_pgp_form_has_board_type_select_and_saves_it(): void
    {
        $user = $this->worker('admin');

        $html = $this->asUser($user)->get('/records/pgp/create')->assertOk()->getContent();
        $this->assertStringContainsString('name="board_type"', $html);
        foreach (['УМН', 'МН', 'СОН'] as $t) $this->assertStringContainsString('<option value="' . $t . '"', $html);

        $this->asUser($user)->post('/records/pgp', array_merge($this->validRecord('pgp', $this->nameOf($user)), ['board_type' => 'СОН']))
            ->assertSessionHasNoErrors();
        $this->assertSame('СОН', PgpRecord::firstOrFail()->board_type);
    }

    public function test_board_type_is_filled_from_number_or_required(): void
    {
        $user = $this->worker('admin');
        $base = $this->validRecord('pgp', $this->nameOf($user));

        // В номере есть «УМН» — тип подставился сам
        $this->asUser($user)->post('/records/pgp', array_merge($base, ['board_number' => 'УМН-101', 'board_type' => '']))
            ->assertSessionHasNoErrors();
        $this->assertSame('УМН', PgpRecord::where('board_number', 'УМН-101')->value('board_type'));

        // Номер без признака типа и тип не выбран — ошибка
        $this->asUser($user)->post('/records/pgp', array_merge($base, ['board_number' => 'RA-1', 'board_type' => '']))
            ->assertSessionHasErrors('board_type');

        // Неизвестный тип — ошибка
        $this->asUser($user)->post('/records/pgp', array_merge($base, ['board_number' => 'RA-2', 'board_type' => 'XXX']))
            ->assertSessionHasErrors('board_type');
    }

    public function test_list_is_filtered_by_board_type(): void
    {
        $user = $this->worker('admin');
        foreach (['УМН-1', 'УМН-2', 'МН-1', 'СОН-1'] as $n) {
            PgpRecord::create(['record_date' => now()->toDateString(), 'post_number' => 1, 'worker1' => 'X',
                               'board_number' => $n, 'status' => 'Сдан']);
        }
        PgpRecord::create(['record_date' => now()->toDateString(), 'post_number' => 1, 'worker1' => 'X',
                           'board_number' => 'RA-9', 'status' => 'Сдан']);

        $this->assertSame(2, PgpRecord::where('board_type', 'УМН')->count(), 'тип определён моделью при сохранении');

        $html = $this->asUser($user)->get('/records/pgp?board_type=УМН')->assertOk()->getContent();
        $this->assertStringContainsString('УМН-1', $html);
        $this->assertStringContainsString('УМН-2', $html);
        $this->assertStringNotContainsString('СОН-1', $html);
        $this->assertStringNotContainsString('RA-9', $html);

        $html = $this->asUser($user)->get('/records/pgp?board_type=_none')->assertOk()->getContent();
        $this->assertStringContainsString('RA-9', $html);
        $this->assertStringContainsString('Без типа', $html);
        $this->assertStringNotContainsString('УМН-1', $html);
    }

    public function test_stats_show_total_and_per_type(): void
    {
        $master = $this->worker('master');
        foreach ([['УМН-1', 'Сдан'], ['УМН-2', 'Не сдан'], ['МН-1', 'Сдан'], ['СОН-1', 'Сдан']] as [$n, $st]) {
            PgpRecord::create(['record_date' => now()->toDateString(), 'post_number' => 1, 'worker1' => 'X',
                               'board_number' => $n, 'status' => $st]);
        }

        $this->actingAs($master);
        $data = app(\App\Services\StatsService::class)->boardTypes(now()->startOfDay(), now()->endOfDay(), null);
        $this->assertSame(4, $data['total']);
        $this->assertSame(3, $data['delivered']);
        $this->assertSame(2, $data['types']['УМН']['total']);
        $this->assertSame(1, $data['types']['УМН']['delivered']);
        $this->assertSame(1, $data['types']['МН']['total']);
        $this->assertSame(1, $data['types']['СОН']['total']);

        $this->asUser($master)->get('/stats?from=' . now()->toDateString() . '&to=' . now()->toDateString())
            ->assertOk()
            ->assertSee('Борта по типам')
            ->assertSee('Всего бортов');
    }
}
