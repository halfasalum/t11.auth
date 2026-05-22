<?php
// database/migrations/2026_05_23_000001_add_plan_discounts_table.php

use App\Models\Plan;
use App\Models\PlanDiscount;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Create plan discounts table for custom duration discounts per plan
        Schema::create('plan_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->onDelete('cascade');
            $table->integer('duration_months'); // 1, 3, 6, 12, 24
            $table->decimal('discount_percentage', 5, 2)->default(0); // 0-100
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->unique(['plan_id', 'duration_months']);
        });
        
        // Add default discounts for existing plans
        $this->seedDefaultDiscounts();
    }
    
    private function seedDefaultDiscounts()
    {
        $plans = Plan::all();
        $defaultDiscounts = [
            1 => 0,
            3 => 5,
            6 => 10,
            12 => 15,
            24 => 20,
        ];
        
        foreach ($plans as $plan) {
            foreach ($defaultDiscounts as $months => $discount) {
                PlanDiscount::updateOrCreate(
                    ['plan_id' => $plan->id, 'duration_months' => $months],
                    ['discount_percentage' => $discount]
                );
            }
        }
    }

    public function down()
    {
        Schema::dropIfExists('plan_discounts');
    }
};