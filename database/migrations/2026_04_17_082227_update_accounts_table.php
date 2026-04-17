<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateAccountsTable extends Migration
{
    public function up()
    {
        Schema::table('accounts', function (Blueprint $table) {
            // Add new columns
            $table->string('account_number', 50)->unique()->nullable()->after('account_name');
            $table->enum('account_type', ['general', 'branch', 'escrow', 'floating'])->default('general')->after('account_number');
            $table->unsignedBigInteger('branch_id')->nullable()->after('account_balance');
            $table->unsignedBigInteger('zone_id')->nullable()->after('branch_id');
            $table->renameColumn('company', 'company_id');
            $table->unsignedBigInteger('parent_account_id')->nullable()->after('company_id');
            $table->string('currency', 3)->default('TZS')->after('parent_account_id');
            $table->decimal('minimum_balance', 15, 2)->default(0)->after('currency');
            $table->decimal('maximum_balance', 15, 2)->nullable()->after('minimum_balance');
            $table->text('description')->nullable()->after('maximum_balance');
            $table->unsignedBigInteger('created_by')->nullable()->after('description');
            $table->unsignedBigInteger('approved_by')->nullable()->after('created_by');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            
            // Add foreign keys
            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('set null');
            $table->foreign('zone_id')->references('id')->on('zones')->onDelete('set null');
            $table->foreign('parent_account_id')->references('id')->on('accounts')->onDelete('set null');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('set null');
        });
        
        // Update account_histories table
        Schema::table('account_histories', function (Blueprint $table) {
            $table->renameColumn('company', 'company_id');
            $table->renameColumn('customer', 'customer_id');
            $table->string('transaction_type', 20)->default('credit')->after('transaction_amount');
            $table->string('reference_number', 50)->nullable()->after('is_reverse');
            $table->text('description')->nullable()->after('reference_number');
            
            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('set null');
        });
    }
    
    public function down()
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropForeign(['zone_id']);
            $table->dropForeign(['parent_account_id']);
            $table->dropForeign(['created_by']);
            $table->dropForeign(['approved_by']);
            
            $table->dropColumn([
                'account_number', 'account_type', 'branch_id', 'zone_id',
                'parent_account_id', 'currency', 'minimum_balance',
                'maximum_balance', 'description', 'created_by',
                'approved_by', 'approved_at'
            ]);
            $table->renameColumn('company_id', 'company');
        });
        
        Schema::table('account_histories', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropColumn(['transaction_type', 'reference_number', 'description']);
            $table->renameColumn('company_id', 'company');
            $table->renameColumn('customer_id', 'customer');
        });
    }
}