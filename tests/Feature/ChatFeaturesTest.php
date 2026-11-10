<?php

namespace Tests\Feature;

use App\Models\ChatAttachment;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Чат «как в Telegram»: реакции, ответы, вложения, «печатает…»,
 * открытие на первом непрочитанном, хранение 7 дней.
 */
class ChatFeaturesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');   // файлы вложений — во временное хранилище
    }

    /** Админы: у исполнителей «один вход на устройство» мешает серии запросов в тесте. */
    private function user(): User
    {
        return User::factory()->admin()->create();
    }

    private function say(User $u, string $body, int $peer = 0, array $extra = []): int
    {
        return $this->actingAs($u)->postJson('/chat/messages', ['peer' => $peer, 'body' => $body] + $extra)
            ->assertCreated()->json('message.id');
    }

    // ---------------------------------------------------------------- реакции

    public function test_reaction_toggles_and_is_delivered_by_polling(): void
    {
        [$a, $b] = [$this->user(), $this->user()];
        $id = $this->say($a, 'Привет');
        $since = $this->actingAs($a)->getJson("/chat/messages?peer=0&after={$id}")->json('server_time');

        $this->travel(2)->seconds();
        $this->actingAs($b)->postJson("/chat/messages/{$id}/react", ['emoji' => '👍'])
            ->assertOk()
            ->assertJsonPath('reactions.0.emoji', '👍')
            ->assertJsonPath('reactions.0.count', 1)
            ->assertJsonPath('reactions.0.mine', true);

        // Автор получает изменение реакций опросом (updates)
        $this->actingAs($a)->getJson("/chat/messages?peer=0&after={$id}&since=" . urlencode($since))
            ->assertJsonPath('updates.0.id', $id)
            ->assertJsonPath('updates.0.reactions.0.count', 1)
            ->assertJsonPath('updates.0.reactions.0.mine', false);

        // Повторный клик снимает реакцию
        $this->actingAs($b)->postJson("/chat/messages/{$id}/react", ['emoji' => '👍'])->assertJsonPath('reactions', []);
    }

    public function test_only_one_reaction_per_person_new_one_replaces_old(): void
    {
        [$a, $b] = [$this->user(), $this->user()];
        $id = $this->say($a, 'Привет');

        $this->actingAs($b)->postJson("/chat/messages/{$id}/react", ['emoji' => '👍'])->assertOk();
        $this->actingAs($b)->postJson("/chat/messages/{$id}/react", ['emoji' => '🔥'])
            ->assertJsonCount(1, 'reactions')
            ->assertJsonPath('reactions.0.emoji', '🔥');
        $this->actingAs($a)->postJson("/chat/messages/{$id}/react", ['emoji' => '🔥'])
            ->assertJsonPath('reactions.0.count', 2);

        $this->assertSame(2, \App\Models\ChatReaction::count());
    }

    public function test_emoji_in_message_text_is_rejected(): void
    {
        $a = $this->user();
        $this->actingAs($a)->postJson('/chat/messages', ['peer' => 0, 'body' => 'Готово 👍'])->assertStatus(422)->assertJsonValidationErrors('body');
        $this->actingAs($a)->postJson('/chat/messages', ['peer' => 0, 'body' => 'Пост 5 → борт #12, 3*4'])->assertCreated();
    }

    public function test_emoji_in_names_rejected_and_hidden_in_chat(): void
    {
        $a = $this->user();

        $this->actingAs($a)->patch('/profile', ['name' => 'Вася 🚀', 'email' => $a->email])->assertSessionHasErrors('name');
        $this->actingAs($a)->patch('/profile', ['name' => 'Иванов 2', 'email' => $a->email])->assertSessionHasNoErrors();

        // Имя с эмодзи, сохранённое раньше, в чате показывается без них
        $a->forceFill(['name' => '🔥Петров🔥 ✅'])->saveQuietly();
        $this->say($a, 'тест');
        $this->actingAs($this->user())->getJson('/chat/messages?peer=0')->assertJsonPath('messages.0.sender_name', 'Петров');
    }

    public function test_only_allowed_reactions_and_only_visible_messages(): void
    {
        [$a, $b, $c] = [$this->user(), $this->user(), $this->user()];
        $id = $this->say($a, 'лично', $b->id);

        $this->actingAs($b)->postJson("/chat/messages/{$id}/react", ['emoji' => '💩'])->assertStatus(422);
        $this->actingAs($c)->postJson("/chat/messages/{$id}/react", ['emoji' => '👍'])->assertNotFound();   // чужая переписка
    }

    // ---------------------------------------------------------------- ответы

    public function test_reply_shows_quote_and_must_be_in_same_channel(): void
    {
        [$a, $b] = [$this->user(), $this->user()];
        $question = $this->say($a, 'Кто берёт борт?');
        $private = $this->say($a, 'секрет', $b->id);

        $this->actingAs($b)->postJson('/chat/messages', ['peer' => 0, 'body' => 'Я беру', 'reply_to_id' => $question])
            ->assertCreated()
            ->assertJsonPath('message.reply_to.id', $question)
            ->assertJsonPath('message.reply_to.preview', 'Кто берёт борт?');

        // Ответить в общем чате на личное сообщение нельзя
        $this->actingAs($b)->postJson('/chat/messages', ['peer' => 0, 'body' => 'x', 'reply_to_id' => $private])->assertStatus(422);
    }

    // ---------------------------------------------------------------- вложения

    public function test_image_is_compressed_to_webp_with_thumbnail(): void
    {
        $a = $this->user();

        $msg = $this->actingAs($a)->post('/chat/messages', [
            'peer' => 0, 'files' => [UploadedFile::fake()->image('фото.jpg', 3000, 2000)],
        ], ['Accept' => 'application/json'])->assertCreated()->json('message');

        $this->assertNull($msg['body'], 'сообщение может быть только с картинкой');
        $this->assertSame('image', $msg['attachments'][0]['kind']);
        $this->assertSame(1920, $msg['attachments'][0]['width'], 'большие фото уменьшаются до 1920');

        $att = ChatAttachment::firstOrFail();
        Storage::disk('local')->assertExists([$att->path, $att->thumb_path]);
        $this->assertStringEndsWith('.webp', $att->path);

        $this->actingAs($a)->get($msg['attachments'][0]['thumb_url'])->assertOk();
    }

    public function test_file_attachment_downloads_with_original_name(): void
    {
        $a = $this->user();

        $msg = $this->actingAs($a)->post('/chat/messages', [
            'peer' => 0, 'body' => 'Инструкция', 'files' => [UploadedFile::fake()->create('Инструкция.pdf', 120, 'application/pdf')],
        ], ['Accept' => 'application/json'])->assertCreated()->json('message');

        $this->assertSame('file', $msg['attachments'][0]['kind']);
        $download = $this->actingAs($a)->get($msg['attachments'][0]['url'])->assertOk();
        $this->assertStringContainsString('attachment', $download->headers->get('Content-Disposition'));
    }

    public function test_dangerous_file_types_are_rejected(): void
    {
        $a = $this->user();

        foreach (['shell.php', 'run.exe', 'page.html', 'vector.svg'] as $name) {
            $this->actingAs($a)->post('/chat/messages', ['peer' => 0, 'files' => [UploadedFile::fake()->create($name, 1)]], ['Accept' => 'application/json'])
                ->assertStatus(422);
        }
        $this->assertSame(0, ChatMessage::count());
    }

    public function test_private_attachment_is_not_visible_to_others(): void
    {
        [$a, $b, $c] = [$this->user(), $this->user(), $this->user()];

        $url = $this->actingAs($a)->post('/chat/messages', [
            'peer' => $b->id, 'files' => [UploadedFile::fake()->create('договор.pdf', 10, 'application/pdf')],
        ], ['Accept' => 'application/json'])->json('message.attachments.0.url');

        $this->actingAs($b)->get($url)->assertOk();
        $this->actingAs($c)->get($url)->assertNotFound();
    }

    public function test_empty_message_without_files_is_rejected(): void
    {
        $this->actingAs($this->user())->postJson('/chat/messages', ['peer' => 0, 'body' => '   '])->assertStatus(422);
    }

    // ---------------------------------------------------------------- «печатает…»

    public function test_typing_indicator_is_shown_to_others_but_not_to_self(): void
    {
        [$a, $b] = [$this->user(), $this->user()];

        $this->actingAs($a)->postJson('/chat/typing', ['peer' => 0])->assertOk();

        $this->actingAs($b)->getJson('/chat/messages?peer=0')->assertJsonCount(1, 'typing');
        $this->actingAs($a)->getJson('/chat/messages?peer=0')->assertJsonCount(0, 'typing');

        $this->travel(10)->seconds();   // отметка живёт несколько секунд
        $this->actingAs($b)->getJson('/chat/messages?peer=0')->assertJsonCount(0, 'typing');
    }

    // ---------------------------------------------------------------- непрочитанные

    public function test_opening_chat_starts_at_first_unread(): void
    {
        [$a, $b] = [$this->user(), $this->user()];
        // Напрямую в базе: через API упёрлись бы в лимит 20 сообщений в минуту
        foreach (range(1, 30) as $i) ChatMessage::create(['sender_id' => $a->id, 'body' => "старое $i"]);
        $this->actingAs($b)->postJson('/chat/read', ['peer' => 0, 'last_id' => ChatMessage::max('id')]);
        $firstNew = ChatMessage::create(['sender_id' => $a->id, 'body' => 'новое 1'])->id;
        ChatMessage::create(['sender_id' => $a->id, 'body' => 'новое 2']);

        $res = $this->actingAs($b)->getJson('/chat/messages?peer=0&initial=1')->assertOk();
        $this->assertSame($firstNew, $res->json('first_unread_id'));

        $bodies = collect($res->json('messages'))->pluck('body');
        $this->assertSame('новое 2', $bodies->last());
        $this->assertCount(15 + 2, $bodies, '15 прочитанных для контекста + все непрочитанные');
        $this->assertTrue($res->json('has_more'), 'выше есть ещё старые');
    }

    public function test_without_unread_chat_opens_at_bottom(): void
    {
        $a = $this->user();
        $this->say($a, 'моё');

        $this->actingAs($a)->getJson('/chat/messages?peer=0&initial=1')->assertJsonPath('first_unread_id', null);
    }

    // ---------------------------------------------------------------- 7 дней

    public function test_messages_older_than_7_days_are_hidden_and_pruned_with_files(): void
    {
        $a = $this->user();
        $this->actingAs($a)->post('/chat/messages', [
            'peer' => 0, 'body' => 'старое', 'files' => [UploadedFile::fake()->create('old.pdf', 5, 'application/pdf')],
        ], ['Accept' => 'application/json'])->assertCreated();
        $old = ChatMessage::firstOrFail();
        $path = $old->attachments()->value('path');
        $old->forceFill(['created_at' => now()->subDays(8)])->saveQuietly();
        $this->say($a, 'свежее');

        // Старое не показывается даже до очистки
        $this->actingAs($a)->getJson('/chat/messages?peer=0')->assertJsonCount(1, 'messages');

        $this->artisan('chat:prune')->assertSuccessful();

        $this->assertNull($old->fresh());
        $this->assertSame(1, ChatMessage::count());
        $this->assertSame(0, ChatAttachment::count());
        Storage::disk('local')->assertMissing($path);
    }
}
