<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Shift;
use App\Models\ShiftCashMovement;
use App\Services\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ShiftController extends Controller
{
    public function __construct(
        protected TenantContext $tenantContext
    ) {}

    /**
     * Open New Cash Drawer Shift
     */
    public function open(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $branch = $this->tenantContext->getBranch();
        $user = $request->user();

        $activeShift = Shift::where('tenant_id', $tenant->id)
            ->where('branch_id', $branch->id)
            ->where('user_id', $user->id)
            ->where('status', 'open')
            ->first();

        if ($activeShift) {
            return response()->json([
                'success' => false,
                'message' => "You already have an open shift (#{$activeShift->shift_number}).",
                'data'    => $activeShift,
            ], Response::HTTP_CONFLICT);
        }

        $validated = $request->validate([
            'opening_float' => 'required|numeric|min:0',
            'notes'         => 'nullable|string|max:255',
        ]);

        $shiftNumber = 'SH-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));

        $shift = Shift::create([
            'tenant_id'     => $tenant->id,
            'branch_id'     => $branch->id,
            'user_id'       => $user->id,
            'shift_number'  => $shiftNumber,
            'opened_at'     => Carbon::now(),
            'opening_float' => $validated['opening_float'],
            'expected_cash' => $validated['opening_float'],
            'status'        => 'open',
            'notes'         => $validated['notes'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Shift opened successfully.',
            'data'    => $shift,
        ], Response::HTTP_CREATED);
    }

    /**
     * Log Safe Drop or Petty Cash Expense During Shift
     */
    public function recordCashMovement(Request $request, int $shiftId): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $shift = Shift::where('tenant_id', $tenant->id)->findOrFail($shiftId);

        if ($shift->status !== 'open') {
            return response()->json(['success' => false, 'message' => 'Shift is already closed.'], Response::HTTP_BAD_REQUEST);
        }

        $validated = $request->validate([
            'movement_type' => 'required|string|in:cash_drop,petty_cash_expense,float_topup',
            'amount'        => 'required|numeric|gt:0',
            'reason'        => 'required|string|max:255',
        ]);

        $movement = ShiftCashMovement::create([
            'shift_id'      => $shift->id,
            'tenant_id'     => $tenant->id,
            'movement_type' => $validated['movement_type'],
            'amount'        => $validated['amount'],
            'reason'        => $validated['reason'],
            'authorized_by' => $request->user()->id,
        ]);

        if (in_array($validated['movement_type'], ['cash_drop', 'petty_cash_expense'])) {
            $shift->total_cash_out += $validated['amount'];
        } else {
            $shift->total_cash_in += $validated['amount'];
        }

        $shift->expected_cash = round(
            $shift->opening_float + $shift->cash_sales + $shift->total_cash_in - $shift->total_cash_out,
            2
        );
        $shift->save();

        return response()->json([
            'success' => true,
            'message' => 'Cash movement recorded successfully.',
            'data'    => [
                'movement'      => $movement,
                'expected_cash' => (float) $shift->expected_cash,
            ],
        ]);
    }

    /**
     * Automated Shift Closing & Cash Reconciliation
     */
    public function close(Request $request, int $shiftId): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $shift = Shift::where('tenant_id', $tenant->id)->findOrFail($shiftId);

        if ($shift->status !== 'open') {
            return response()->json(['success' => false, 'message' => 'Shift has already been finalized.'], Response::HTTP_CONFLICT);
        }

        $validated = $request->validate([
            'counted_cash' => 'required|numeric|min:0', // Cashier physical count
            'notes'        => 'nullable|string|max:500',
        ]);

        $countedCash = (float) $validated['counted_cash'];
        $expectedCash = (float) $shift->expected_cash;
        $discrepancy = round($countedCash - $expectedCash, 2); // > 0 Overage, < 0 Shortage

        $shift->update([
            'counted_cash' => $countedCash,
            'discrepancy'  => $discrepancy,
            'status'       => 'closed',
            'closed_at'    => Carbon::now(),
            'closed_by'    => $request->user()->id,
            'notes'        => $validated['notes'] ?? $shift->notes,
        ]);

        AuditLog::create([
            'tenant_id'      => $tenant->id,
            'user_id'        => $request->user()->id,
            'event'          => 'shift_closed',
            'auditable_type' => Shift::class,
            'auditable_id'   => $shift->id,
            'new_values'     => [
                'shift_number'  => $shift->shift_number,
                'expected_cash' => $expectedCash,
                'counted_cash'  => $countedCash,
                'discrepancy'   => $discrepancy,
                'cash_sales'    => (float) $shift->cash_sales,
                'card_sales'    => (float) $shift->card_sales,
            ],
            'ip_address'     => $request->ip(),
            'user_agent'     => $request->userAgent(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Shift closed and drawer reconciled successfully.',
            'data'    => [
                'shift_number'     => $shift->shift_number,
                'opening_float'    => (float) $shift->opening_float,
                'cash_sales'       => (float) $shift->cash_sales,
                'card_sales'       => (float) $shift->card_sales,
                'credit_sales'     => (float) $shift->credit_sales,
                'qr_sales'         => (float) $shift->qr_sales,
                'cash_drops'       => (float) $shift->total_cash_out,
                'expected_cash'    => $expectedCash,
                'counted_cash'     => $countedCash,
                'discrepancy'      => $discrepancy,
                'discrepancy_type' => $discrepancy == 0 ? 'balanced' : ($discrepancy > 0 ? 'overage' : 'shortage'),
                'closed_at'        => $shift->closed_at->toIso8601String(),
            ],
        ]);
    }
}

