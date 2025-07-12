<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Plan;

class PlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Plan::create([
            'name' => 'Basic',
            'price' => 35000.00,
            'customer_limit' => 60,
            'branch_limit' => 1,
            'zone_limit' => 3,
            'user_limit' => 4,
            'description' => 'Basic plan for small microfinance institutions.',
        ]);

        Plan::create([
            'name' => 'Medium',
            'price' => 50000.00,
            'customer_limit' => 150,
            'branch_limit' => 4,
            'zone_limit' => 12,
            'user_limit' => 15,
            'description' => 'Medium plan for medium size microfinance institutions.',
        ]);

        Plan::create([
            'name' => 'Pro',
            'price' => 90000.00,
            'customer_limit' => 350,
            'branch_limit' => 10,
            'zone_limit' => 30,
            'user_limit' => 65,
            'description' => 'Pro plan for growing institutions.',
        ]);

        Plan::create([
            'name' => 'Enterprise',
            'price' => 150000.00,
            'customer_limit' => null, // Unlimited
            'branch_limit' => null,
            'zone_limit' => null,
            'user_limit' => null,
            'description' => 'Enterprise plan with unlimited access.',
        ]);
    }
}
