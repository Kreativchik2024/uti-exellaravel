<?php

namespace App\Http\Middleware;

use App\Services\SectionContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Пускает в раздел, только если он включён у текущего участка.
 * Пример: ->middleware('section.feature:firmware')
 *
 * Если раздел есть на другом доступном участке — переключаемся на него
 * (например, пришли по ссылке на прошивку, находясь на другом участке).
 */
class EnsureSectionFeature
{
    public function __construct(private SectionContext $sections) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (!$this->sections->has($feature)) {
            $other = $this->sections->available()->first(fn ($s) => $feature === 'firmware' && $s->has_firmware);
            abort_unless($other, 404, 'Этот раздел недоступен на вашем участке');
            $this->sections->switchTo($other);
        }

        return $next($request);
    }
}
