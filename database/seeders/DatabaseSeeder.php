<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 05.6 §4.2 launch zones and carriage rates — first, so the demo data
        // can add its collection rate to the mainland zone.
        $this->call(DeliveryZoneSeeder::class);
        $this->call(DemoDataSeeder::class);
        // 02 §25.10: placeholder terms of trade, local machines only.
        if (app()->environment('local')) {
            $this->call(PlaceholderTermsSeeder::class);
            // 05.11 §2.4: placeholder legal and help pages, local only.
            $this->call(PlaceholderCmsPagesSeeder::class);
        }
    }
}
