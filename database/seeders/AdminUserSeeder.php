<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@uti.local'],
            [
                'name'        => 'Администратор',
                'password'    => Hash::make('ChangeMe123!'),
                'role'        => 'admin',
                'is_active'   => true,
                'approved_at' => now(),
            ]
        );
    }
}