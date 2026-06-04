<?php

namespace Database\Seeders;

use App\Modules\Branch\Models\Branch;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    public function run(): void
    {
        Branch::firstOrCreate(
            ['code' => 'MAIN'],
            [
                'name' => 'Asia Dental Lab Pusat',
                'address' => 'Makassar',
                'phone' => null,
                'is_active' => true,
            ]
        );
    }
}
