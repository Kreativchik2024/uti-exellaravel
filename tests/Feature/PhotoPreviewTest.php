<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SiteHelpers;
use Tests\TestCase;

/** Превью фото в чате, запрет копирования и печати. */
class PhotoPreviewTest extends TestCase
{
    use RefreshDatabase, SiteHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_chat_reply_and_list_show_photo_thumbnail_not_emoji_text(): void
    {
        [$a, $b] = [User::factory()->admin()->create(), User::factory()->admin()->create()];
        $photoMsg = $this->actingAs($a)->post('/chat/messages', ['peer' => 0, 'files' => [UploadedFile::fake()->image('борт.jpg', 400, 300)]],
            ['Accept' => 'application/json'])->assertCreated()->json('message.id');

        $reply = $this->actingAs($b)->postJson('/chat/messages', ['peer' => 0, 'body' => 'Вижу трещину', 'reply_to_id' => $photoMsg])
            ->assertCreated()->json('message.reply_to');
        $this->assertSame('Фото', $reply['media']['label']);
        $this->assertNotNull($reply['media']['thumb_url'], 'в цитате — миниатюра фото');
        $this->assertStringNotContainsString('🖼', json_encode($reply, JSON_UNESCAPED_UNICODE));

        $last = $this->actingAs($a)->getJson('/chat/conversations')->json('general.last');
        $this->assertSame('Вижу трещину', $last['body']);
    }

    public function test_chat_text_copyable_with_links_and_printing_blocked(): void
    {
        $user = User::factory()->admin()->create();
        $html = $this->actingAs($user)->get('/profile')->assertOk()->getContent();

        // текст переписки можно выделить и скопировать, ссылки в нём кликабельные
        $this->assertStringNotContainsString('ui-chat__messages ui-nocopy', $html);
        $this->assertStringNotContainsString('@copy.prevent', $html);
        $this->assertStringContainsString('ui-chat__url', $html);
        $this->assertStringContainsString('Печать страниц системы запрещена', $html);
        $this->assertStringContainsString("ui-print-blocked", file_get_contents(resource_path('css/app.css')));
    }
}
