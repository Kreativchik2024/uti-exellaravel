<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SiteHelpers;
use Tests\TestCase;

/** Повторная отправка формы записи не создаёт дубль. */
class DuplicateRecordTest extends TestCase
{
    use RefreshDatabase, SiteHelpers;

    public function test_same_form_submitted_twice_creates_one_record(): void
    {
        $user = $this->worker('master');
        $data = $this->validRecord('cross1', $this->nameOf($user)) + ['_submit_token' => '0b1c2d3e-aaaa-bbbb-cccc-1234567890ab'];
        $model = $this->modelOf('cross1');

        $this->asUser($user)->post('/records/cross1', $data)->assertRedirect(route('records.index', 'cross1'));
        $id = $model::latest('id')->value('id');

        // Двойной клик / «Назад» и отправить ещё раз — та же форма
        $this->asUser($user)->post('/records/cross1', $data)
            ->assertRedirect(route('records.show', ['cross1', $id]))
            ->assertSessionHas('ok', fn ($m) => str_contains($m, 'дубль'));

        $this->assertSame(1, $model::count());
    }

    public function test_identical_record_within_a_minute_is_refused_then_allowed(): void
    {
        $user = $this->worker('master');
        $model = $this->modelOf('cross1');
        $data = $this->validRecord('cross1', $this->nameOf($user));

        $this->asUser($user)->post('/records/cross1', $data + ['_submit_token' => 'aaaaaaaa-0000-0000-0000-000000000001']);
        // Другая форма (новый номер), но точно такие же данные — через несколько секунд
        $this->asUser($user)->post('/records/cross1', $data + ['_submit_token' => 'aaaaaaaa-0000-0000-0000-000000000002'])
            ->assertSessionHasErrors('general');
        $this->assertSame(1, $model::count());

        // Через минуту такую же запись сохранить можно (нужна вторая — например, повторная проверка)
        $this->travel(61)->seconds();
        $this->asUser($user)->post('/records/cross1', $data + ['_submit_token' => 'aaaaaaaa-0000-0000-0000-000000000003'])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, $model::count());
    }

    public function test_validation_error_does_not_burn_the_form(): void
    {
        $user = $this->worker('master');
        $model = $this->modelOf('cross1');
        $data = $this->validRecord('cross1', $this->nameOf($user)) + ['_submit_token' => 'bbbbbbbb-0000-0000-0000-000000000001'];

        $bad = $data; $bad['record_date'] = '';
        $this->asUser($user)->post('/records/cross1', $bad)->assertSessionHasErrors('record_date');

        // Исправили поле и отправили ту же форму — запись создаётся
        $this->asUser($user)->post('/records/cross1', $data)->assertSessionHasNoErrors();
        $this->assertSame(1, $model::count());
    }

    public function test_form_has_one_time_token(): void
    {
        $this->asUser($this->worker('master'))->get('/records/cross1/create')->assertOk()->assertSee('name="_submit_token"', false);
    }
}
