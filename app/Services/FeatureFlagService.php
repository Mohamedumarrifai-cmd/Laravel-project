<?php

namespace App\Services;

use App\Models\Tenant;

class FeatureFlagService
{
    public function __construct(
        protected TenantContext $tenantContext
    ) {}

    /**
     * Standard feature catalog mapping by business type
     */
    protected array $businessTypeDefaults = [
        'supermarket' => [
            'batch_tracking'           => true,
            'barcode_scanning'         => true,
            'scale_weight_barcode'     => true,
            'loyalty_program'          => true,
            'store_credit_ledger'      => true,
            'fast_numeric_keypad'      => true,
            'table_management'         => false,
            'kitchen_order_ticket'     => false,
            'kitchen_display_system'   => false,
            'prescription_validation'  => false,
            'food_modifiers'           => false,
        ],
        'restaurant' => [
            'batch_tracking'           => false,
            'barcode_scanning'         => false,
            'scale_weight_barcode'     => false,
            'loyalty_program'          => true,
            'store_credit_ledger'      => false,
            'fast_numeric_keypad'      => false,
            'table_management'         => true,
            'kitchen_order_ticket'     => true,
            'kitchen_display_system'   => true,
            'prescription_validation'  => false,
            'food_modifiers'           => true,
        ],
        'pharmacy' => [
            'batch_tracking'           => true,
            'fifo_stock_deduction'     => true,
            'expiry_date_enforcement'  => true,
            'near_expiry_discounts'    => true,
            'barcode_scanning'         => true,
            'scale_weight_barcode'     => false,
            'loyalty_program'          => true,
            'store_credit_ledger'      => true,
            'fast_numeric_keypad'      => true,
            'table_management'         => false,
            'kitchen_order_ticket'     => false,
            'kitchen_display_system'   => false,
            'prescription_validation'  => true,
            'food_modifiers'           => false,
        ],
        'retail' => [
            'batch_tracking'           => false,
            'barcode_scanning'         => true,
            'scale_weight_barcode'     => false,
            'loyalty_program'          => true,
            'store_credit_ledger'      => true,
            'fast_numeric_keypad'      => true,
            'table_management'         => false,
            'kitchen_order_ticket'     => false,
            'kitchen_display_system'   => false,
            'prescription_validation'  => false,
            'food_modifiers'           => false,
        ],
    ];

    /**
     * Check if a feature is enabled for the active tenant
     */
    public function isEnabled(string $featureKey, ?Tenant $tenant = null): bool
    {
        $tenant = $tenant ?? $this->tenantContext->getTenant();
        if (!$tenant) {
            return false;
        }

        // 1. Explicit tenant override takes highest precedence
        $overrides = $tenant->feature_overrides ?? [];
        if (is_array($overrides) && array_key_exists($featureKey, $overrides)) {
            return (bool) $overrides[$featureKey];
        }

        // 2. Fall back to business type defaults
        $businessTypeCode = $tenant->businessType?->code ?? 'retail';
        $defaults = $this->businessTypeDefaults[$businessTypeCode] ?? $this->businessTypeDefaults['retail'];

        return $defaults[$featureKey] ?? false;
    }

    /**
     * Get all active feature flags for frontend hydration
     */
    public function getActiveFeatures(?Tenant $tenant = null): array
    {
        $tenant = $tenant ?? $this->tenantContext->getTenant();
        if (!$tenant) {
            return [];
        }

        $businessTypeCode = $tenant->businessType?->code ?? 'retail';
        $defaults = $this->businessTypeDefaults[$businessTypeCode] ?? $this->businessTypeDefaults['retail'];
        $overrides = is_array($tenant->feature_overrides) ? $tenant->feature_overrides : [];

        return array_merge($defaults, $overrides);
    }
}

