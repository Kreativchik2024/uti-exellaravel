<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Партия морского боя. player2_id = null — игра с компьютером. */
class BattleshipGame extends Model
{
    protected $guarded = [];

    protected $casts = [
        'ships1' => 'array', 'ships2' => 'array', 'shots1' => 'array', 'shots2' => 'array',
        'ready1' => 'boolean', 'ready2' => 'boolean', 'winner_bot' => 'boolean',
        'last_move_at' => 'datetime', 'finished_at' => 'datetime',
    ];

    public function player1(): BelongsTo { return $this->belongsTo(User::class, 'player1_id'); }
    public function player2(): BelongsTo { return $this->belongsTo(User::class, 'player2_id'); }

    public function vsBot(): bool { return $this->player2_id === null; }

    /** Номер игрока (1 или 2) или null, если пользователь не участник. */
    public function seat(int $userId): ?int
    {
        return match ($userId) {
            (int) $this->player1_id => 1,
            (int) $this->player2_id => 2,
            default => null,
        };
    }
}
