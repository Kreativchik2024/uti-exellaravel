<?php

namespace App\Services;

use App\Models\Section;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Текущий участок пользователя.
 *
 *  - Доступные участки: админ — все активные; остальные — назначенные ему.
 *  - Выбранный участок хранится в сессии (section_id); если он недоступен —
 *    берётся первый доступный.
 *  - Вкладки учёта, статистика и новости фильтруются по выбранному участку.
 *
 * Регистрируется как scoped-синглтон: вычисляется один раз за запрос.
 */
class SectionContext
{
    private const SESSION_KEY = 'section_id';

    private ?Collection $available = null;
    private ?Section $current = null;
    private bool $resolved = false;
    private ?int $forUser = null;

    /** Текущий пользователь; при его смене кеш сервиса сбрасывается. */
    private function user(): ?User
    {
        $user = auth()->user();
        if ($user?->id !== $this->forUser) {
            $this->forUser = $user?->id;
            $this->available = null;
            $this->current = null;
            $this->resolved = false;
        }
        return $user;
    }

    /** Участки, доступные текущему пользователю (упорядочены). */
    public function available(): Collection
    {
        if ($this->available !== null) return $this->available;

        $user = $this->user();
        if (!$user) return $this->available = collect();

        $query = $user->isAdmin()
            ? Section::query()
            : $user->sections()->getQuery();

        return $this->available = $query->active()->ordered()->get();
    }

    public function canAccess(Section $section): bool
    {
        return $this->available()->contains('id', $section->id);
    }

    /** Выбранный участок (null — пользователю не назначено ни одного). */
    public function current(): ?Section
    {
        $this->user();   // сброс кеша при смене пользователя
        if ($this->resolved) return $this->current;
        $this->resolved = true;

        $available = $this->available();
        $id = (int) session(self::SESSION_KEY);

        $this->current = $available->firstWhere('id', $id) ?? $available->first();

        if ($this->current && $this->current->id !== $id) {
            session([self::SESSION_KEY => $this->current->id]);
        }

        return $this->current;
    }

    public function switchTo(Section $section): void
    {
        abort_unless($this->canAccess($section), 403, 'Нет доступа к участку');

        session([self::SESSION_KEY => $section->id]);
        $this->current = $section;
        $this->resolved = true;
    }

    /**
     * Участок без сессии — для API станций: справочник кодов неисправностей и т. п. по участку
     * вкладки. Доступ проверяет вызывающий (StationController). Вызывать после Auth::setUser.
     */
    public function pin(Section $section): void
    {
        $this->user();   // сброс кеша при смене пользователя — до закрепления
        $this->current = $section;
        $this->resolved = true;
    }

    /** Вкладки учёта выбранного участка: [slug => cfg]. */
    public function sheets(): array
    {
        return $this->current()?->sheets() ?? [];
    }

    /**
     * Конфиг вкладки с проверкой доступа. Если вкладка с другого доступного
     * участка (например, открыли ссылку) — переключаемся на этот участок.
     */
    public function sheet(string $slug): array
    {
        $cfg = config("sheets.map.$slug");
        abort_unless($cfg, 404, "Вкладка {$slug} не найдена");

        $sectionSlug = $cfg['section'] ?? Section::DEFAULT_SLUG;

        if ($this->current()?->slug !== $sectionSlug) {
            $section = $this->available()->firstWhere('slug', $sectionSlug);
            abort_unless($section, 403, 'Эта вкладка относится к участку, к которому у вас нет доступа');
            $this->switchTo($section);
        }

        return $cfg;
    }

    /** Есть ли у выбранного участка раздел (сейчас — только 'firmware'). */
    public function has(string $feature): bool
    {
        return match ($feature) {
            'firmware' => (bool) $this->current()?->has_firmware,
            default    => false,
        };
    }

    /** Часть ключа кеша — чтобы данные участков не смешивались. */
    public function cacheKey(): string
    {
        return 's' . ($this->current()?->id ?? 0);
    }
}
