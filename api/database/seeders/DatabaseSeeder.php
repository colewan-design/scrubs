<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Administrator first: it is the only one of these that production
        // wants, so a failure further down still leaves a usable /admin login.
        $this->call([
            AdminUserSeeder::class,
            FoundationSeeder::class,
            CatalogSeeder::class,
        ]);
    }
}
