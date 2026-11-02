<?php

namespace App\Services;

use App\Models\Sale;

class EscPosReceiptService
{
    // ESC/POS Command Constants
    const ESC = "\x1b";
    const GS  = "\x1d";

    const CMD_INIT              = "\x1b\x40";           // ESC @: Initialize printer
    const CMD_ALIGN_LEFT        = "\x1b\x61\x00";       // ESC a 0: Align left
    const CMD_ALIGN_CENTER      = "\x1b\x61\x01";       // ESC a 1: Align center
    const CMD_ALIGN_RIGHT       = "\x1b\x61\x02";       // ESC a 2: Align right
    const CMD_BOLD_ON           = "\x1b\x45\x01";       // ESC E 1: Turn bold on
    const CMD_BOLD_OFF          = "\x1b\x45\x00";       // ESC E 0: Turn bold off
    const CMD_DOUBLE_SIZE_ON    = "\x1d\x21\x11";       // GS ! 17: 2x Width & Height
    const CMD_DOUBLE_SIZE_OFF   = "\x1d\x21\x00";       // Normal font sizing
    const CMD_DRAWER_KICK       = "\x1b\x70\x00\x19\xfa"; // Kick cash drawer pin 2
    const CMD_CUT_PARTIAL       = "\x1d\x56\x41\x03";   // Feed paper and partial cut

    protected int $paperWidthCols = 42; // Standard 80mm roll = 42-48 columns (58mm = 32 cols)

    public function __construct(int $paperWidthCols = 42)
    {
        $this->paperWidthCols = $paperWidthCols;
    }

    /**
     * Generate raw ESC/POS binary buffer for thermal printers.
     *
     * @param Sale $sale Fully loaded sale model
     * @param bool $kickDrawer Whether to pulse open the cash drawer
     * @return string Raw byte buffer ready to pipe to /dev/usb/lp0, network socket, or WebUSB
     */
    public function generateReceiptBuffer(Sale $sale, bool $kickDrawer = true): string
    {
        $tenant = $sale->tenant;
        $branch = $sale->branch;
        $out = "";

        // 1. Initialize
        $out .= self::CMD_INIT;

        // Optional cash drawer kick
        if ($kickDrawer) {
            $out .= self::CMD_DRAWER_KICK;
        }

        // 2. Header (Centered)
        $out .= self::CMD_ALIGN_CENTER;
        $out .= self::CMD_BOLD_ON . self::CMD_DOUBLE_SIZE_ON;
        $out .= ($tenant ? $tenant->name : "POS SYSTEM") . "\n";
        $out .= self::CMD_DOUBLE_SIZE_OFF . self::CMD_BOLD_OFF;

        if ($branch) {
            $out .= "Branch: " . $branch->name . "\n";
            if ($branch->address) $out .= $branch->address . "\n";
            if ($branch->phone)   $out .= "Tel: " . $branch->phone . "\n";
        }

        if ($tenant && $tenant->tax_identification_number) {
            $out .= "VAT Reg / TIN: " . $tenant->tax_identification_number . "\n";
        }

        $out .= $this->horizontalDivider('=') . "\n";

        // 3. Invoice & Cashier Metadata (Left aligned)
        $out .= self::CMD_ALIGN_LEFT;
        $out .= "Invoice : " . $sale->invoice_number . "\n";
        $out .= "Date/Time: " . $sale->created_at->format('Y-m-d H:i:s') . "\n";
        $out .= "Cashier : " . ($sale->user?->name ?? 'Staff') . "\n";
        if ($sale->restaurant_table_id) {
            $out .= "Table   : " . ($sale->restaurantTable?->table_number ?? 'N/A') . " (" . strtoupper($sale->order_type) . ")\n";
        }
        if ($sale->customer) {
            $out .= "Customer: " . $sale->customer->name . " [" . $sale->customer->phone . "]\n";
            $out .= "Loyalty : Tier " . strtoupper($sale->customer->loyalty_tier) . " (Bal: " . $sale->customer->loyalty_points . " pts)\n";
        }

        $out .= $this->horizontalDivider('-') . "\n";

        // 4. Itemized Table
        $out .= $this->formatLine("ITEM (QTY x PRICE)", "TOTAL (LKR)") . "\n";
        $out .= $this->horizontalDivider('-') . "\n";

        foreach ($sale->items as $item) {
            $productName = $item->product ? $item->product->name : "Item #{$item->product_id}";
            $qtyFormatted = (float)$item->quantity . " x " . number_format((float)$item->unit_price, 2);
            $lineTotalFormatted = number_format((float)$item->total_price, 2);

            $out .= self::CMD_BOLD_ON . $productName . self::CMD_BOLD_OFF . "\n";
            $out .= $this->formatLine("  " . $qtyFormatted, $lineTotalFormatted) . "\n";

            if ($item->batch) {
                $out .= "  [Batch: {$item->batch->batch_number} Exp: {$item->batch->expiry_date->format('m/Y')}]\n";
            }
            if (!empty($item->modifiers)) {
                foreach ($item->modifiers as $mod) {
                    $out .= "  + " . ($mod['name'] ?? '') . " (" . number_format($mod['price'] ?? 0, 2) . ")\n";
                }
            }
        }

        $out .= $this->horizontalDivider('-') . "\n";

        // 5. Financials & Sri Lanka Tax Breakdown
        $out .= $this->formatLine("Subtotal:", number_format((float)$sale->subtotal, 2)) . "\n";

        if ((float)$sale->discount_amount > 0) {
            $out .= $this->formatLine("Discount:", "-" . number_format((float)$sale->discount_amount, 2)) . "\n";
        }

        // Sri Lanka Statutory Levies
        if ((float)$sale->sscl_amount > 0) {
            $out .= $this->formatLine("SSCL (2.5%):", number_format((float)$sale->sscl_amount, 2)) . "\n";
        }
        if ((float)$sale->vat_amount > 0) {
            $out .= $this->formatLine("VAT (18%):", number_format((float)$sale->vat_amount, 2)) . "\n";
        }
        if ((float)$sale->service_charge_amount > 0) {
            $out .= $this->formatLine("Service Charge (10%):", number_format((float)$sale->service_charge_amount, 2)) . "\n";
        }

        $out .= $this->horizontalDivider('=') . "\n";

        // 6. Grand Total (Double Height & Bold)
        $out .= self::CMD_BOLD_ON . self::CMD_DOUBLE_SIZE_ON;
        $out .= $this->formatLine("TOTAL:", "LKR " . number_format((float)$sale->grand_total, 2), 24) . "\n";
        $out .= self::CMD_DOUBLE_SIZE_OFF . self::CMD_BOLD_OFF;

        $out .= $this->horizontalDivider('-') . "\n";

        // 7. Payment Tender Breakdown
        foreach ($sale->payments as $payment) {
            $label = "Paid (" . strtoupper($payment->payment_method) . "):";
            $out .= $this->formatLine($label, number_format((float)$payment->amount, 2)) . "\n";
        }

        if ((float)$sale->change_amount > 0) {
            $out .= $this->formatLine("Change Due:", number_format((float)$sale->change_amount, 2)) . "\n";
        }

        $out .= $this->horizontalDivider('=') . "\n";

        // 8. Footer & Thank You Notes
        $out .= self::CMD_ALIGN_CENTER;
        $out .= "Thank you for your business!\n";
        $out .= "Exchange within 7 days with original receipt.\n";
        $out .= "Powered by Modular Multi-Tenant Enterprise POS\n\n";

        // 9. QR Code generation for e-invoice validation
        $qrData = "INV:{$sale->invoice_number}|AMT:{$sale->grand_total}|TIN:" . ($tenant?->tax_identification_number ?? 'NA');
        $out .= $this->generateQrCodeEscPos($qrData);

        // 10. Feed and cut
        $out .= "\n\n\n" . self::CMD_CUT_PARTIAL;

        return $out;
    }

    /**
     * Formats columns with spaces to match receipt width.
     */
    protected function formatLine(string $left, string $right, ?int $customWidth = null): string
    {
        $width = $customWidth ?? $this->paperWidthCols;
        $leftLen = strlen($left);
        $rightLen = strlen($right);

        if ($leftLen + $rightLen >= $width) {
            $left = substr($left, 0, $width - $rightLen - 1);
            $leftLen = strlen($left);
        }

        $spaces = str_repeat(' ', max(1, $width - $leftLen - $rightLen));
        return $left . $spaces . $right;
    }

    protected function horizontalDivider(string $char = '-'): string
    {
        return str_repeat($char, $this->paperWidthCols);
    }

    /**
     * ESC/POS QR Code Model 2 raw bytes
     */
    protected function generateQrCodeEscPos(string $data): string
    {
        $len = strlen($data) + 3;
        $pL = chr($len % 256);
        $pH = chr((int)($len / 256));

        $qr = "";
        // Set QR model
        $qr .= self::GS . "(k" . "\x04\x00\x31\x41\x32\x00";
        // Set QR module size (4 dots)
        $qr .= self::GS . "(k" . "\x03\x00\x31\x43\x04";
        // Set Error Correction level L
        $qr .= self::GS . "(k" . "\x03\x00\x31\x45\x30";
        // Store data
        $qr .= self::GS . "(k" . $pL . $pH . "\x31\x50\x30" . $data;
        // Print QR
        $qr .= self::GS . "(k" . "\x03\x00\x31\x51\x30";

        return $qr;
    }
}

