<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\BranchProductStock;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class InventoryAdjustmentController extends Controller
{
    public function __construct(
        protected TenantContext $tenantContext
    ) {}

    /**
     * Submit Stock Write-off, Supplier Return or Damaged Item Adjustment
     */
    public function store(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $branch = $this->tenantContext->getBranch();

        $validated = $request->validate([
            'adjustment_type'        => 'required|string|in:damaged_write_off,supplier_return,expired_stock,audit_discrepancy',
            'supplier_id'            => 'nullable|integer',
            'reason'                 => 'required|string|max:500',
            'items'                  => 'required|array|min:1',
            'items.*.product_id'     => 'required|integer',
            'items.*.batch_id'       => 'nullable|integer',
            'items.*.quantity'       => 'required|numeric|gt:0',
            'items.*.item_condition' => 'nullable|string|max:100',
        ]);

        return DB::transaction(function () use ($validated, $tenant, $branch, $request) {
            $refNumber = 'ADJ-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
            $totalLossCost = 0.0000;

            $adjustment = InventoryAdjustment::create([
                'tenant_id'        => $tenant->id,
                'branch_id'        => $branch->id,
                'supplier_id'      => $validated['supplier_id'] ?? null,
                'reference_number' => $refNumber,
                'adjustment_type'  => $validated['adjustment_type'],
                'total_loss_cost'  => 0.0000,
                'reason'           => $validated['reason'],
                'authorized_by'    => $request->user()->id,
            ]);

            foreach ($validated['items'] as $item) {
                $product = Product::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($item['product_id']);
                $quantity = (float) $item['quantity'];
                $unitCost = (float) $product->cost_price;

                // If batch tracked, deduct from specific batch
                if (!empty($item['batch_id'])) {
                    $batch = Batch::where('tenant_id', $tenant->id)
                        ->where('branch_id', $branch->id)
                        ->where('id', $item['batch_id'])
                        ->lockForUpdate()
                        ->firstOrFail();

                    $unitCost = (float) $batch->cost_price;
                    $batch->current_stock = max(0, (float) $batch->current_stock - $quantity);
                    if ($batch->current_stock == 0) {
                        $batch->status = 'depleted';
                    }
                    $batch->save();
                }

                // Decrement branch aggregate stock
                $branchStock = BranchProductStock::where('tenant_id', $tenant->id)
                    ->where('branch_id', $branch->id)
                    ->where('product_id', $product->id)
                    ->lockForUpdate()
                    ->first();

                if ($branchStock) {
                    $branchStock->quantity_on_hand = max(0, (float) $branchStock->quantity_on_hand - $quantity);
                    $branchStock->save();
                }

                $lineTotalCost = round($unitCost * $quantity, 4);
                $totalLossCost += $lineTotalCost;

                $adjustment->items()->create([
                    'product_id'     => $product->id,
                    'batch_id'       => $item['batch_id'] ?? null,
                    'quantity'       => $quantity,
                    'unit_cost'      => $unitCost,
                    'total_cost'     => $lineTotalCost,
                    'item_condition' => $item['item_condition'] ?? null,
                ]);
            }

            $adjustment->update(['total_loss_cost' => $totalLossCost]);

            AuditLog::create([
                'tenant_id'      => $tenant->id,
                'user_id'        => $request->user()->id,
                'event'          => 'stock_adjusted',
                'auditable_type' => InventoryAdjustment::class,
                'auditable_id'   => $adjustment->id,
                'new_values'     => [
                    'reference_number' => $adjustment->reference_number,
                    'type'             => $adjustment->adjustment_type,
                    'total_loss_cost'  => $totalLossCost,
                    'reason'           => $adjustment->reason,
                ],
                'ip_address'     => $request->ip(),
                'user_agent'     => $request->userAgent(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Inventory adjustment completed and stock levels updated.',
                'data'    => $adjustment->load('items.product'),
            ], Response::HTTP_CREATED);
        });
    }
}

