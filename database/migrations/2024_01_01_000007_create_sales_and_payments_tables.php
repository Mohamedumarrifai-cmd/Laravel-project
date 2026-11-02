<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Restaurant Tables management
        Schema::create('restaurant_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('table_number', 50);
            $table->string('zone', 50)->default('Main Dining'); // Main Dining, Balcony, VIP, Patio
            $table->unsignedInteger('seating_capacity')->default(4);
            $table->string('status', 30)->default('available'); // available, occupied, reserved, cleaning
            $table->timestamps();

            $table->unique(['tenant_id', 'branch_id', 'table_number']);
            $table->index(['tenant_id', 'branch_id', 'status']);
        });

        // Sales Master Table
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // Cashier
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('restaurant_table_id')->nullable()->constrained('restaurant_tables')->nullOnDelete();
            $table->string('invoice_number', 60);
            $table->string('order_type', 30)->default('retail'); // retail, dine_in, takeaway, delivery
            $table->decimal('subtotal', 15, 4)->default(0.0000);
            $table->string('discount_type', 20)->default('none'); // none, fixed, percentage
            $table->decimal('discount_rate', 5, 2)->default(0.00);
            $table->decimal('discount_amount', 15, 4)->default(0.0000);
            $table->foreignId('manager_discount_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('tax_amount', 15, 4)->default(0.0000);
            $table->decimal('vat_amount', 15, 4)->default(0.0000); // 18% Sri Lanka VAT
            $table->decimal('sscl_amount', 15, 4)->default(0.0000); // 2.5% Social Security Contribution Levy
            $table->decimal('service_charge_amount', 15, 4)->default(0.0000); // Restaurant 10%
            $table->decimal('grand_total', 15, 4)->default(0.0000);
            $table->decimal('total_cost', 15, 4)->default(0.0000); // Sum of COGS for margin analytics
            $table->decimal('gross_profit', 15, 4)->default(0.0000); // grand_total - tax - total_cost
            $table->decimal('paid_amount', 15, 4)->default(0.0000);
            $table->decimal('change_amount', 15, 4)->default(0.0000);
            $table->unsignedInteger('points_earned')->default(0);
            $table->unsignedInteger('points_redeemed')->default(0);
            $table->decimal('points_discount_amount', 15, 4)->default(0.0000);
            $table->string('payment_status', 30)->default('paid'); // paid, partial, due, refunded
            $table->string('status', 30)->default('completed'); // completed, hold, cancelled, refunded
            $table->string('offline_client_uuid', 100)->nullable()->unique(); // Idempotency key for offline sync
            $table->boolean('is_synced_from_offline')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'invoice_number']);
            $table->index(['tenant_id', 'branch_id', 'created_at']);
            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'shift_id']);
            $table->index(['tenant_id', 'status']);
        });

        // Split Tender Payment Allocation
        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->string('payment_method', 30); // cash, card, qr_wallet, store_credit, bank_transfer
            $table->decimal('amount', 15, 4);
            $table->string('transaction_reference', 100)->nullable(); // Card auth code, LankaPay QR ref
            $table->json('meta_data')->nullable(); // card brand, bank name, etc.
            $table->timestamps();

            $table->index(['tenant_id', 'sale_id']);
            $table->index(['tenant_id', 'payment_method']);
        });

        // Sale Line Items
        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('batches')->nullOnDelete();
            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_cost', 15, 4); // COGS recorded at time of sale
            $table->decimal('unit_price', 15, 4);
            $table->decimal('discount_amount', 15, 4)->default(0.0000);
            $table->decimal('vat_rate', 5, 2)->default(0.00);
            $table->decimal('vat_amount', 15, 4)->default(0.0000);
            $table->decimal('sscl_rate', 5, 2)->default(0.00);
            $table->decimal('sscl_amount', 15, 4)->default(0.0000);
            $table->decimal('total_price', 15, 4);
            $table->json('modifiers')->nullable(); // [{"name": "Extra Spicy", "price": 0}]
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'sale_id']);
            $table->index(['tenant_id', 'product_id']);
            $table->index(['tenant_id', 'batch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sale_payments');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('restaurant_tables');
    }
};
