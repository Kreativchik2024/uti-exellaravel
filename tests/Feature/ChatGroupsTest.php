<?php

namespace Tests\Feature;

use App\Models\ChatGroup;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Групповые беседы: создание с несколькими участниками, приватность,
 * добавление сразу нескольких, непрочитанные, исключение и выход.
 */
class ChatGroupsTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->admin()->create();
    }

    private function createGroup(User $owner, array $members, string $name = 'Смена А'): int
    {
        return $this->actingAs($owner)->postJson('/chat/groups', ['name' => $name, 'members' => collect($members)->pluck('id')->all()])
            ->assertCreated()
            ->json('group');
    }

    public function test_create_group_with_several_members(): void
    {
        [$a, $b, $c] = [$this->user(), $this->user(), $this->user()];

        $res = $this->actingAs($a)->postJson('/chat/groups', ['name' => 'Смена А', 'members' => [$b->id, $c->id]])
            ->assertCreated()
            ->assertJsonPath('name', 'Смена А')
            ->assertJsonCount(3, 'members');

        $this->assertTrue(ChatGroup::findOrFail($res->json('group'))->hasMember($a->id), 'создатель — участник');
    }

    public function test_group_requires_name_and_members(): void
    {
        $a = $this->user();
        $this->actingAs($a)->postJson('/chat/groups', ['name' => '', 'members' => [$this->user()->id]])->assertStatus(422);
        $this->actingAs($a)->postJson('/chat/groups', ['name' => 'Пусто', 'members' => []])->assertStatus(422);
        $this->actingAs($a)->postJson('/chat/groups', ['name' => 'Только я', 'members' => [$a->id]])->assertStatus(422);
    }

    public function test_group_messages_are_visible_only_to_members(): void
    {
        [$a, $b, $outsider] = [$this->user(), $this->user(), $this->user()];
        $g = $this->createGroup($a, [$b]);

        $this->actingAs($a)->postJson('/chat/messages', ['group' => $g, 'body' => 'Привет группе'])->assertCreated();

        $this->actingAs($b)->getJson("/chat/messages?group={$g}")->assertOk()->assertJsonCount(1, 'messages');
        $this->actingAs($outsider)->getJson("/chat/messages?group={$g}")->assertNotFound();
        $this->actingAs($outsider)->postJson('/chat/messages', ['group' => $g, 'body' => 'влезу'])->assertNotFound();

        // В общий чат групповое сообщение не попадает
        $this->actingAs($outsider)->getJson('/chat/messages?peer=0')->assertJsonCount(0, 'messages');
        $this->actingAs($b)->getJson('/chat/messages?peer=0')->assertJsonCount(0, 'messages');

        // Вложения и реакции группы чужим недоступны
        $id = ChatMessage::firstOrFail()->id;
        $this->actingAs($outsider)->postJson("/chat/messages/{$id}/react", ['emoji' => '👍'])->assertNotFound();
        $this->actingAs($b)->postJson("/chat/messages/{$id}/react", ['emoji' => '👍'])->assertOk();
    }

    public function test_add_several_members_at_once(): void
    {
        [$a, $b, $c, $d] = [$this->user(), $this->user(), $this->user(), $this->user()];
        $g = $this->createGroup($a, [$b]);
        $this->actingAs($a)->postJson('/chat/messages', ['group' => $g, 'body' => 'старое']);

        // Добавить может любой участник, сразу нескольких; уже состоящих пропускаем
        $this->actingAs($b)->postJson("/chat/groups/{$g}/members", ['user_ids' => [$c->id, $d->id, $a->id]])
            ->assertOk()
            ->assertJsonPath('added', 2)
            ->assertJsonCount(4, 'members');

        // Новичок видит историю, но она не считается непрочитанной
        $this->actingAs($c)->getJson("/chat/messages?group={$g}")->assertJsonCount(1, 'messages');
        $this->actingAs($c)->getJson('/chat/summary')->assertJsonPath('unread', 0);

        // Не участник добавить не может
        $e = $this->user();
        $this->actingAs($e)->postJson("/chat/groups/{$g}/members", ['user_ids' => [$e->id]])->assertNotFound();
    }

    public function test_unread_and_conversations_list(): void
    {
        [$a, $b] = [$this->user(), $this->user()];
        $g = $this->createGroup($a, [$b], 'Ночная смена');

        $last = $this->actingAs($a)->postJson('/chat/messages', ['group' => $g, 'body' => 'раз'])->json('message.id');
        $this->actingAs($a)->postJson('/chat/messages', ['group' => $g, 'body' => 'два']);

        $this->actingAs($b)->getJson('/chat/summary')->assertJsonPath('unread', 2);
        $this->actingAs($b)->getJson('/chat/conversations')
            ->assertJsonPath('groups.0.name', 'Ночная смена')
            ->assertJsonPath('groups.0.unread', 2)
            ->assertJsonPath('groups.0.members', 2)
            ->assertJsonPath('general.unread', 0);

        $this->actingAs($b)->postJson('/chat/read', ['group' => $g, 'last_id' => $last + 1])->assertOk();
        $this->actingAs($b)->getJson('/chat/summary')->assertJsonPath('unread', 0);

        // Отметки «прочитано» группы не путаются с общим чатом
        $this->actingAs($a)->postJson('/chat/messages', ['peer' => 0, 'body' => 'всем']);
        $this->actingAs($b)->getJson('/chat/summary')->assertJsonPath('unread', 1);
    }

    public function test_remove_member_and_leave(): void
    {
        [$a, $b, $c] = [$this->user(), User::factory()->create(), $this->user()];   // $b — исполнитель
        $g = $this->createGroup($a, [$b, $c]);

        // Обычный участник (исполнитель, не создатель) исключать не может, создатель — может
        // (у исполнителя в тесте — один запрос: «один вход на устройство» закрывает сессию)
        $this->actingAs($b)->postJson("/chat/groups/{$g}/remove", ['user_id' => $c->id])->assertForbidden();
        $this->actingAs($a)->postJson("/chat/groups/{$g}/remove", ['user_id' => $b->id])->assertOk()->assertJsonCount(2, 'members');
        $this->assertFalse(ChatGroup::findOrFail($g)->hasMember($b->id));

        // Создатель вышел — права переходят оставшемуся
        $this->actingAs($a)->postJson("/chat/groups/{$g}/remove", ['user_id' => $a->id])->assertOk()->assertJsonPath('left', true);
        $this->assertSame($c->id, (int) ChatGroup::findOrFail($g)->created_by);
        $this->actingAs($a)->getJson("/chat/messages?group={$g}")->assertNotFound();

        // Последний вышел — группа удалена
        $this->actingAs($c)->postJson("/chat/groups/{$g}/remove", ['user_id' => $c->id])->assertOk();
        $this->assertNull(ChatGroup::find($g));
    }

    public function test_rename_and_pin_rules(): void
    {
        [$a, $b] = [$this->user(), $this->user()];
        $g = $this->createGroup($a, [$b]);

        $this->actingAs($a)->postJson("/chat/groups/{$g}/rename", ['name' => 'Новая'])->assertOk()->assertJsonPath('name', 'Новая');

        // Закрепление — только для общего чата
        $id = $this->actingAs($a)->postJson('/chat/messages', ['group' => $g, 'body' => 'x'])->json('message.id');
        $this->actingAs($a)->postJson("/chat/messages/{$id}/pin")->assertStatus(422);
    }
}
