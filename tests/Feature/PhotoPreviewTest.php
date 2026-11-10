<?php

namespace Tests\Feature;

use App\Models\RecordPhoto;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SiteHelpers;
use Tests\TestCase;

/** Фото к записям учёта, превью фото в чате, запрет копирования и печати. */
class PhotoPreviewTest extends TestCase
{
    use RefreshDatabase, SiteHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_record_photos_upload_show_delete_and_go_with_record(): void
    {
        $master = $this->worker('master');
        $data = $this->validRecord('cross1', $this->nameOf($master)) + [
            'photos' => [UploadedFile::fake()->image('борт.jpg', 2500, 1800), UploadedFile::fake()->image('узел.png', 800, 600)],
        ];

        $this->asUser($master)->post('/records/cross1', $data)->assertRedirect()->assertSessionHasNoErrors();
        $record = $this->modelOf('cross1')::latest('id')->firstOrFail();
        $photos = RecordPhoto::of('cross1', $record->id)->get();
        $this->assertCount(2, $photos);
        $this->assertSame(1920, $photos[0]->width, 'большие фото уменьшаются до 1920');
        Storage::disk('local')->assertExists([$photos[0]->path, $photos[0]->thumb_path]);

        // Превью на странице записи и в форме редактирования
        $this->asUser($master)->get("/records/cross1/{$record->id}")->assertOk()->assertSee('Фото · 2')->assertSee($photos[0]->url(true), false);
        $this->asUser($master)->get("/records/cross1/{$record->id}/edit")->assertOk()->assertSee('name="delete_photos[]"', false);
        $this->asUser($master)->get($photos[0]->url(true))->assertOk()->assertHeader('Content-Type', 'image/webp');

        // Удаление одного фото при сохранении
        $edit = $this->validRecord('cross1', $this->nameOf($master)) + ['delete_photos' => [$photos[0]->id]];
        $this->asUser($master)->put("/records/cross1/{$record->id}", $edit)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, RecordPhoto::of('cross1', $record->id)->count());
        Storage::disk('local')->assertMissing($photos[0]->path);

        // Запись удалена — фото тоже
        $this->asUser($master)->delete("/records/cross1/{$record->id}");
        $this->assertSame(0, RecordPhoto::count());
        Storage::disk('local')->assertMissing($photos[1]->path);
    }

    public function test_only_images_and_limit(): void
    {
        $user = $this->worker('master');
        $data = $this->validRecord('cross1', $this->nameOf($user));

        $this->asUser($user)->post('/records/cross1', $data + ['photos' => [UploadedFile::fake()->create('virus.php', 5)]])
            ->assertSessionHasErrors('photos.0');
        $many = array_map(fn ($i) => UploadedFile::fake()->image("$i.jpg", 50, 50), range(1, RecordPhoto::MAX_PER_RECORD + 1));
        $this->asUser($user)->post('/records/cross1', $data + ['photos' => $many])->assertSessionHasErrors('photos');
    }

    public function test_photo_is_hidden_from_users_without_section(): void
    {
        $photo = RecordPhoto::create(['sheet' => 'cross1', 'record_id' => 1, 'path' => 'records/x.webp', 'thumb_path' => 'records/x_t.webp']);
        Storage::disk('local')->put('records/x.webp', 'img');

        $outsider = User::factory()->create(['role' => 'executor']);   // без участков
        $this->asUser($outsider)->get($photo->url())->assertForbidden();
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

    public function test_chat_text_not_copyable_and_printing_blocked(): void
    {
        $user = User::factory()->admin()->create();
        $html = $this->actingAs($user)->get('/profile')->assertOk()->getContent();

        $this->assertStringContainsString('ui-chat__messages ui-nocopy', $html, 'переписку нельзя выделить');
        $this->assertStringContainsString('@copy.prevent', $html);
        $this->assertStringContainsString('Печать страниц системы запрещена', $html);
        $this->assertStringContainsString("ui-print-blocked", file_get_contents(resource_path('css/app.css')));
    }
}
