<?php

namespace Tests\Feature;

use App\Models\FaultCode;
use App\Models\FaultCodeArchive;
use App\Models\User;
use App\Services\FaultCodeRating;
use App\Services\FaultCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SiteHelpers;
use Tests\TestCase;

/** Рейтинг кодов неисправностей: привязка к справочнику и объединение похожих написаний. */
class FaultCodeRatingTest extends TestCase
{
    use RefreshDatabase, SiteHelpers;

    private function row(string $code, int $count = 1, string $sheet = 'pgp'): array
    {
        return ['code' => $code, 'sheet' => $sheet, 'sheet_title' => 'ПГП', 'count' => $count, 'first_date' => '2026-11-01', 'last_date' => '2026-11-10'];
    }

    public function test_codes_and_similar_descriptions_are_linked_to_catalog(): void
    {
        FaultCode::create(['code' => 'E01', 'description' => 'Нет связи с модулем', 'is_active' => true]);
        FaultCode::create(['code' => 'E02', 'description' => 'Не определяется камера', 'is_active' => true]);

        $rated = app(FaultCodeRating::class)->rate([
            $this->row('e01', 3),                       // регистр не важен — тот же код
            $this->row('нет связи с модулем', 2),       // текст похож на описание E01
            $this->row('Не определяется камера', 1),
        ]);

        $this->assertCount(2, $rated);
        $this->assertSame('E01', $rated[0]['code']);
        $this->assertSame(5, $rated[0]['count']);
        $this->assertTrue($rated[0]['known']);
        $this->assertSame('E02', $rated[1]['code']);
    }

    public function test_unknown_texts_with_same_meaning_are_grouped_into_one_row(): void
    {
        $rated = app(FaultCodeRating::class)->rate([
            $this->row('Сбой питания платы', 2),
            $this->row('сбой питания платы ', 1),
            $this->row('Хаотичный шум на линии', 1),
        ]);

        $this->assertCount(2, $rated);
        $this->assertSame(3, $rated[0]['count']);
        $this->assertFalse($rated[0]['known']);
        $this->assertNull($rated[0]['code']);
        $this->assertSame('Сбой питания платы', $rated[0]['text']);
        $this->assertCount(2, $rated[0]['variants']);
    }

    public function test_archived_code_keeps_its_description(): void
    {
        FaultCodeArchive::create(['code' => 'OLD7', 'description' => 'Старая неисправность', 'hint' => null, 'group_label' => 'Кросс 3']);

        $rated = app(FaultCodeRating::class)->rate([$this->row('OLD7', 4)]);

        $this->assertSame('OLD7', $rated[0]['code']);
        $this->assertSame('Старая неисправность', $rated[0]['description']);
        $this->assertFalse($rated[0]['known']);
    }

    public function test_ambiguous_description_is_not_guessed(): void
    {
        FaultCode::create(['code' => 'A1', 'description' => 'Отсутствует ПО', 'is_active' => true]);
        FaultCode::create(['code' => 'B1', 'description' => 'Отсутствует ПО', 'is_active' => true]);

        $rated = app(FaultCodeRating::class)->rate([$this->row('Отсутствует ПО', 1)]);

        $this->assertNull($rated[0]['code'] ?? null, 'при двух одинаковых описаниях код не подбирается');
    }

    public function test_link_command_rewrites_text_to_catalog_code_only_when_applied(): void
    {
        FaultCode::create(['code' => 'E01', 'description' => 'Нет связи с модулем', 'is_active' => true]);
        $worker = $this->worker('master');
        $model = $this->modelOf('pgp');
        $data = $this->validRecord('pgp', 'Иванов И.И.');
        $data['fault_code'] = 'нет связи с модулем';
        $rec = $model::create($data + ['created_by' => $worker->id]);

        $this->artisan('fault-codes:link-records', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame('нет связи с модулем', $rec->fresh()->fault_code);

        $this->artisan('fault-codes:link-records', ['--force' => true])->assertSuccessful();
        $this->assertSame('E01', $rec->fresh()->fault_code);
    }

    public function test_zone6_returns_rated_rows(): void
    {
        FaultCode::create(['code' => 'E01', 'description' => 'Нет связи с модулем', 'is_active' => true]);
        $master = $this->worker('master');
        $model = $this->modelOf('pgp');
        $data = $this->validRecord('pgp', 'Иванов И.И.');
        $data['fault_code'] = 'E01';
        $model::create($data + ['created_by' => $master->id]);

        $rows = $this->asUser($master)->getJson('/stats/zone6?' . http_build_query(['from' => now()->subDay()->toDateString(), 'to' => now()->toDateString()]))
            ->assertOk()->json();

        $this->assertSame('E01', $rows[0]['code']);
        $this->assertSame('Нет связи с модулем', $rows[0]['description']);
        $this->assertTrue($rows[0]['known']);
    }
}
