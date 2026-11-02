<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SalesCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class POSCheckoutController extends Controller
{
    public function __construct(
        protected SalesCheckoutService $checkoutService
    ) {}

    /**
     * Submit POS Cart for Transactional Checkout
     */
    public function checkout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id'            => 'nullable|integer',
            'restaurant_table_id'    => 'nullable|integer',
            'order_type'             => 'required|string|in:retail,dine_in,takeaway,delivery',
            'discount_type'          => 'nullable|string|in:none,percentage,fixed',
            'discount_rate'          => 'nullable|numeric|min:0|max:100',
            'discount_amount'        => 'nullable|numeric|min:0',
            'manager_pin'            => 'nullable|string',
            'redeem_loyalty_points'  => 'nullable|integer|min:0',
            'offline_client_uuid'    => 'nullable|string',
            'notes'                  => 'nullable|string|max:500',
            'items'                  => 'required|array|min:1',
            'items.*.product_id'     => 'required|integer',
            'items.*.batch_id'       => 'nullable|integer',
            'items.*.quantity'       => 'required|numeric|gt:0',
            'items.*.unit_price'     => 'nullable|numeric|min:0',
            'items.*.discount_amount'=> 'nullable|numeric|min:0',
            'items.*.modifiers'      => 'nullable|array',
            'items.*.notes'          => 'nullable|string|max:255',
            'payments'               => 'required|array|min:1',
            'payments.*.payment_method' => 'required|string|in:cash,card,qr_wallet,store_credit,bank_transfer',
            'payments.*.amount'         => 'required|numeric|gt:0',
            'payments.*.reference_number'=> 'nullable|string|max:100',
        ]);

        try {
            $sale = $this->checkoutService->processCheckout($validated, $request->user());

            return response()->json([
                'success' => true,
                'message' => 'Sale processed and finalized successfully.',
                'data'    => [
                    'id'             => $sale->id,
                    'uuid'           => $sale->uuid,
                    'invoice_number' => $sale->invoice_number,
                    'subtotal'       => (float) $sale->subtotal,
                    'tax_amount'     => (float) $sale->tax_amount,
                    'vat_amount'     => (float) $sale->vat_amount,
                    'sscl_amount'    => (float) $sale->sscl_amount,
                    'grand_total'    => (float) $sale->grand_total,
                    'paid_amount'    => (float) $sale->paid_amount,
                    'change_amount'  => (float) $sale->change_amount,
                    'points_earned'  => $sale->points_earned,
                    'items_count'    => $sale->items->count(),
                    'created_at'     => $sale->created_at->toIso8601String(),
                ],
            ], Response::HTTP_CREATED);
        } catch (\App\Exceptions\InsufficientStockException $e) {
            return response()->json(['success' => false, 'error' => 'INSUFFICIENT_STOCK', 'message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\App\Exceptions\ManagerApprovalRequiredException $e) {
            return response()->json(['success' => false, 'error' => 'MANAGER_APPROVAL_REQUIRED', 'message' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (\App\Exceptions\CreditLimitExceededException $e) {
            return response()->json(['success' => false, 'error' => 'CREDIT_LIMIT_EXCEEDED', 'message' => $e->getMessage()], Response::HTTP_PAYMENT_REQUIRED);
        } catch (\App\Exceptions\ShiftNotActiveException $e) {
            return response()->json(['success' => false, 'error' => 'SHIFT_NOT_ACTIVE', 'message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => 'CHECKOUT_FAILED', 'message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}

