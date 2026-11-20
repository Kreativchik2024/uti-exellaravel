<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SiteHelpers;
use Tests\TestCase;

/** Панель чата не приходит с каждой страницей — только кнопка; разметка панели — по /chat/panel при первом открытии. */
class ChatLazyPanelTest extends TestCase
{
    use RefreshDatabase, SiteHelpers;

    public function test_page_has_only_button_and_panel_is_served_separately(): void
    {
        $user = $this->worker();

        $page = $this->asUser($user)->get('/records/pgp')->assertOk()->getContent();
        $this->assertStringContainsString('ui-chat__fab', $page);
        $this->assertStringContainsString('data-chat-panel-host', $page);
        $this->assertStringContainsString(route('chat.panel'), str_replace('\/', '/', $page));
        $this->assertStringNotContainsString('ui-chat__panel', $page);

        $panel = $this->asUser($user)->get('/chat/panel')->assertOk()->getContent();
        $this->assertStringContainsString('ui-chat__panel', $panel);
        $this->assertStringNotContainsString('<html', $panel);

        auth()->logout();
        $this->get('/chat/panel')->assertRedirect();
    }
}
