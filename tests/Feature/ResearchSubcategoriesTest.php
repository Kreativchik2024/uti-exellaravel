<?php

namespace Tests\Feature;

use App\Models\ResearchRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SiteHelpers;
use Tests\TestCase;

/** Исследования: подкатегории — борта и любые другие модули; у модуля — серийный номер. */
class ResearchSubcategoriesTest extends TestCase
{
    use RefreshDatabase, SiteHelpers;

    private function research(array $attrs): ResearchRecord
    {
        $r = new ResearchRecord();
        $r->forceFill(array_merge(['record_date' => now()->toDateString(), 'worker1' => 'Иванов И.И.', 'repair_status' => 'new'], $attrs))->save();

        return $r;
    }

    public function test_tabs_split_boards_and_modules(): void
    {
        $board  = $this->research(['category' => config('sheets.map.pgp.title'), 'fuselage_no' => 'F-1']);
        $module = $this->research(['category' => 'Кросс 3', 'module_serial' => 'CR3-777']);
        $loose  = $this->research(['category' => null]);
        $user = $this->worker('master');

        $html = $this->asUser($user)->get('/records/research')->assertOk()->getContent();
        $this->assertStringContainsString('Что исследуем:', $html);
        $this->assertStringContainsString('Борта', $html);
        $this->assertStringContainsString('Кросс 3', $html);
        $this->assertStringContainsString('Без категории', $html);

        $boards = $this->asUser($user)->get('/records/research?cat=_board')->getContent();
        $this->assertStringContainsString("/records/research/{$board->id}\"", $boards);
        $this->assertStringNotContainsString("/records/research/{$module->id}\"", $boards);

        $modules = $this->asUser($user)->get('/records/research?cat=' . urlencode('Кросс 3'))->getContent();
        $this->assertStringContainsString("/records/research/{$module->id}\"", $modules);
        $this->assertStringNotContainsString("/records/research/{$board->id}\"", $modules);
        $this->assertStringNotContainsString("/records/research/{$loose->id}\"", $modules);
    }

    public function test_module_research_saves_serial_and_form_marks_fields(): void
    {
        $user = $this->worker('master');
        $form = $this->asUser($user)->get('/records/research/create')->assertOk()->getContent();
        $this->assertStringContainsString('data-for="module"', $form);
        $this->assertStringContainsString('data-for="board"', $form);

        $data = array_merge($this->validRecord('research', $this->nameOf($user)), ['category' => 'Мини ПК', 'module_serial' => 'PC-42', 'repair_status' => 'new']);
        $this->asUser($user)->post('/records/research', $data)->assertSessionHasNoErrors();
        $this->assertSame('PC-42', ResearchRecord::latest('id')->value('module_serial'));
    }
}
