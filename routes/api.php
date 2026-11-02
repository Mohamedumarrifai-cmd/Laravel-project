<?php

use App\Http\Controllers\Api\V1\InventoryAdjustmentController;
use App\Http\Controllers\Api\V1\POSCheckoutController;
use App\Http\Controllers\Api\V1\ShiftController;
use App\Http\Middleware\IdentifyTenant;
use App\Models\Kot;
use App\Models\Product;
use App\Models\User;
use App\Services\FeatureFlagService;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Enterprise Multi-Tenant POS API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->middleware(['auth:sanctum', IdentifyTenant::class])->group(function () {

    // Feature Flags & Environment Config
    Route::get('/pos/features', function (FeatureFlagService $flagService) {
        return response()->json([
            'success'  => true,
            'features' => $flagService->getActiveFeatures(),
        ]);
    });

    // Product Lookup by Barcode / SKU
    Route::get('/pos/products/lookup', function (Request $request, TenantContext $context) {
        $query = $request->query('code');
        $product = Product::where('tenant_id', $context->getTenantId())
            ->where(function ($q) use ($query) {
                $q->where('barcode', $query)
                  ->orWhere('sku', $query)
                  ->orWhere('name', 'LIKE', "%{$query}%");
            })
            ->where('is_active', true)
            ->with(['batches' => function ($b) {
                $b->where('status', 'active')
                  ->where('current_stock', '>', 0)
                  ->orderBy('expiry_date', 'asc');
            }])
            ->first();

        if (!$product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        return response()->json($product);
    });

    // POS Checkout Transaction
    Route::post('/pos/checkout', [POSCheckoutController::class, 'checkout']);

    // Shift Management & Reconciliation
    Route::post('/shifts/open', [ShiftController::class, 'open']);
    Route::post('/shifts/{id}/cash-movement', [ShiftController::class, 'recordCashMovement']);
    Route::post('/shifts/{id}/close', [ShiftController::class, 'close']);

    // Inventory Adjustments & Write-offs
    Route::post('/inventory/adjustments', [InventoryAdjustmentController::class, 'store']);

    // Restaurant Kitchen Order Ticket (KOT) Transitions
    Route::patch('/kots/{id}/status', function (Request $request, int $id, TenantContext $context) {
        $validated = $request->validate([
            'status' => 'required|string|in:pending,cooking,ready,served,cancelled',
        ]);

        $kot = Kot::where('tenant_id', $context->getTenantId())->findOrFail($id);
        $kot->status = $validated['status'];

        if ($validated['status'] === 'cooking' && !$kot->started_cooking_at) {
            $kot->started_cooking_at = now();
        } elseif ($validated['status'] === 'ready' && !$kot->ready_at) {
            $kot->ready_at = now();
        } elseif ($validated['status'] === 'served' && !$kot->served_at) {
            $kot->served_at = now();
        }

        $kot->save();

        return response()->json(['success' => true, 'data' => $kot]);
    });

    // Manager PIN Override Verification
    Route::post('/auth/verify-pin', function (Request $request, TenantContext $context) {
        $validated = $request->validate([
            'pin' => 'required|string|min:4|max:8',
        ]);

        $managers = User::where('tenant_id', $context->getTenantId())
            ->where('is_active', true)
            ->whereNotNull('pin_code_hash')
            ->get();

        foreach ($managers as $manager) {
            if (Hash::check($validated['pin'], $manager->pin_code_hash)) {
                return response()->json([
                    'success'      => true,
                    'manager_id'   => $manager->id,
                    'manager_name' => $manager->name,
                ]);
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'Invalid manager PIN code.',
        ], 403);
    });
});

