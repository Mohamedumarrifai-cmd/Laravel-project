<?php

namespace App\Services;

use App\Exceptions\CreditLimitExceededException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\ManagerApprovalRequiredException;
use App\Exceptions\PaymentMismatchException;
use App\Exceptions\ShiftNotActiveException;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\BranchProductStock;
use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\Kot;
use App\Models\KotItem;
use App\Models\Product;
use App\Models\RestaurantTable;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Shift;
use App\Models\User;
use App\Services\TaxEngine\SriLankaTaxCalculator;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SalesCheckoutService
{
    public function __construct(
        protected TenantContext $tenantContext,
        protected FeatureFlagService $featureFlags
    ) {}

    /**
     * Execute POS sales checkout transaction.
     *
     * @param array $payload Cart, customer, payments, discount, and order meta
     * @param User $cashier Authenticated cashier initiating the sale
     * @return Sale Fully persisted sale with relations
     * @throws \Throwable
     */
    public function processCheckout(array $payload, User $cashier): Sale
    {
        $tenant = $this->tenantContext->getTenant();
        $branch = $this->tenantContext->getBranch();

        if (!$tenant || !$branch) {
            throw new \RuntimeException('Active tenant and branch context are mandatory for checkout.');
        }

        // 1. Validate Active Cashier Shift
        $activeShift = Shift::where('tenant_id', $tenant->id)
            ->where('branch_id', $branch->id)
            ->where('user_id', $cashier->id)
            ->where('status', 'open')
            ->first();

        if (!$activeShift) {
            throw new ShiftNotActiveException("No open shift found for cashier '{$cashier->name}'. Open a cash drawer shift first.");
        }

        // 2. Offline idempotency check
        if (!empty($payload['offline_client_uuid'])) {
            $existingSale = Sale::where('tenant_id', $tenant->id)
                ->where('offline_client_uuid', $payload['offline_client_uuid'])
                ->with(['items', 'payments', 'customer'])
                ->first();

            if ($existingSale) {
                return $existingSale; // Already processed idempotently
            }
        }

        return DB::transaction(function () use ($payload, $cashier, $tenant, $branch, $activeShift) {
            // 3. Resolve Customer (if attached)
            $customer = null;
            if (!empty($payload['customer_id'])) {
                $customer = Customer::where('tenant_id', $tenant->id)
                    ->lockForUpdate()
                    ->find($payload['customer_id']);
            }

            // 4. Validate Manager Approval for Discounts Exceeding Threshold
            $managerApprovedById = null;
            $cartDiscountRate = (float) ($payload['discount_rate'] ?? 0.00);
            $cartDiscountType = $payload['discount_type'] ?? 'none';
            $cartDiscountAmountInput = (float) ($payload['discount_amount'] ?? 0.00);

            $threshold = (float) ($tenant->manager_pin_discount_threshold ?? 10.00);
            $requiresManagerApproval = false;

            if ($cartDiscountType === 'percentage' && $cartDiscountRate > $threshold) {
                $requiresManagerApproval = true;
            }

            if ($requiresManagerApproval) {
                $managerApprovedById = $this->verifyManagerPin($payload['manager_pin'] ?? null, $tenant->id);
            }

            // 5. Process Cart Items, Stock Locks & Tax Computations
            $preparedSaleItems = [];
            $computedSubtotal = 0.0000;
            $computedTotalCost = 0.0000;
            $computedVatTotal = 0.0000;
            $computedSsclTotal = 0.0000;

            foreach ($payload['items'] as $itemData) {
                $productId = $itemData['product_id'];
                $requestedQty = (float) $itemData['quantity'];
                $itemDiscount = (float) ($itemData['discount_amount'] ?? 0.0000);
                $modifiers = $itemData['modifiers'] ?? [];
                $itemNotes = $itemData['notes'] ?? null;

                // Pessimistic lock on Product
                $product = Product::where('tenant_id', $tenant->id)
                    ->lockForUpdate()
                    ->findOrFail($productId);

                if (!$product->is_active) {
                    throw new \InvalidArgumentException("Product '{$product->name}' is marked inactive.");
                }

                // Check modifiers price
                $modifiersPrice = 0.00;
                if (!empty($modifiers) && is_array($modifiers)) {
                    foreach ($modifiers as $mod) {
                        $modifiersPrice += (float) ($mod['price'] ?? 0);
                    }
                }

                $sellingPrice = (float) ($itemData['unit_price'] ?? $product->selling_price) + $modifiersPrice;

                // Inventory Deduction Handling (FIFO Batch vs Standard Stock)
                if ($product->is_batch_tracked && $this->featureFlags->isEnabled('batch_tracking', $tenant)) {
                    $allocations = $this->allocateFifoBatches(
                        $tenant->id,
                        $branch->id,
                        $product,
                        $requestedQty,
                        $itemData['batch_id'] ?? null
                    );

                    foreach ($allocations as $alloc) {
                        $allocQty = $alloc['quantity'];
                        $allocBatch = $alloc['batch'];
                        $lineTaxable = round(($sellingPrice * $allocQty) - ($itemDiscount * ($allocQty / $requestedQty)), 4);

                        $taxes = SriLankaTaxCalculator::calculate(
                            $lineTaxable,
                            $product->vat_applicable,
                            $product->sscl_applicable,
                            (float) $tenant->default_vat_rate,
                            (float) $tenant->default_sscl_rate
                        );

                        $lineGross = $taxes['gross_total'];
                        $lineCost = round((float) $allocBatch->cost_price * $allocQty, 4);

                        $computedSubtotal += ($sellingPrice * $allocQty);
                        $computedTotalCost += $lineCost;
                        $computedVatTotal += $taxes['vat_amount'];
                        $computedSsclTotal += $taxes['sscl_amount'];

                        $preparedSaleItems[] = [
                            'product_id'      => $product->id,
                            'batch_id'        => $allocBatch->id,
                            'quantity'        => $allocQty,
                            'unit_cost'       => $allocBatch->cost_price,
                            'unit_price'      => $sellingPrice,
                            'discount_amount' => round($itemDiscount * ($allocQty / $requestedQty), 4),
                            'vat_rate'        => $taxes['vat_rate'],
                            'vat_amount'      => $taxes['vat_amount'],
                            'sscl_rate'       => $taxes['sscl_rate'],
                            'sscl_amount'     => $taxes['sscl_amount'],
                            'total_price'     => $lineGross,
                            'modifiers'       => !empty($modifiers) ? $modifiers : null,
                            'notes'           => $itemNotes,
                        ];
                    }
                } else {
                    // Standard Non-Batch Stock Deduction
                    $branchStock = BranchProductStock::where('tenant_id', $tenant->id)
                        ->where('branch_id', $branch->id)
                        ->where('product_id', $product->id)
                        ->lockForUpdate()
                        ->first();

                    $availableStock = $branchStock ? (float) $branchStock->quantity_on_hand : 0.0000;
                    if ($product->item_type !== 'service' && $availableStock < $requestedQty) {
                        throw new InsufficientStockException("Insufficient stock for '{$product->name}'. Available: {$availableStock}, Requested: {$requestedQty}");
                    }

                    if ($branchStock && $product->item_type !== 'service') {
                        $branchStock->quantity_on_hand -= $requestedQty;
                        $branchStock->save();
                    }

                    $lineTaxable = round(($sellingPrice * $requestedQty) - $itemDiscount, 4);

                    $taxes = SriLankaTaxCalculator::calculate(
                        $lineTaxable,
                        $product->vat_applicable,
                        $product->sscl_applicable,
                        (float) $tenant->default_vat_rate,
                        (float) $tenant->default_sscl_rate
                    );

                    $lineGross = $taxes['gross_total'];
                    $lineCost = round((float) $product->cost_price * $requestedQty, 4);

                    $computedSubtotal += ($sellingPrice * $requestedQty);
                    $computedTotalCost += $lineCost;
                    $computedVatTotal += $taxes['vat_amount'];
                    $computedSsclTotal += $taxes['sscl_amount'];

                    $preparedSaleItems[] = [
                        'product_id'      => $product->id,
                        'batch_id'        => null,
                        'quantity'        => $requestedQty,
                        'unit_cost'       => $product->cost_price,
                        'unit_price'      => $sellingPrice,
                        'discount_amount' => $itemDiscount,
                        'vat_rate'        => $taxes['vat_rate'],
                        'vat_amount'      => $taxes['vat_amount'],
                        'sscl_rate'       => $taxes['sscl_rate'],
                        'sscl_amount'     => $taxes['sscl_amount'],
                        'total_price'     => $lineGross,
                        'modifiers'       => !empty($modifiers) ? $modifiers : null,
                        'notes'           => $itemNotes,
                    ];
                }
            }

            // 6. Overall Cart Discount Calculation
            $finalCartDiscount = 0.0000;
            if ($cartDiscountType === 'percentage') {
                $finalCartDiscount = round($computedSubtotal * ($cartDiscountRate / 100), 4);
            } elseif ($cartDiscountType === 'fixed') {
                $finalCartDiscount = min($cartDiscountAmountInput, $computedSubtotal);
            }

            // Loyalty Points Redemption (e.g. 1 point = 1 LKR)
            $pointsToRedeem = (int) ($payload['redeem_loyalty_points'] ?? 0);
            $pointsDiscountAmount = 0.0000;
            if ($pointsToRedeem > 0 && $customer) {
                if ($customer->loyalty_points < $pointsToRedeem) {
                    throw new \InvalidArgumentException("Customer only has {$customer->loyalty_points} loyalty points. Cannot redeem {$pointsToRedeem}.");
                }
                $pointsDiscountAmount = (float) $pointsToRedeem; // 1 pt = 1 LKR
                $finalCartDiscount += $pointsDiscountAmount;
            }

            $computedTaxTotal = $computedVatTotal + $computedSsclTotal;
            $serviceCharge = 0.0000;
            if (($payload['order_type'] ?? 'retail') === 'dine_in') {
                $serviceCharge = round(($computedSubtotal - $finalCartDiscount) * 0.10, 4); // 10% restaurant service charge
            }

            $computedGrandTotal = max(0, round(($computedSubtotal - $finalCartDiscount) + $computedTaxTotal + $serviceCharge, 2));
            $grossProfit = round($computedGrandTotal - $computedTaxTotal - $computedTotalCost, 4);

            // 7. Split-Tender Payment Allocation & Validation
            $payments = $payload['payments'] ?? [];
            if (empty($payments)) {
                throw new PaymentMismatchException("At least one payment method is required.");
            }

            $totalPaid = 0.00;
            $cashPaid = 0.00;
            $cardPaid = 0.00;
            $creditPaid = 0.00;
            $qrPaid = 0.00;

            foreach ($payments as $payment) {
                $amount = (float) $payment['amount'];
                $method = $payment['payment_method'];
                $totalPaid += $amount;

                switch ($method) {
                    case 'cash':
                        $cashPaid += $amount;
                        break;
                    case 'card':
                        $cardPaid += $amount;
                        break;
                    case 'qr_wallet':
                        $qrPaid += $amount;
                        break;
                    case 'store_credit':
                        $creditPaid += $amount;
                        if (!$customer) {
                            throw new CreditLimitExceededException("A registered customer account is required to issue Store Credit.");
                        }
                        if (!$customer->allow_store_credit) {
                            throw new CreditLimitExceededException("Store credit facility is not enabled for customer '{$customer->name}'.");
                        }
                        $projectedBalance = (float) $customer->current_credit_balance + $amount;
                        if ($projectedBalance > (float) $customer->credit_limit) {
                            throw new CreditLimitExceededException("Credit limit of Rs. {$customer->credit_limit} exceeded. Current balance: Rs. {$customer->current_credit_balance}, Attempted: Rs. {$amount}.");
                        }
                        break;
                }
            }

            if ($totalPaid < $computedGrandTotal) {
                throw new PaymentMismatchException("Underpaid amount. Grand Total: {$computedGrandTotal}, Total Paid: {$totalPaid}");
            }

            $changeAmount = round(max(0, $totalPaid - $computedGrandTotal), 2);
            $netCashContribution = max(0, $cashPaid - $changeAmount);

            // 8. Generate Consecutive Invoice Number
            $invoiceNumber = $this->generateInvoiceNumber($tenant->id, $branch->code);

            // 9. Persist Master Sale Record
            $sale = Sale::create([
                'uuid'                        => (string) Str::uuid(),
                'tenant_id'                   => $tenant->id,
                'branch_id'                   => $branch->id,
                'shift_id'                    => $activeShift->id,
                'user_id'                     => $cashier->id,
                'customer_id'                 => $customer?->id,
                'restaurant_table_id'         => $payload['restaurant_table_id'] ?? null,
                'invoice_number'              => $invoiceNumber,
                'order_type'                  => $payload['order_type'] ?? 'retail',
                'subtotal'                    => $computedSubtotal,
                'discount_type'               => $cartDiscountType,
                'discount_rate'               => $cartDiscountRate,
                'discount_amount'             => $finalCartDiscount,
                'manager_discount_approved_by'=> $managerApprovedById,
                'tax_amount'                  => $computedTaxTotal,
                'vat_amount'                  => $computedVatTotal,
                'sscl_amount'                 => $computedSsclTotal,
                'service_charge_amount'       => $serviceCharge,
                'grand_total'                 => $computedGrandTotal,
                'total_cost'                  => $computedTotalCost,
                'gross_profit'                => $grossProfit,
                'paid_amount'                 => $totalPaid,
                'change_amount'               => $changeAmount,
                'points_earned'               => 0, // calculated below
                'points_redeemed'             => $pointsToRedeem,
                'points_discount_amount'      => $pointsDiscountAmount,
                'payment_status'              => 'paid',
                'status'                      => 'completed',
                'offline_client_uuid'         => $payload['offline_client_uuid'] ?? null,
                'is_synced_from_offline'      => !empty($payload['offline_client_uuid']),
                'notes'                       => $payload['notes'] ?? null,
            ]);

            // 10. Persist Sale Items
            foreach ($preparedSaleItems as $line) {
                $sale->items()->create(array_merge($line, ['tenant_id' => $tenant->id]));
            }

            // 11. Persist Sale Payments
            foreach ($payments as $payment) {
                $sale->payments()->create([
                    'tenant_id'             => $tenant->id,
                    'payment_method'        => $payment['payment_method'],
                    'amount'                => $payment['amount'],
                    'transaction_reference' => $payment['reference_number'] ?? null,
                    'meta_data'             => $payment['meta_data'] ?? null,
                ]);
            }

            // 12. Handle Store Credit Ledger Entries
            if ($creditPaid > 0 && $customer) {
                $newCustomerBalance = round((float) $customer->current_credit_balance + $creditPaid, 2);
                $customer->current_credit_balance = $newCustomerBalance;

                CustomerLedger::create([
                    'tenant_id'        => $tenant->id,
                    'customer_id'      => $customer->id,
                    'branch_id'        => $branch->id,
                    'transaction_type' => 'sale_credit',
                    'reference_id'     => $sale->id,
                    'reference_number' => $sale->invoice_number,
                    'debit'            => $creditPaid,
                    'credit'           => 0.00,
                    'balance_after'    => $newCustomerBalance,
                    'payment_method'   => 'store_credit',
                    'notes'            => "Store credit purchase on invoice #{$sale->invoice_number}",
                    'created_by'       => $cashier->id,
                ]);
            }

            // 13. Customer Loyalty Points Accrual & Tier Upgrades
            if ($customer) {
                // Earn 1 point per 100 LKR spent on net grand total
                $earnedPoints = (int) floor($computedGrandTotal / 100);
                $customer->loyalty_points = ($customer->loyalty_points - $pointsToRedeem) + $earnedPoints;
                $customer->total_spent = round((float) $customer->total_spent + $computedGrandTotal, 2);

                // Update Tier
                if ($customer->total_spent >= 250000) {
                    $customer->loyalty_tier = 'platinum';
                } elseif ($customer->total_spent >= 100000) {
                    $customer->loyalty_tier = 'gold';
                } elseif ($customer->total_spent >= 30000) {
                    $customer->loyalty_tier = 'silver';
                }

                $customer->save();

                $sale->points_earned = $earnedPoints;
                $sale->save();
            }

            // 14. Update Shift Running Balances
            $activeShift->cash_sales   = round((float) $activeShift->cash_sales + $netCashContribution, 2);
            $activeShift->card_sales   = round((float) $activeShift->card_sales + $cardPaid, 2);
            $activeShift->credit_sales = round((float) $activeShift->credit_sales + $creditPaid, 2);
            $activeShift->qr_sales     = round((float) $activeShift->qr_sales + $qrPaid, 2);
            $activeShift->expected_cash = round(
                (float) $activeShift->opening_float + (float) $activeShift->cash_sales + (float) $activeShift->total_cash_in - (float) $activeShift->total_cash_out,
                2
            );
            $activeShift->save();

            // 15. Restaurant / Cafe KOT Generation & Table Management
            if (!empty($payload['restaurant_table_id']) || ($payload['order_type'] ?? '') === 'dine_in') {
                $this->processRestaurantOrder($sale, $payload, $tenant->id, $branch->id);
            }

            // 16. Audit Log Entry
            AuditLog::create([
                'tenant_id'      => $tenant->id,
                'user_id'        => $cashier->id,
                'event'          => 'sale_completed',
                'auditable_type' => Sale::class,
                'auditable_id'   => $sale->id,
                'new_values'     => [
                    'invoice_number' => $sale->invoice_number,
                    'grand_total'    => $sale->grand_total,
                    'cashier'        => $cashier->name,
                    'payments'       => $payments,
                ],
                'ip_address'     => request()->ip(),
                'user_agent'     => request()->userAgent(),
            ]);

            return $sale->load(['items.product', 'items.batch', 'payments', 'customer', 'kots.items']);
        });
    }

    /**
     * Deducts stock via First-In-First-Out (FIFO) algorithm across batches.
     */
    protected function allocateFifoBatches(
        int $tenantId,
        int $branchId,
        Product $product,
        float $quantityNeeded,
        ?int $preferredBatchId = null
    ): array {
        $allocations = [];
        $remainingNeeded = $quantityNeeded;

        // If specific batch preferred (e.g. pharmacy cashier selecting exact batch)
        if ($preferredBatchId) {
            $batch = Batch::where('tenant_id', $tenantId)
                ->where('branch_id', $branchId)
                ->where('id', $preferredBatchId)
                ->where('status', 'active')
                ->lockForUpdate()
                ->firstOrFail();

            if ((float) $batch->current_stock < $quantityNeeded) {
                throw new InsufficientStockException("Selected batch {$batch->batch_number} has only {$batch->current_stock} available. Requested: {$quantityNeeded}");
            }

            $batch->current_stock = round((float) $batch->current_stock - $quantityNeeded, 4);
            if ($batch->current_stock <= 0) {
                $batch->status = 'depleted';
            }
            $batch->save();

            $this->decrementBranchStock($tenantId, $branchId, $product->id, $quantityNeeded);

            return [[
                'batch'    => $batch,
                'quantity' => $quantityNeeded,
            ]];
        }

        // Automated FIFO allocation ordered by nearest expiry date
        $availableBatches = Batch::where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->where('product_id', $product->id)
            ->where('status', 'active')
            ->where('current_stock', '>', 0)
            ->orderBy('expiry_date', 'asc')
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get();

        $totalAvailable = $availableBatches->sum('current_stock');
        if ($totalAvailable < $quantityNeeded) {
            throw new InsufficientStockException("Insufficient FIFO batch stock for '{$product->name}'. Available: {$totalAvailable}, Needed: {$quantityNeeded}");
        }

        foreach ($availableBatches as $batch) {
            if ($remainingNeeded <= 0) {
                break;
            }

            $currentStock = (float) $batch->current_stock;
            $deductQty = min($currentStock, $remainingNeeded);

            $batch->current_stock = round($currentStock - $deductQty, 4);
            if ($batch->current_stock <= 0) {
                $batch->status = 'depleted';
            }
            $batch->save();

            $allocations[] = [
                'batch'    => $batch,
                'quantity' => $deductQty,
            ];

            $remainingNeeded -= $deductQty;
        }

        $this->decrementBranchStock($tenantId, $branchId, $product->id, $quantityNeeded);

        return $allocations;
    }

    /**
     * Decrements branch aggregate stock
     */
    protected function decrementBranchStock(int $tenantId, int $branchId, int $productId, float $quantity): void
    {
        $branchStock = BranchProductStock::where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->first();

        if ($branchStock) {
            $branchStock->quantity_on_hand = round((float) $branchStock->quantity_on_hand - $quantity, 4);
            $branchStock->save();
        }
    }

    /**
     * Verify manager PIN code for privileged discount overrides.
     */
    protected function verifyManagerPin(?string $pin, int $tenantId): int
    {
        if (empty($pin)) {
            throw new ManagerApprovalRequiredException("Manager PIN approval is required for discounts exceeding the threshold limit.");
        }

        // Find users with Manager or Owner privileges for this tenant
        $managers = User::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereNotNull('pin_code_hash')
            ->get();

        foreach ($managers as $mgr) {
            if (Hash::check($pin, $mgr->pin_code_hash)) {
                return $mgr->id;
            }
        }

        throw new ManagerApprovalRequiredException("Invalid Manager PIN provided. Authorization denied.");
    }

    /**
     * Handles Restaurant table reservation status and Kitchen Order Ticket routing.
     */
    protected function processRestaurantOrder(Sale $sale, array $payload, int $tenantId, int $branchId): void
    {
        $tableId = $payload['restaurant_table_id'] ?? null;
        if ($tableId) {
            $table = RestaurantTable::where('tenant_id', $tenantId)
                ->where('id', $tableId)
                ->lockForUpdate()
                ->first();

            if ($table) {
                $table->status = 'occupied';
                $table->save();
            }
        }

        // Daily token sequence calculation
        $todayStart = Carbon::today();
        $dailyToken = Kot::where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->where('created_at', '>=', $todayStart)
            ->max('daily_token_number') + 1;

        $kotNumber = 'KOT-' . date('Ymd') . '-' . str_pad((string) $dailyToken, 4, '0', STR_PAD_LEFT);

        $kot = Kot::create([
            'tenant_id'           => $tenantId,
            'branch_id'           => $branchId,
            'sale_id'             => $sale->id,
            'restaurant_table_id' => $tableId,
            'kot_number'          => $kotNumber,
            'daily_token_number'  => $dailyToken,
            'kitchen_station'     => $payload['kitchen_station'] ?? 'main',
            'status'              => 'pending',
            'notes'               => $payload['notes'] ?? null,
        ]);

        foreach ($payload['items'] as $item) {
            $product = Product::find($item['product_id']);
            KotItem::create([
                'kot_id'               => $kot->id,
                'product_id'           => $item['product_id'],
                'item_name'            => $product ? $product->name : 'Unknown Item',
                'quantity'             => $item['quantity'],
                'modifiers'            => $item['modifiers'] ?? null,
                'special_instructions' => $item['notes'] ?? null,
                'status'               => 'pending',
            ]);
        }
    }

    /**
     * Atomically formats consecutive invoice numbers (e.g. INV-COL-2026-000042)
     */
    protected function generateInvoiceNumber(int $tenantId, string $branchCode): string
    {
        $year = date('Y');
        $prefix = strtoupper($branchCode) . "-{$year}-";

        $lastSale = Sale::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('invoice_number', 'LIKE', "INV-{$prefix}%")
            ->orderBy('id', 'desc')
            ->first();

        $nextSeq = 1;
        if ($lastSale) {
            $parts = explode('-', $lastSale->invoice_number);
            $lastNum = (int) end($parts);
            $nextSeq = $lastNum + 1;
        }

        return "INV-{$prefix}" . str_pad((string) $nextSeq, 6, '0', STR_PAD_LEFT);
    }
}

