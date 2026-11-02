<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name', 150);
            $table->string('slug', 150);
            $table->string('code', 50)->nullable();
            $table->text('description')->nullable();
            $table->string('image_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'parent_id', 'is_active']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name', 200);
            $table->string('sku', 100);
            $table->string('barcode', 100)->nullable();
            $table->string('item_type', 30)->default('standard'); // standard, batch_tracked, composite, service
            $table->string('unit', 30)->default('pcs'); // pcs, kg, g, l, ml, strip, pack
            $table->decimal('cost_price', 15, 4)->default(0.0000); // Weighted Average Cost
            $table->decimal('selling_price', 15, 4)->default(0.0000);
            $table->decimal('min_selling_price', 15, 4)->nullable(); // Price floor for cashier discounts
            $table->boolean('is_batch_tracked')->default(false);
            $table->boolean('is_taxable')->default(true);
            $table->boolean('vat_applicable')->default(true); // 18% VAT
            $table->boolean('sscl_applicable')->default(true); // 2.5% SSCL
            $table->decimal('custom_tax_rate', 5, 2)->nullable();
            $table->decimal('min_stock_threshold', 12, 4)->default(5.0000); // Re-order point
            $table->decimal('reorder_quantity', 12, 4)->default(20.0000);
            $table->boolean('is_scale_weight_item')->default(false); // Supermarket weight embedded barcode item
            $table->boolean('has_modifiers')->default(false); // Restaurant modifiers/addons
            $table->json('modifiers')->nullable(); // [{name: "Extra Cheese", price: 150}, ...]
            $table->boolean('is_prescription_required')->default(false); // Pharmacy regulations
            $table->string('brand', 100)->nullable();
            $table->string('generic_name', 200)->nullable(); // Pharmacy generic drug formula
            $table->string('rack_location', 50)->nullable(); // Warehouse / shelf identifier
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'sku']);
            $table->index(['tenant_id', 'barcode']);
            $table->index(['tenant_id', 'category_id', 'is_active']);
            $table->index(['tenant_id', 'is_batch_tracked']);
        });

        // Current aggregate stock per branch
        Schema::create('branch_product_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->decimal('quantity_on_hand', 14, 4)->default(0.0000);
            $table->decimal('quantity_reserved', 14, 4)->default(0.0000); // e.g. open restaurant tables / pending orders
            $table->decimal('alert_threshold', 12, 4)->default(5.0000);
            $table->timestamps();

            $table->unique(['tenant_id', 'branch_id', 'product_id'], 'branch_product_unique');
            $table->index(['tenant_id', 'branch_id', 'quantity_on_hand']);
        });

        // Batch Tracking for FIFO & Expiry Management (Pharmacies & Groceries)
        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('batch_number', 100);
            $table->date('manufacture_date')->nullable();
            $table->date('expiry_date');
            $table->decimal('cost_price', 15, 4);
            $table->decimal('selling_price', 15, 4);
            $table->decimal('quantity_received', 14, 4);
            $table->decimal('current_stock', 14, 4);
            $table->decimal('near_expiry_discount_percent', 5, 2)->default(0.00);
            $table->string('status', 30)->default('active'); // active, expired, depleted, quarantined
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'branch_id', 'product_id', 'batch_number'], 'batch_unique');
            // Crucial composite index for FIFO stock allocation:
            $table->index(['tenant_id', 'branch_id', 'product_id', 'current_stock', 'expiry_date'], 'fifo_batch_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batches');
        Schema::dropIfExists('branch_product_stocks');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
    }
};
