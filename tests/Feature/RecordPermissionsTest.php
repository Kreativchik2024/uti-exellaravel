<?php

namespace Tests\Feature;

use App\Models\PgpRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SiteHelpers;
use Tests\TestCase;

/**
 * Права на записи учёта и подтверждение исполнителей ПИН-кодом.
 */
class RecordPermissionsTest extends TestCase
{
    use RefreshDatabase, SiteHelpers;

    private function createPgp($user, array $extra = []): PgpRecord
    {
        $this->asUser($user)->post('/records/pgp', array_merge($this->validRecord('pgp', $this->nameOf($user)), $extra))
            ->assertSessionHasNoErrors();

        return PgpRecord::latest('id')->firstOrFail();
    }

    // ---------------------------------------------------------------- исполнители и ПИН

    public function test_executor_records_himself_without_pin(): void
    {
        $exec = $this->worker();
        $this->createPgp($exec);
        $this->assertSame(1, PgpRecord::count());
    }

    public function test_adding_colleague_requires_his_pin(): void
    {
        $exec = $this->worker();
        $colleague = $this->worker();

        $this->asUser($exec)->post('/records/pgp', array_merge($this->validRecord('pgp', $this->nameOf($exec)), ['worker2' => $this->nameOf($colleague)]))
            ->assertSessionHasErrors('worker2');
        $this->assertSame(0, PgpRecord::count());
    }

    public function test_wrong_pin_is_rejected_and_correct_pin_allows_colleague(): void
    {
        $exec = $this->worker();
        $colleague = $this->worker();

        $this->asUser($exec)->postJson('/records-pin-check', ['short_name' => $this->nameOf($colleague), 'pin' => '9999'])
            ->assertStatus(422)->assertJsonPath('ok', false);

        $this->asUser($exec)->postJson('/records-pin-check', ['short_name' => $this->nameOf($colleague), 'pin' => '1234'])
            ->assertOk()->assertJsonPath('ok', true);

        // ПИН подтверждён в этой сессии — коллегу можно указать
        $this->asUser($exec)->post('/records/pgp', array_merge($this->validRecord('pgp', $this->nameOf($exec)), ['worker2' => $this->nameOf($colleague)]))
            ->assertSessionHasNoErrors();
        $this->assertSame($this->nameOf($colleague), PgpRecord::firstOrFail()->worker2);
    }

    public function test_same_worker_twice_is_rejected(): void
    {
        $master = $this->worker('master');
        $name = $this->nameOf($master);

        $this->asUser($master)->post('/records/pgp', array_merge($this->validRecord('pgp', $name), ['worker2' => $name]))
            ->assertSessionHasErrors('worker2');
    }

    public function test_unknown_or_inactive_worker_is_rejected(): void
    {
        $master = $this->worker('master');

        $this->asUser($master)->post('/records/pgp', $this->validRecord('pgp', 'Несуществующий Н.Н.'))
            ->assertSessionHasErrors('worker1');
    }

    // ---------------------------------------------------------------- свои и чужие записи

    public function test_executor_edits_own_record_but_not_others(): void
    {
        $author = $this->worker();
        $other = $this->worker();
        $record = $this->createPgp($author);

        $this->asUser($author)->put("/records/pgp/{$record->id}", array_merge($this->validRecord('pgp', $this->nameOf($author)), ['board_number' => 'ИЗМЕНЁН']))
            ->assertSessionHasNoErrors();
        $this->assertSame('ИЗМЕНЁН', $record->fresh()->board_number);

        $this->asUser($other)->put("/records/pgp/{$record->id}", array_merge($this->validRecord('pgp', $this->nameOf($other)), ['board_number' => 'ЧУЖОЙ']))
            ->assertSessionHasErrors('general');
        $this->assertSame('ИЗМЕНЁН', $record->fresh()->board_number);
    }

    public function test_only_master_or_admin_can_delete(): void
    {
        $author = $this->worker();
        $record = $this->createPgp($author);

        $this->asUser($author)->delete("/records/pgp/{$record->id}")->assertSessionHasErrors('general');
        $this->assertNotNull($record->fresh());

        $this->asUser($this->worker('master'))->delete("/records/pgp/{$record->id}");
        $this->assertNull($record->fresh());
    }

    public function test_record_locks_for_executor_after_24_hours_but_not_for_master(): void
    {
        $author = $this->worker();
        $record = $this->createPgp($author);
        $record->forceFill(['created_at' => now()->subHours(25)])->saveQuietly();

        $this->asUser($author)->put("/records/pgp/{$record->id}", array_merge($this->validRecord('pgp', $this->nameOf($author)), ['board_number' => 'ПОЗДНО']))
            ->assertSessionHasErrors('general');
        $this->assertNotSame('ПОЗДНО', $record->fresh()->board_number);

        $master = $this->worker('master');
        $this->asUser($master)->put("/records/pgp/{$record->id}", array_merge($this->validRecord('pgp', $this->nameOf($master)), ['board_number' => 'МАСТЕР']))
            ->assertSessionHasNoErrors();
        $this->assertSame('МАСТЕР', $record->fresh()->board_number);
    }

    public function test_guest_cannot_see_records(): void
    {
        $this->get('/records/pgp')->assertRedirect('/login');
    }
}
