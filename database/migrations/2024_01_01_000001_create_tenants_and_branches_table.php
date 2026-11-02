<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique(); // supermarket, restaurant, pharmacy, retail
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->json('default_features'); // e.g. ["batch_tracking", "kot", "tables", "expiry_alerts", "weigh_scale"]
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_type_id')->constrained('business_types')->restrictOnDelete();
            $table->string('name', 150);
            $table->string('slug', 100)->unique();
            $table->string('subdomain', 100)->nullable()->unique();
            $table->string('custom_domain', 150)->nullable()->unique();
            $table->string('currency', 10)->default('LKR');
            $table->string('currency_symbol', 10)->default('Rs.');
            $table->string('timezone', 50)->default('Asia/Colombo');
            $table->string('tax_identification_number', 50)->nullable(); // VAT / TIN number
            $table->decimal('default_vat_rate', 5, 2)->default(18.00); // 18% VAT
            $table->decimal('default_sscl_rate', 5, 2)->default(2.50); // 2.5% SSCL
            $table->decimal('manager_pin_discount_threshold', 5, 2)->default(10.00); // % requiring manager PIN
            $table->json('feature_overrides')->nullable(); // custom tenant toggles
            $table->json('settings')->nullable(); // receipt layout, logo, print settings
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'created_at']);
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('code', 30);
            $table->string('address', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email', 100)->nullable();
            $table->boolean('is_main_branch')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable(); // localized branch tax/receipt headers
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
        Schema::dropIfExists('tenants');
        Schema::dropIfExists('business_types');
    }
};

