<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kikoba_group_members', function (Blueprint $table) {
            $table->id();

            $table->foreignId('kikoba_group_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('kikoba_member_id')
                ->constrained('kikoba_members')
                ->cascadeOnDelete();

            $table->enum('role', ['chairperson', 'secretary', 'treasurer', 'member'])
                ->default('member');

            $table->date('joined_date');
            $table->date('exit_date')->nullable();

            $table->enum('status', ['active', 'inactive', 'exited'])->default('active');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kikoba_group_members');
    }
};
