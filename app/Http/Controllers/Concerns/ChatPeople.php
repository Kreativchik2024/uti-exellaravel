<?php

namespace App\Http\Controllers\Concerns;

use App\Models\ActiveWorkerSession;
use App\Models\User;
use App\Rules\CleanText;
use App\Rules\NoEmoji;

/** Чат: имена, инициалы и кто сейчас в сети (ChatController, ChatGroupController). */
trait ChatPeople
{
    private const ONLINE_SECONDS = 180;

    /** Имя для чата: короткое ФИО сотрудника, иначе имя учётки. */
    private function displayNames(iterable $userIds): array
    {
        $ids = collect($userIds)->filter()->unique()->values();
        if ($ids->isEmpty()) return [];

        return User::query()
            ->whereIn('users.id', $ids)
            ->leftJoin('employees', 'employees.user_id', '=', 'users.id')
            ->get(['users.id', 'users.name', 'employees.short_name'])
            ->mapWithKeys(fn ($u) => [$u->id => CleanText::strip(NoEmoji::strip((string) ($u->short_name ?: $u->name))) ?: 'Без имени'])
            ->all();
    }

    private function onlineUserIds(): array
    {
        return ActiveWorkerSession::query()
            ->where('last_heartbeat_at', '>=', now()->subSeconds(self::ONLINE_SECONDS))
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/[\s.]+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: ['?'];
        return mb_strtoupper(mb_substr($parts[0], 0, 1) . (isset($parts[1]) ? mb_substr($parts[1], 0, 1) : ''));
    }
}
