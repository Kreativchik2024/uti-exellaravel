<?php

namespace Tests\Feature;

use App\Models\PgpRecord;
use App\Services\FieldPrefills;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SiteHelpers;
use Tests\TestCase;

/** Админ → Заготовки полей: текст уже стоит в пустом поле, дальше — свободный ввод без строгой проверки. */
class FieldPrefillTest extends TestCase
{
    use RefreshDatabase, SiteHelpers;

    public function test_only_admin_manages_prefills(): void
    {
        foreach (['executor', 'master', 'otk', 'repair'] as $role) {
            $this->asUser($this->worker($role))->get('/field-prefills')->assertForbidden();
            $this->asUser($this->worker($role))->put('/field-prefills/pgp', ['prefill' => ['board_number' => 'X-']])->assertForbidden();
        }
        $this->assertNull(Setting::get(FieldPrefills::key('pgp', 'board_number')));
    }

    public function test_prefill_stands_in_empty_field_and_input_is_free(): void
    {
        $admin = $this->worker('admin');
        $page = $this->asUser($admin)->get('/field-prefills')->assertOk()->getContent();
        $this->assertStringContainsString('Номер борта', $page);
        $this->assertStringContainsString('Заготовка не нужна', $page);   // дата, статус…

        $this->asUser($admin)->put('/field-prefills/pgp', ['prefill' => ['board_number' => 'УМН-']])->assertSessionHasNoErrors();
        Setting::forgetMemo();

        $exec = $this->worker();
        $form = $this->asUser($exec)->get('/records/pgp/create')->assertOk()->getContent();
        $this->assertStringContainsString('value="УМН-"', $form);
        $this->assertStringContainsString('data-prefill="УМН-"', $form);

        $data = $this->validRecord('pgp', $this->nameOf($exec));
        // Свободный ввод: и с заготовкой, и без неё, и что угодно
        foreach (['УМН-0421', 'МН-77', 'что угодно'] as $v) {
            $this->asUser($exec)->post('/records/pgp', ['board_number' => $v] + $data)->assertSessionHasNoErrors();
            $this->assertSame(1, PgpRecord::where('board_number', $v)->count());
        }

        // Осталась только заготовка — поле пустое (номер борта необязателен при «Не сдан»)
        $this->asUser($exec)->post('/records/pgp', ['board_number' => 'УМН-', 'status' => 'Не сдан', 'ip_address' => ''] + $data)->assertSessionHasNoErrors();
        $this->assertSame(0, PgpRecord::where('board_number', 'УМН-')->count());
    }

    public function test_admin_changes_ip_prefix(): void
    {
        $admin = $this->worker('admin');
        $this->asUser($admin)->put('/field-prefills/pgp', ['prefix' => ['ip_address' => '10.0.5']])->assertSessionHasErrors();
        $this->asUser($admin)->put('/field-prefills/pgp', ['prefix' => ['ip_address' => '10.0.5.']])->assertSessionHasNoErrors();
        Setting::forgetMemo();

        $exec = $this->worker();
        $this->assertStringContainsString('10.0.5.', $this->asUser($exec)->get('/records/pgp/create')->getContent());
        $this->asUser($exec)->post('/records/pgp', ['ip_address' => '77', 'board_number' => 'IP-тест'] + $this->validRecord('pgp', $this->nameOf($exec)))->assertSessionHasNoErrors();
        $this->assertSame('10.0.5.77', PgpRecord::where('board_number', 'IP-тест')->value('ip_address'));
    }
}
