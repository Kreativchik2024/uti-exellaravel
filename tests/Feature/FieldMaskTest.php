<?php

namespace Tests\Feature;

use App\Models\FieldMask;
use App\Models\PgpRecord;
use App\Support\InputMask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SiteHelpers;
use Tests\TestCase;

/** Админ → Маски полей: библиотека масок, маски у полей ПГП, проверка при вводе. */
class FieldMaskTest extends TestCase
{
    use RefreshDatabase, SiteHelpers;

    public function test_mask_syntax(): void
    {
        $this->assertTrue(InputMask::matches('УМН-9999', 'УМН-0421'));
        $this->assertTrue(InputMask::matches('УМН-9999', 'умн-0421'));   // постоянные — без учёта регистра
        $this->assertFalse(InputMask::matches('УМН-9999', 'УМН-04a1'));
        $this->assertFalse(InputMask::matches('УМН-9999', 'УМН-04211'));
        $this->assertTrue(InputMask::matches('AA-*9', 'Мр-x5'));
        $this->assertTrue(InputMask::matches('\9-99', '9-12'));
        $this->assertFalse(InputMask::matches('\9-99', '8-12'));
        $this->assertSame('УМН-0000', InputMask::placeholder('УМН-9999'));
        $this->assertNotNull(InputMask::error('УМН-'));

        // Маску можно набрать «как видишь»: 0 — цифра, Я / русская А — буква, ? и _ — буква или цифра
        $this->assertTrue(InputMask::matches('УМН-0000', 'УМН-0421'));
        $this->assertTrue(InputMask::matches('ЯЯ-##', 'MR-12'));
        $this->assertTrue(InputMask::matches('АА-99', 'MR-12'));   // кириллическая «А»
        $this->assertTrue(InputMask::matches('??-__', 'A1-b2'));
        $this->assertTrue(InputMask::matches('\0-00', '0-12'));
        $this->assertFalse(InputMask::matches('\0-00', '5-12'));
        $this->assertNull(InputMask::error('УМН-____'));
        $this->assertNull(InputMask::error('АА-'));
    }

    public function test_only_admin_manages_masks(): void
    {
        foreach (['executor', 'master', 'otk', 'repair'] as $role) {
            $this->asUser($this->worker($role))->get('/field-masks')->assertForbidden();
            $this->asUser($this->worker($role))->post('/field-masks', ['name' => 'x', 'pattern' => '99'])->assertForbidden();
        }
        $this->assertSame(0, FieldMask::count());
    }

    public function test_admin_creates_mask_for_field_and_form_checks_it(): void
    {
        $admin = $this->worker('admin');
        $html = $this->asUser($admin)->get('/field-masks')->assertOk()->getContent();
        $this->assertStringContainsString('Номер борта', $html);
        $this->assertStringContainsString('Маска не применяется', $html);   // дата, статус…

        // Создать маску прямо у поля — сразу привязывается
        $this->asUser($admin)->post('/field-masks', ['name' => 'Номер борта', 'pattern' => 'Б-9999', 'sheet' => 'pgp', 'attach_field' => 'board_number'])
            ->assertSessionHasNoErrors();
        $mask = FieldMask::firstOrFail();

        // Форма ПГП — с маской и подсказкой формата
        $exec = $this->worker();
        $form = $this->asUser($exec)->get('/records/pgp/create')->assertOk()->getContent();
        $this->assertStringContainsString('data-masks="[&quot;Б-9999&quot;]"', $form);
        $this->assertStringContainsString('Формат: Б-0000', $form);

        $data = $this->validRecord('pgp', $this->nameOf($exec));
        $this->asUser($exec)->post('/records/pgp', ['board_number' => 'Б-12'] + $data)->assertSessionHasErrors('board_number');
        $this->asUser($exec)->post('/records/pgp', ['board_number' => 'Б-0421'] + $data)->assertSessionHasNoErrors();
        $this->assertSame(1, PgpRecord::where('board_number', 'Б-0421')->count());

        // Вторая маска у поля — подходит любая
        $other = FieldMask::create(['name' => 'Старый', 'pattern' => 'AA99']);
        $this->asUser($admin)->put('/field-masks/field/pgp/board_number', ['masks' => [$mask->id, $other->id]])->assertSessionHasNoErrors();
        $this->asUser($exec)->post('/records/pgp', ['board_number' => 'ZX12'] + $data)->assertSessionHasNoErrors();

        // Снять маски — снова как угодно
        $this->asUser($admin)->put('/field-masks/field/pgp/board_number', ['masks' => []])->assertSessionHasNoErrors();
        $this->asUser($exec)->post('/records/pgp', ['board_number' => 'что угодно'] + $data)->assertSessionHasNoErrors();
    }

    public function test_old_value_passes_when_editing_record(): void
    {
        $exec = $this->worker();
        $data = $this->validRecord('pgp', $this->nameOf($exec));
        $this->asUser($exec)->post('/records/pgp', ['board_number' => 'старый номер'] + $data)->assertSessionHasNoErrors();
        $rec = PgpRecord::where('board_number', 'старый номер')->firstOrFail();

        $mask = FieldMask::create(['name' => 'Борт', 'pattern' => 'Б-9999']);
        $this->asUser($this->worker('admin'))->put('/field-masks/field/pgp/board_number', ['masks' => [$mask->id]]);

        $this->asUser($exec)->put("/records/pgp/{$rec->id}", ['board_number' => 'старый номер', 'actions_taken' => 'правка'] + $data)->assertSessionHasNoErrors();
        $this->asUser($exec)->put("/records/pgp/{$rec->id}", ['board_number' => 'новый не по маске'] + $data)->assertSessionHasErrors('board_number');
    }

    public function test_admin_changes_ip_prefix(): void
    {
        $admin = $this->worker('admin');
        $this->asUser($admin)->put('/field-masks/field/pgp/ip_address', ['prefix' => '10.0.5'])->assertSessionHasErrors('prefix');
        $this->asUser($admin)->put('/field-masks/field/pgp/ip_address', ['prefix' => '10.0.5.'])->assertSessionHasNoErrors();

        $exec = $this->worker();
        $this->assertStringContainsString('10.0.5.', $this->asUser($exec)->get('/records/pgp/create')->getContent());
        $this->asUser($exec)->post('/records/pgp', ['ip_address' => '77', 'board_number' => 'IP-тест'] + $this->validRecord('pgp', $this->nameOf($exec)))->assertSessionHasNoErrors();
        $this->assertSame('10.0.5.77', PgpRecord::where('board_number', 'IP-тест')->value('ip_address'));

        // Маска к полю «Статус» не применяется
        $mask = FieldMask::create(['name' => 'x', 'pattern' => '99']);
        $this->asUser($admin)->put('/field-masks/field/pgp/status', ['masks' => [$mask->id]])->assertStatus(422);
    }
}
