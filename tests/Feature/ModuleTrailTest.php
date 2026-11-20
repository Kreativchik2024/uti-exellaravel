<?php

namespace Tests\Feature;

use App\Models\CommModuleRecord;
use App\Models\ResearchRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SiteHelpers;
use Tests\TestCase;

/** Модуль по S/N в обе стороны: исследование ↔ запись вкладки, где модуль шили. */
class ModuleTrailTest extends TestCase
{
    use RefreshDatabase, SiteHelpers;

    public function test_research_shows_who_flashed_module_and_back(): void
    {
        $flasher = $this->worker('executor', 'Прошивальщик П.П.');
        $this->asUser($flasher)->post('/records/comm_module', array_merge($this->validRecord('comm_module', $this->nameOf($flasher)),
            ['serial_number' => 'MN-778899']))->assertSessionHasNoErrors();
        $comm = CommModuleRecord::latest('id')->firstOrFail();
        $other = new CommModuleRecord();
        $other->forceFill(['record_date' => now()->toDateString(), 'serial_number' => 'MN-000001', 'worker1' => 'Чужой Ч.Ч.'])->save();

        $research = new ResearchRecord();
        $research->forceFill(['record_date' => now()->toDateString(), 'worker1' => 'Ремонт Р.Р.', 'category' => 'Модуль связи',
            'module_serial' => ' mn-778899 ', 'reported_defect' => 'Нет линка', 'repair_status' => 'new'])->save();

        $master = $this->worker('master');
        // Исследование → где делали: вкладка, кто шил
        $card = $this->asUser($master)->get("/records/research/{$research->id}")->assertOk()->getContent();
        $this->assertStringContainsString('Где делали модуль', $card);
        $this->assertStringContainsString("/records/comm_module/{$comm->id}\"", $card);
        $this->assertStringContainsString('Прошивальщик П.П.', $card);
        $this->assertStringNotContainsString('Чужой Ч.Ч.', $card);

        // Обратно: запись модуля связи → его исследования
        $back = $this->asUser($master)->get("/records/comm_module/{$comm->id}")->assertOk()->getContent();
        $this->assertStringContainsString('Исследования модуля', $back);
        $this->assertStringContainsString("/records/research/{$research->id}\"", $back);
        $this->assertStringNotContainsString('Исследования модуля', $this->asUser($master)->get("/records/comm_module/{$other->id}")->getContent());

        // Повторное исследование того же модуля — в истории детали
        $again = new ResearchRecord();
        $again->forceFill(['record_date' => now()->toDateString(), 'worker1' => 'Ремонт Р.Р.', 'module_serial' => 'mn-778899', 'repair_status' => 'new'])->save();
        $this->assertStringContainsString("/records/research/{$research->id}\"", $this->asUser($master)->get("/records/research/{$again->id}")->getContent());
    }
}
