<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\Branch;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IdentifyTenant
{
    public function __construct(
        protected TenantContext $tenantContext
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = null;

        // 1. Resolve from Authenticated User
        if ($request->user() && $request->user()->tenant_id) {
            $tenant = Tenant::find($request->user()->tenant_id);
        }

        // 2. Resolve from Header (e.g. mobile POS, API clients, testing)
        if (!$tenant && $request->hasHeader('X-Tenant-ID')) {
            $tenantIdentifier = $request->header('X-Tenant-ID');
            $tenant = is_numeric($tenantIdentifier)
                ? Tenant::find($tenantIdentifier)
                : Tenant::where('uuid', $tenantIdentifier)->first();
        }

        // 3. Resolve from Subdomain (e.g. branch1.superpos.com)
        if (!$tenant) {
            $host = $request->getHost();
            $parts = explode('.', $host);
            if (count($parts) > 2) {
                $subdomain = $parts[0];
                $tenant = Tenant::where('subdomain', $subdomain)->first();
            }
        }

        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant identification required. Missing or invalid tenant context.',
            ], Response::HTTP_NOT_FOUND);
        }

        if (!$tenant->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'This store account is currently suspended or inactive.',
            ], Response::HTTP_FORBIDDEN);
        }

        // Resolve Active Branch
        $branch = null;
        if ($request->hasHeader('X-Branch-ID')) {
            $branchId = $request->header('X-Branch-ID');
            $branch = Branch::where('tenant_id', $tenant->id)
                ->where('id', $branchId)
                ->where('is_active', true)
                ->first();
        }

        if (!$branch && $request->user() && $request->user()->branch_id) {
            $branch = Branch::where('tenant_id', $tenant->id)
                ->where('id', $request->user()->branch_id)
                ->where('is_active', true)
                ->first();
        }

        if (!$branch) {
            $branch = Branch::where('tenant_id', $tenant->id)
                ->where('is_main_branch', true)
                ->where('is_active', true)
                ->first()
                ?? Branch::where('tenant_id', $tenant->id)->where('is_active', true)->first();
        }

        // Set Context
        $this->tenantContext->setTenant($tenant);
        $this->tenantContext->setBranch($branch);

        // Configure Localized Environment
        if (!empty($tenant->timezone)) {
            config(['app.timezone' => $tenant->timezone]);
            date_default_timezone_set($tenant->timezone);
        }

        return $next($request);
    }
}
