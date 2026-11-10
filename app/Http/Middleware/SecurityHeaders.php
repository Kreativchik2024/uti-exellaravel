<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Заголовки безопасности для всех ответов:
 *  - сайт нельзя встроить в чужую страницу (защита от «кликджекинга»);
 *  - браузер не угадывает тип файла (загруженный файл не выполнится как HTML/JS);
 *  - адрес страниц не уходит на сторонние сайты.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $h = $response->headers;
        $h->set('X-Frame-Options', 'SAMEORIGIN', false);
        $h->set('X-Content-Type-Options', 'nosniff', false);
        $h->set('Referrer-Policy', 'same-origin', false);
        $h->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()', false);

        return $response;
    }
}
