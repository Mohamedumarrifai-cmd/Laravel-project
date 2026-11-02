<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('phone', 50);
            $table->string('email', 100)->nullable();
            $table->string('nic_or_passport', 50)->nullable(); // Identity verification for credit accounts
            $table->string('loyalty_card_number', 50)->nullable();
            $table->unsignedInteger('loyalty_points')->default(0);
            $table->string('loyalty_tier', 20)->default('bronze'); // bronze, silver, gold, platinum
            $table->decimal('credit_limit', 15, 2)->default(0.00);
            $table->decimal('current_credit_balance', 15, 2)->default(0.00); // Debt owed to store
            $table->decimal('total_spent', 15, 2)->default(0.00);
            $table->text('address')->nullable();
            $table->boolean('allow_store_credit')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'phone']);
            $table->unique(['tenant_id', 'loyalty_card_number']);
            $table->index(['tenant_id', 'current_credit_balance']);
            $table->index(['tenant_id', 'loyalty_tier']);
        });

        // Double-entry customer debt ledger
        Schema::create('customer_ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('transaction_type', 30); // sale_credit, payment_installment, credit_note, debit_adjustment
            $table->unsignedBigInteger('reference_id')->nullable(); // sale_id or receipt_id
            $table->string('reference_number', 60)->nullable();
            $table->decimal('debit', 15, 2)->default(0.00); // Increases customer debt
            $table->decimal('credit', 15, 2)->default(0.00); // Decreases customer debt (repayment)
            $table->decimal('balance_after', 15, 2); // Running balance
            $table->string('payment_method', 30)->nullable(); // cash, bank_transfer, card, cheque
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'customer_id', 'created_at'], 'customer_ledger_timeline_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_ledgers');
        Schema::dropIfExists('customers');
    }
};
