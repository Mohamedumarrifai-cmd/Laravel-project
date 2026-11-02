<?php

namespace App\Services\TaxEngine;

class SriLankaTaxCalculator
{
    /**
     * Compute Sri Lanka compliant VAT (18%) and SSCL (2.5%) breakdown.
     *
     * @param float $taxableBase Net amount after item-level discounts
     * @param bool $vatApplicable Whether product is subject to VAT
     * @param bool $ssclApplicable Whether product is subject to Social Security Contribution Levy
     * @param float $vatRate Standard VAT percentage (default 18.0)
     * @param float $ssclRate Standard SSCL percentage (default 2.5)
     * @param bool $compoundTax If true, VAT is calculated on (Base + SSCL), standard Inland Revenue method
     */
    public static function calculate(
        float $taxableBase,
        bool $vatApplicable = true,
        bool $ssclApplicable = true,
        float $vatRate = 18.00,
        float $ssclRate = 2.50,
        bool $compoundTax = true
    ): array {
        if ($taxableBase <= 0) {
            return [
                'taxable_base' => 0.0,
                'sscl_rate'    => $ssclRate,
                'sscl_amount'  => 0.0,
                'vat_rate'     => $vatRate,
                'vat_amount'   => 0.0,
                'total_tax'    => 0.0,
                'gross_total'  => 0.0,
            ];
        }

        // 1. Calculate SSCL (2.5% on turnover base)
        $ssclAmount = $ssclApplicable ? round($taxableBase * ($ssclRate / 100), 4) : 0.0000;

        // 2. Calculate VAT (18%)
        $vatBase = $taxableBase;
        if ($compoundTax && $ssclApplicable) {
            $vatBase += $ssclAmount; // Sri Lanka gazetted rule: VAT is applied to base plus SSCL
        }

        $vatAmount = $vatApplicable ? round($vatBase * ($vatRate / 100), 4) : 0.0000;

        $totalTax = round($ssclAmount + $vatAmount, 4);
        $grossTotal = round($taxableBase + $totalTax, 4);

        return [
            'taxable_base' => round($taxableBase, 4),
            'sscl_rate'    => $ssclApplicable ? $ssclRate : 0.00,
            'sscl_amount'  => $ssclAmount,
            'vat_rate'     => $vatApplicable ? $vatRate : 0.00,
            'vat_amount'   => $vatAmount,
            'total_tax'    => $totalTax,
            'gross_total'  => $grossTotal,
        ];
    }
}

