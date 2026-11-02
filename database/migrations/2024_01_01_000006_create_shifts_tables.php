<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // Cashier
            $table->string('shift_number', 50);
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->decimal('opening_float', 15, 2)->default(0.00); // Cash in drawer at start
            $table->decimal('cash_sales', 15, 2)->default(0.00);
            $table->decimal('card_sales', 15, 2)->default(0.00);
            $table->decimal('credit_sales', 15, 2)->default(0.00);
            $table->decimal('qr_sales', 15, 2)->default(0.00);
            $table->decimal('total_cash_in', 15, 2)->default(0.00);
            $table->decimal('total_cash_out', 15, 2)->default(0.00); // Petty expenses + safe cash drops
            $table->decimal('expected_cash', 15, 2)->default(0.00); // opening + cash_sales + cash_in - cash_out
            $table->decimal('counted_cash', 15, 2)->nullable(); // Physical count entered by cashier
            $table->decimal('discrepancy', 15, 2)->nullable(); // counted - expected (overage / shortage)
            $table->string('status', 20)->default('open'); // open, closed
            $table->text('notes')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'shift_number']);
            $table->index(['tenant_id', 'branch_id', 'user_id', 'status']);
        });

        Schema::create('shift_cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained('shifts')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('movement_type', 30); // cash_drop, petty_cash_expense, float_topup
            $table->decimal('amount', 15, 2);
            $table->string('reason', 255);
            $table->foreignId('authorized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['shift_id', 'movement_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_cash_movements');
        Schema::dropIfExists('shifts');
    }
};
