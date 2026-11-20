<?php

namespace Tests\Feature;

use App\Models\Cross1Record;
use App\Models\FaultCode;
use App\Models\FaultCodeGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SiteHelpers;
use Tests\TestCase;

/** Миграция чистки дерева кодов: перенос ВМР, объединение дублей, ВМГ → ХВОСТ, коды в записях переписаны. */
class FaultCodeTreeFixTest extends TestCase
{
    use RefreshDatabase, SiteHelpers;

    public function test_tree_is_fixed_and_records_follow_codes(): void
    {
        $frame = FaultCodeGroup::create(['name' => 'МОТОРАМА', 'prefix' => 'М', 'sheets' => ['pgp']]);
        $vmr = FaultCodeGroup::create(['name' => 'ВМР', 'prefix' => 'C.1', 'sheets' => ['pgp']]);
        $comm = FaultCodeGroup::create(['name' => 'КОММУТАЦИЯ', 'prefix' => 'H.1', 'sheets' => ['pgp']]);
        $bracket = FaultCodeGroup::create(['name' => 'КРОНШТЕЙН КАМЕРЫ', 'prefix' => 'КК', 'sheets' => ['pgp']]);
        $router = FaultCodeGroup::create(['name' => 'Роутер', 'prefix' => 'G.5', 'parent_id' => $bracket->id]);
        $speed = FaultCodeGroup::create(['name' => 'Датчик скорости', 'prefix' => 'G.8', 'parent_id' => $bracket->id]);
        $vmg = FaultCodeGroup::create(['name' => 'ВМГ', 'prefix' => 'R.1', 'sheets' => ['pgp']]);
        // ХВОСТ с подгруппами уже заведён миграцией 2026_11_18_180000_add_tail_block_for_repair
        $this->assertTrue(FaultCodeGroup::whereNull('parent_id')->where('name', 'ХВОСТ')->exists());

        FaultCode::create(['code' => 'C.1.1', 'description' => '12_ВМР', 'group_id' => $vmr->id]);
        FaultCode::create(['code' => 'C.1.8', 'description' => 'Поврежден жгут модуля связи', 'group_id' => $vmr->id]);
        FaultCode::create(['code' => 'G.5.6', 'description' => 'Неисправен датчик срорости', 'group_id' => $router->id]);
        FaultCode::create(['code' => 'G.5.8', 'description' => 'Перепутаны провода', 'group_id' => $router->id]);
        FaultCode::create(['code' => 'G.8.1', 'description' => 'Неисправен датчик скорости', 'group_id' => $speed->id]);
        FaultCode::create(['code' => 'R.1.7', 'description' => 'Неисправен электромотор', 'group_id' => $vmg->id]);

        $rec = new Cross1Record();
        $rec->forceFill(['record_date' => now()->toDateString(), 'qty' => 1, 'worker1' => 'Иванов И.И.', 'fault_code' => 'C.1.1; G.5.6; R.1.7'])->save();

        (require database_path('migrations/2026_11_20_130000_fix_fault_code_tree.php'))->up();

        $this->assertSame($frame->id, $vmr->fresh()->parent_id);
        $this->assertSame('ВМР', $vmr->fresh()->prefix);
        $this->assertSame('Неисправна ВМР', FaultCode::where('code', 'ВМР.1')->value('description'));
        $this->assertSame($comm->id, FaultCode::where('code', 'H.1.8')->value('group_id'));
        $this->assertFalse(FaultCode::where('code', 'G.5.6')->where('group_id', $router->id)->where('description', 'like', '%срорости%')->exists());
        $this->assertSame('Перепутаны провода', FaultCode::where('code', 'G.5.6')->value('description'), 'нумерация роутера без дыр');
        $this->assertNull(FaultCodeGroup::find($vmg->id), 'ВМГ влита в ХВОСТ');
        $this->assertSame('Мотор', FaultCode::where('code', 'ХВ.2.2')->first()?->group?->name);
        $this->assertContains('data15', (array) $comm->fresh()->sheets);

        $this->assertSame('ВМР.1; G.8.1; ХВ.2.2', $rec->fresh()->fault_code);
    }
}
