<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Аварийный сброс входа с кодом (потерян телефон и резервные коды у единственного админа):
 *   sudo -u www-data php artisan user:2fa-reset admin@uti.local
 * После сброса пользователь при входе заново настроит приложение на телефоне.
 */
class ResetTwoFactor extends Command
{
    protected $signature = 'user:2fa-reset {email : Почта пользователя}';

    protected $description = 'Сбросить вход с кодом из приложения у пользователя (потерян телефон)';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error('Пользователь не найден');
            return self::FAILURE;
        }

        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        AuditLog::write('users', 'two_factor', "Сброшен вход с кодом (консоль) у {$user->email}", ['subject_type' => 'user', 'subject_id' => $user->id, 'section_id' => null]);
        $this->info("Сброшено. При следующем входе {$user->email} заново настроит приложение на телефоне.");

        return self::SUCCESS;
    }
}
