<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kitchen Order Tickets (KOT) for Restaurants & Cafes
        Schema::create('kots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->foreignId('restaurant_table_id')->nullable()->constrained('restaurant_tables')->nullOnDelete();
            $table->string('kot_number', 50);
            $table->unsignedInteger('daily_token_number');
            $table->string('kitchen_station', 50)->default('main'); // main, bar, grill, dessert
            $table->string('status', 30)->default('pending'); // pending, cooking, ready, served, cancelled
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_cooking_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('served_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'branch_id', 'kot_number']);
            $table->index(['tenant_id', 'branch_id', 'status', 'created_at']);
        });

        Schema::create('kot_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kot_id')->constrained('kots')->cascadeOnDelete();
            $table->foreignId('sale_item_id')->nullable()->constrained('sale_items')->nullOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('item_name', 150);
            $table->decimal('quantity', 14, 4);
            $table->json('modifiers')->nullable(); // [{"name": "No Onion"}, {"name": "Extra Spicy"}]
            $table->string('special_instructions', 255)->nullable();
            $table->string('status', 30)->default('pending'); // pending, cooking, ready
            $table->timestamps();

            $table->index(['kot_id', 'status']);
        });

        // Audit Trail System
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 50); // sale_completed, price_override, discount_approved, shift_closed, stock_adjusted
            $table->string('auditable_type', 150)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'event', 'created_at']);
            $table->index(['auditable_type', 'auditable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('kot_items');
        Schema::dropIfExists('kots');
    }
};

