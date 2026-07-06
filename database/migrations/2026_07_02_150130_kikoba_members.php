<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kikoba_members', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            // Optional link if this person is ALSO a loan customer elsewhere in the system
            $table->foreignId('customer_id')
                ->nullable()
                ->constrained('customers')
                ->nullOnDelete();

            $table->string('member_no')->nullable(); // company-generated registration number

            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');

            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->date('date_of_birth')->nullable();

            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();

            $table->string('id_type')->nullable(); // National ID, Passport, Voter ID, etc.
            $table->string('id_number')->nullable();

            $table->string('next_of_kin_name')->nullable();
            $table->string('next_of_kin_phone')->nullable();
            $table->string('next_of_kin_relationship')->nullable();

            $table->string('photo_path')->nullable();

            $table->enum('status', ['active', 'inactive', 'blacklisted'])->default('active');

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['member_no']);
            $table->unique(['id_number']);
            $table->index(['company_id']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kikoba_members');
    }
};
