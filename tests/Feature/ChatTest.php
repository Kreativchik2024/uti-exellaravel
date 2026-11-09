<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    /** Админы: у исполнителей «один вход на устройство» мешает серии запросов в тесте. */
    private function user(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_guest_cannot_use_chat(): void
    {
        $this->getJson('/chat/summary')->assertUnauthorized();
        $this->postJson('/chat/messages', ['peer' => 0, 'body' => 'x'])->assertUnauthorized();
    }

    public function test_general_channel_is_visible_to_everyone(): void
    {
        [$a, $b] = [$this->user(), $this->user()];

        $this->actingAs($a)->postJson('/chat/messages', ['peer' => 0, 'body' => 'Всем привет'])
            ->assertCreated()
            ->assertJsonPath('message.body', 'Всем привет')
            ->assertJsonPath('message.mine', true);

        $this->actingAs($b)->getJson('/chat/messages?peer=0')
            ->assertOk()
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.mine', false);
    }

    public function test_direct_messages_are_private(): void
    {
        [$a, $b, $c] = [$this->user(), $this->user(), $this->user()];

        $this->actingAs($a)->postJson('/chat/messages', ['peer' => $b->id, 'body' => 'Секрет'])->assertCreated();

        // Получатель видит
        $this->actingAs($b)->getJson("/chat/messages?peer={$a->id}")->assertJsonCount(1, 'messages');
        // Третий — нет: его переписка с отправителем пуста, в общем канале тоже ничего
        $this->actingAs($c)->getJson("/chat/messages?peer={$a->id}")->assertJsonCount(0, 'messages');
        $this->actingAs($c)->getJson('/chat/messages?peer=0')->assertJsonCount(0, 'messages');
    }

    public function test_unread_counters_and_mark_read(): void
    {
        [$a, $b] = [$this->user(), $this->user()];

        $this->actingAs($a)->postJson('/chat/messages', ['peer' => 0, 'body' => 'общий']);
        $this->actingAs($a)->postJson('/chat/messages', ['peer' => $b->id, 'body' => 'лично 1']);
        $last = $this->actingAs($a)->postJson('/chat/messages', ['peer' => $b->id, 'body' => 'лично 2'])->json('message.id');

        // Отправителю его же сообщения не считаются непрочитанными
        $this->actingAs($a)->getJson('/chat/summary')->assertJsonPath('unread', 0);

        $this->actingAs($b)->getJson('/chat/summary')->assertJsonPath('unread', 3);
        $this->actingAs($b)->getJson('/chat/conversations')
            ->assertJsonPath('general.unread', 1)
            ->assertJsonPath('direct.0.peer', $a->id)
            ->assertJsonPath('direct.0.unread', 2);

        $this->actingAs($b)->postJson('/chat/read', ['peer' => $a->id, 'last_id' => $last])->assertOk();
        $this->actingAs($b)->getJson('/chat/summary')->assertJsonPath('unread', 1);
    }

    public function test_cannot_write_to_self_or_inactive_user(): void
    {
        $a = $this->user();
        $inactive = User::factory()->inactive()->create();

        $this->actingAs($a)->postJson('/chat/messages', ['peer' => $a->id, 'body' => 'x'])->assertStatus(422);
        $this->actingAs($a)->postJson('/chat/messages', ['peer' => $inactive->id, 'body' => 'x'])->assertNotFound();
        $this->assertSame(0, ChatMessage::count());
    }

    public function test_message_validation(): void
    {
        $a = $this->user();

        $this->actingAs($a)->postJson('/chat/messages', ['peer' => 0, 'body' => '   '])->assertStatus(422);
        $this->actingAs($a)->postJson('/chat/messages', ['peer' => 0, 'body' => str_repeat('я', 2001)])->assertStatus(422);
        $this->assertSame(0, ChatMessage::count());
    }

    public function test_body_is_returned_as_plain_text(): void
    {
        $a = $this->user();
        $xss = '<img src=x onerror=alert(1)>';

        $this->actingAs($a)->postJson('/chat/messages', ['peer' => 0, 'body' => $xss])
            ->assertCreated()
            ->assertJsonPath('message.body', $xss); // хранится как есть, а выводится через x-text (без HTML)
    }

    public function test_sending_is_rate_limited(): void
    {
        $a = $this->user();

        for ($i = 0; $i < 20; $i++) {
            $this->actingAs($a)->postJson('/chat/messages', ['peer' => 0, 'body' => "сообщение {$i}"])->assertCreated();
        }
        $this->actingAs($a)->postJson('/chat/messages', ['peer' => 0, 'body' => 'лишнее'])->assertStatus(429);
    }

    public function test_older_messages_pagination(): void
    {
        $a = $this->user();
        foreach (range(1, 55) as $i) {
            ChatMessage::create(['sender_id' => $a->id, 'recipient_id' => null, 'body' => "m{$i}"]);
        }

        $page = $this->actingAs($a)->getJson('/chat/messages?peer=0')->assertJsonPath('has_more', true);
        $this->assertCount(50, $page->json('messages'));
        $this->assertSame('m55', $page->json('messages.49.body'));

        $older = $this->actingAs($a)->getJson('/chat/messages?peer=0&before=' . $page->json('messages.0.id'))
            ->assertJsonPath('has_more', false);
        $this->assertCount(5, $older->json('messages'));
    }
}
