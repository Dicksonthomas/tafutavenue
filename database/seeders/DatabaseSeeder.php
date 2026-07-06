<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@ubs.ac.tz'],
            [
                'name' => 'System Administrator',
                'password' => bcrypt('password123'),
                'role' => 'admin',
                'phone' => '0700000000',
                'is_active' => true,
            ]
        );
    }
}
