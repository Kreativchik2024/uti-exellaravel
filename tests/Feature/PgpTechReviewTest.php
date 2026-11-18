<?php

namespace Tests\Feature;

use App\Models\PgpRecord;
use App\Services\PgpComplaints;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SiteHelpers;
use Tests\TestCase;

/** Пометка технолога ПГП (только ОТК) и счётчик необработанных рекламаций. */
class PgpTechReviewTest extends TestCase
{
    use RefreshDatabase, SiteHelpers;

    /** Запись ПГП с браком; forceFill — tech_processed не в $fillable (как и в контроллере пометки). */
    private function defect(array $extra = []): PgpRecord
    {
        $worker = $this->worker('executor', 'Иванов И.И.');
        $data = $this->validRecord('pgp', 'Иванов И.И.');
        $data['status'] = 'Не сдан';
        $record = new PgpRecord();
        $record->forceFill(array_merge($data, ['created_by' => $worker->id], $extra))->save();

        return $record;
    }

    public function test_only_otk_can_write_tech_note(): void
    {
        $rec = $this->defect();
        $url = "/records/pgp/{$rec->id}/tech";

        foreach (['executor', 'master', 'admin'] as $role) {
            $this->asUser($this->worker($role))->patch($url, ['tech_note' => 'взлом'])->assertForbidden();
        }
        $this->assertNull($rec->fresh()->tech_note);

        $this->asUser($this->worker('otk'))->patch($url, ['tech_note' => 'Перепаять разъём'])->assertRedirect();
        $this->assertSame('Перепаять разъём', $rec->fresh()->tech_note);
        $this->assertFalse($rec->fresh()->tech_processed);
    }

    public function test_otk_toggles_processed_flag(): void
    {
        $rec = $this->defect();
        $otk = $this->worker('otk');

        $this->asUser($otk)->patch("/records/pgp/{$rec->id}/tech", ['tech_processed' => 1])->assertRedirect();
        $this->assertTrue($rec->fresh()->tech_processed);

        $this->asUser($otk)->patch("/records/pgp/{$rec->id}/tech", ['tech_processed' => 0])->assertRedirect();
        $this->assertFalse($rec->fresh()->tech_processed);
    }

    public function test_otk_sees_note_form_with_resolved_select_and_cancel(): void
    {
        $rec = $this->defect();

        $otkHtml = $this->asUser($this->worker('otk'))->get("/records/pgp/{$rec->id}")->assertOk()->getContent();
        $this->assertStringContainsString('name="tech_note"', $otkHtml);
        $this->assertStringContainsString('name="tech_processed"', $otkHtml);
        $this->assertStringContainsString('type="reset"', $otkHtml);

        $masterHtml = $this->asUser($this->worker('master'))->get("/records/pgp/{$rec->id}")->assertOk()->getContent();
        $this->assertStringNotContainsString('name="tech_note"', $masterHtml);
        $this->assertStringNotContainsString('type="reset"', $masterHtml);
    }

    public function test_open_complaints_count_only_unprocessed_defects(): void
    {
        $master = $this->worker('master');
        $open = $this->defect();
        $this->defect(['tech_processed' => true]);
        $this->defect(['status' => 'Сдан']);   // не брак

        $this->actingAs($master);   // счётчик считается для текущего участка пользователя
        $this->assertSame(1, app(PgpComplaints::class)->openCount());

        $this->asUser($this->worker('otk'))->patch("/records/pgp/{$open->id}/tech", ['tech_processed' => 1]);
        $this->assertSame(0, app(PgpComplaints::class)->openCount());
    }

    public function test_tech_open_filter_lists_only_open_complaints(): void
    {
        $open = $this->defect();
        $done = $this->defect(['tech_processed' => true]);

        $html = $this->asUser($this->worker('master'))->get('/records/pgp?tech=open')->assertOk()->getContent();
        $this->assertStringContainsString("/records/pgp/{$open->id}\"", $html);
        $this->assertStringNotContainsString("/records/pgp/{$done->id}\"", $html);
    }
}
