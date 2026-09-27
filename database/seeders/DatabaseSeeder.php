<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DemoDataSeeder::class);
        // 05.6 §4.2 launch zones and carriage rates.
        $this->call(DeliveryZoneSeeder::class);
        // 02 §25.10: placeholder terms of trade, local machines only.
        if (app()->environment('local')) {
            $this->call(PlaceholderTermsSeeder::class);
        }
    }
}
