<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database. Local development only: see DemoSeeder.
     */
    public function run(): void
    {
        $this->call(DemoSeeder::class);
    }
}
