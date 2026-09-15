<?php

namespace RTOFLOW\Services;

use RTOFLOW\Config\Env;

if (!defined('ABSPATH')) exit;

/**
 * Invoice Service
 *
 * Generates GST-compliant tax invoices in PDF format.
 * Stores them securely and attaches to payment confirmation emails.
 *
 * Invoice contains:
 * - Company details (name, GSTIN, address, state)
 * - Client details (name, email, GSTIN if applicable)
 * - Line items (service fee, govt fee, GST breakdown)
 * - Total with CGST/SGST or IGST as applicable
 * - Amount in words
 * - Invoice number, date, payment details
 */
class InvoiceService
{
    private GstService $gst;

    public function __construct(GstService $gst)
    {
        $this->gst = $gst;
    }

    // ── Generate invoice for a lead + payment ─────────────────────────────

    public function generate(int $leadId, int $paymentId): array
    {
        global $wpdb;

        // Load full lead data
        $lead = $wpdb->get_row($wpdb->prepare(
            "SELECT l.*, s.name as service_name, s.govt_fee, s.gst_applicable,
                    c.name as city_name, st.name as state_name, st.code as state_code,
                    u.display_name as client_name, u.user_email as client_email
             FROM {$wpdb->prefix}rto_leads l
             LEFT JOIN {$wpdb->prefix}rto_services s ON s.id = l.service_id
             LEFT JOIN {$wpdb->prefix}rto_cities c ON c.id = l.city_id
             LEFT JOIN {$wpdb->prefix}rto_states st ON st.id = c.state_id
             LEFT JOIN {$wpdb->prefix}users u ON u.ID = l.client_id
             WHERE l.id = %d",
            $leadId
        ), ARRAY_A);

        if (!$lead) return ['success' => false, 'message' => 'Lead not found'];

        $payment = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}rto_payments WHERE id = %d", $paymentId),
            ARRAY_A
        );

        if (!$payment) return ['success' => false, 'message' => 'Payment not found'];

        // Calculate GST
        $gstBreakdown = $this->gst->calculateForLead($lead);

        // Generate invoice number
        $invoiceNumber = $this->generateInvoiceNumber($leadId);

        // Save invoice record first
        $invoiceId = $this->saveInvoiceRecord($invoiceNumber, $lead, $payment, $gstBreakdown);

        // BUGFIX: saveInvoiceRecord() now returns 0 on a failed insert instead
        // of a possibly-stale insert_id. Stop here rather than generating a
        // PDF for, and later UPDATE-ing, an invoice row that does not exist —
        // id 0 would either update nothing (silently, since $wpdb->update()'s
        // own return value here was also never checked) or, worse on some
        // schemas, could match an unrelated row.
        if ($invoiceId === 0) {
            return ['success' => false, 'message' => 'Unable to create invoice record. Please try again.'];
        }

        // Generate PDF
        $pdfPath = $this->generatePdf($invoiceId, $invoiceNumber, $lead, $payment, $gstBreakdown);

        if ($pdfPath) {
            $issuedUpdated = $wpdb->update(
                $wpdb->prefix . 'rto_invoices',
                ['pdf_path' => $pdfPath, 'status' => 'issued', 'issued_at' => current_time('mysql')],
                ['id' => $invoiceId]
            );
            if ($issuedUpdated === false) {
                // Non-fatal: the invoice row and its 'draft' status already
                // exist and are correct; only the pdf_path/issued status
                // failed to attach. Logged so this doesn't go unnoticed, but
                // the invoice itself was genuinely created, so this does not
                // block the caller (e.g. PaymentService::record()) from
                // reporting a successful payment.
                error_log('RTOFLOW InvoiceService: failed to mark invoice ' . $invoiceId . ' as issued — ' . $wpdb->last_error);
            }
        }

        return [
            'success'        => true,
            'invoice_id'     => $invoiceId,
            'invoice_number' => $invoiceNumber,
            'pdf_path'       => $pdfPath,
        ];
    }

    // ── PDF generation ────────────────────────────────────────────────────

    private function generatePdf(int $invoiceId, string $invNum, array $lead, array $payment, array $gst): ?string
    {
        // Use FPDF (included via composer) if available, otherwise HTML-to-PDF
        if (class_exists('\FPDF')) {
            return $this->generateFpdf($invoiceId, $invNum, $lead, $payment, $gst);
        }

        // Fallback: generate HTML invoice and store it (not PDF but printable)
        return $this->generateHtmlInvoice($invoiceId, $invNum, $lead, $payment, $gst);
    }

    private function generateFpdf(int $invoiceId, string $invNum, array $lead, array $payment, array $gst): ?string
    {
        try {
            $pdf = new \FPDF('P', 'mm', 'A4');
            $pdf->AddPage();
            $pdf->SetMargins(15, 15, 15);

            // ── Header ────────────────────────────────────────────────────
            $company = get_option('rtoflow_company_name', 'RTOFLOW');
            $gstin   = get_option('rtoflow_company_gstin', '');
            $address = get_option('rtoflow_company_address', '');

            $pdf->SetFont('Helvetica', 'B', 18);
            $pdf->SetTextColor(30, 58, 95); // Navy
            $pdf->Cell(0, 10, $company, 0, 1, 'L');

            $pdf->SetFont('Helvetica', '', 9);
            $pdf->SetTextColor(80, 80, 80);
            if ($gstin) $pdf->Cell(0, 5, 'GSTIN: ' . $gstin, 0, 1, 'L');
            if ($address) $pdf->MultiCell(120, 5, $address, 0, 'L');

            // ── Invoice label (right side) ─────────────────────────────────
            $pdf->SetXY(150, 15);
            $pdf->SetFont('Helvetica', 'B', 22);
            $pdf->SetTextColor(231, 76, 60); // Red accent
            $pdf->Cell(45, 10, 'TAX INVOICE', 0, 1, 'R');

            $pdf->SetFont('Helvetica', '', 9);
            $pdf->SetTextColor(80, 80, 80);
            $pdf->SetX(150);
            $pdf->Cell(45, 5, 'Invoice No: ' . $invNum, 0, 1, 'R');
            $pdf->SetX(150);
            $pdf->Cell(45, 5, 'Date: ' . date('d-m-Y'), 0, 1, 'R');
            $pdf->SetX(150);
            $pdf->Cell(45, 5, 'Order: ' . $lead['lead_number'], 0, 1, 'R');

            // ── Divider ────────────────────────────────────────────────────
            $pdf->SetDrawColor(30, 58, 95);
            $pdf->SetLineWidth(0.5);
            $pdf->Line(15, $pdf->GetY() + 3, 195, $pdf->GetY() + 3);
            $pdf->Ln(8);

            // ── Client Details ─────────────────────────────────────────────
            $pdf->SetFont('Helvetica', 'B', 10);
            $pdf->SetTextColor(30, 58, 95);
            $pdf->Cell(0, 6, 'Bill To:', 0, 1);
            $pdf->SetFont('Helvetica', '', 9);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Cell(0, 5, $lead['client_name'], 0, 1);
            $pdf->Cell(0, 5, $lead['client_email'], 0, 1);
            $pdf->Ln(5);

            // ── Line Items Table ───────────────────────────────────────────
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->SetFillColor(30, 58, 95);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->Cell(80, 7, 'Description', 1, 0, 'L', true);
            $pdf->Cell(20, 7, 'SAC', 1, 0, 'C', true);
            $pdf->Cell(25, 7, 'Taxable Amt', 1, 0, 'R', true);
            $pdf->Cell(20, 7, 'GST Rate', 1, 0, 'C', true);
            $pdf->Cell(35, 7, 'GST Amount', 1, 1, 'R', true);

            $pdf->SetFont('Helvetica', '', 9);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFillColor(240, 244, 248);
            $taxable  = $gst['service_fee'] ?? (float)$lead['total_amount'];
            $gstAmt   = $gst['total_gst'] ?? 0;
            $sacCode  = GstService::sacCode();

            $pdf->Cell(80, 7, GstService::getServiceDescription($lead['service_name']), 1, 0, 'L', true);
            $pdf->Cell(20, 7, $sacCode, 1, 0, 'C', true);
            $pdf->Cell(25, 7, '₹' . number_format($taxable, 2), 1, 0, 'R', true);
            $pdf->Cell(20, 7, $gst['gst_rate'] . '%', 1, 0, 'C', true);
            $pdf->Cell(35, 7, '₹' . number_format($gstAmt, 2), 1, 1, 'R', true);

            if (($gst['govt_fee'] ?? 0) > 0) {
                $pdf->SetFillColor(255, 255, 255);
                $pdf->Cell(80, 7, 'Government Fee (Exempt from GST)', 1, 0, 'L', false);
                $pdf->Cell(20, 7, '-', 1, 0, 'C', false);
                $pdf->Cell(25, 7, '₹' . number_format($gst['govt_fee'], 2), 1, 0, 'R', false);
                $pdf->Cell(20, 7, '0%', 1, 0, 'C', false);
                $pdf->Cell(35, 7, '₹0.00', 1, 1, 'R', false);
            }

            // ── GST Breakdown ──────────────────────────────────────────────
            $pdf->Ln(4);
            $pdf->SetFont('Helvetica', '', 9);

            $summaryX = 120;
            $pdf->SetX($summaryX);
            if (!$gst['is_interstate']) {
                $pdf->Cell(40, 6, 'CGST (' . ($gst['gst_rate'] / 2) . '%):', 0, 0, 'R');
                $pdf->Cell(35, 6, '₹' . number_format($gst['cgst'], 2), 0, 1, 'R');
                $pdf->SetX($summaryX);
                $pdf->Cell(40, 6, 'SGST (' . ($gst['gst_rate'] / 2) . '%):', 0, 0, 'R');
                $pdf->Cell(35, 6, '₹' . number_format($gst['sgst'], 2), 0, 1, 'R');
            } else {
                $pdf->Cell(40, 6, 'IGST (' . $gst['gst_rate'] . '%):', 0, 0, 'R');
                $pdf->Cell(35, 6, '₹' . number_format($gst['igst'], 2), 0, 1, 'R');
            }

            // ── Total ──────────────────────────────────────────────────────
            $pdf->SetX($summaryX);
            $pdf->SetFont('Helvetica', 'B', 11);
            $pdf->SetFillColor(30, 58, 95);
            $pdf->SetTextColor(255, 255, 255);
            $total = $gst['grand_total'] ?? (float)$lead['total_amount'];
            $pdf->Cell(40, 8, 'TOTAL:', 1, 0, 'R', true);
            $pdf->Cell(35, 8, '₹' . number_format($total, 2), 1, 1, 'R', true);

            // ── Amount in words ────────────────────────────────────────────
            $pdf->SetTextColor(80, 80, 80);
            $pdf->SetFont('Helvetica', 'I', 9);
            $pdf->Ln(3);
            $pdf->Cell(0, 5, 'Amount in words: ' . GstService::amountInWords($total), 0, 1);

            // ── Payment details ────────────────────────────────────────────
            $pdf->Ln(5);
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->SetTextColor(30, 58, 95);
            $pdf->Cell(0, 5, 'Payment Details:', 0, 1);
            $pdf->SetFont('Helvetica', '', 9);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Cell(0, 5, 'Method: ' . ucfirst($payment['method'] ?? ''), 0, 1);
            if ($payment['txn_id'] ?? '') {
                $pdf->Cell(0, 5, 'Transaction ID: ' . $payment['txn_id'], 0, 1);
            }

            // ── Footer ─────────────────────────────────────────────────────
            $pdf->SetY(-20);
            $pdf->SetFont('Helvetica', 'I', 8);
            $pdf->SetTextColor(150, 150, 150);
            $pdf->Cell(0, 5, 'This is a computer-generated invoice and does not require a signature.', 0, 1, 'C');

            // ── Save ───────────────────────────────────────────────────────
            $dir  = WP_CONTENT_DIR . '/uploads/rtoflow/invoices/' . date('Y/m');
            wp_mkdir_p($dir);
            $path = $dir . '/' . $invNum . '.pdf';
            $pdf->Output('F', $path);

            return 'invoices/' . date('Y/m') . '/' . $invNum . '.pdf';
        } catch (\Throwable $e) {
            error_log('RTOFLOW InvoiceService PDF error: ' . $e->getMessage());
            return null;
        }
    }

    private function generateHtmlInvoice(int $invoiceId, string $invNum, array $lead, array $payment, array $gst): string
    {
        $html = $this->renderHtmlInvoice($invNum, $lead, $payment, $gst);
        $dir  = WP_CONTENT_DIR . '/uploads/rtoflow/invoices/' . date('Y/m');
        wp_mkdir_p($dir);
        $path = $dir . '/' . $invNum . '.html';
        file_put_contents($path, $html);
        return 'invoices/' . date('Y/m') . '/' . $invNum . '.html';
    }

    private function renderHtmlInvoice(string $invNum, array $lead, array $payment, array $gst): string
    {
        $company = esc_html(get_option('rtoflow_company_name', 'RTOFLOW'));
        $gstin   = esc_html(get_option('rtoflow_company_gstin', ''));
        $total   = $gst['grand_total'] ?? (float)$lead['total_amount'];

        ob_start(); ?>
<!DOCTYPE html><html><head><meta charset="UTF-8">
<style>
  body{font-family:Arial,sans-serif;color:#333;margin:0;padding:30px}
  .header{display:flex;justify-content:space-between;margin-bottom:30px}
  .company-name{font-size:24px;font-weight:bold;color:#1E3A5F}
  .invoice-label{font-size:28px;color:#E74C3C;font-weight:bold;text-align:right}
  table{width:100%;border-collapse:collapse;margin:20px 0}
  th{background:#1E3A5F;color:#fff;padding:8px;text-align:left}
  td{padding:8px;border:1px solid #ddd}
  tr:nth-child(even) td{background:#f5f5f5}
  .total-row td{background:#1E3A5F!important;color:#fff;font-weight:bold;font-size:16px}
  .footer{margin-top:30px;font-size:12px;color:#999;text-align:center;border-top:1px solid #ddd;padding-top:15px}
</style></head><body>
<div class="header">
  <div><div class="company-name"><?= $company ?></div><?php if($gstin): ?><div>GSTIN: <?= $gstin ?></div><?php endif; ?></div>
  <div><div class="invoice-label">TAX INVOICE</div>
    <div style="text-align:right;font-size:13px">
      <div><?= esc_html($invNum) ?></div>
      <div>Date: <?= date('d-m-Y') ?></div>
      <div>Order: <?= esc_html($lead['lead_number']) ?></div>
    </div>
  </div>
</div>
<div style="margin-bottom:20px">
  <strong>Bill To:</strong><br>
  <?= esc_html($lead['client_name']) ?><br>
  <?= esc_html($lead['client_email']) ?>
</div>
<table>
  <thead><tr><th>Description</th><th>SAC</th><th>Taxable</th><th>GST</th><th>Total</th></tr></thead>
  <tbody>
    <tr><td><?= esc_html(GstService::getServiceDescription($lead['service_name'])) ?></td>
    <td><?= GstService::sacCode() ?></td>
    <td>₹<?= number_format($gst['service_fee'] ?? (float)$lead['total_amount'], 2) ?></td>
    <td><?= $gst['gst_rate'] ?>%</td>
    <td>₹<?= number_format($gst['total_gst'] ?? 0, 2) ?></td></tr>
    <?php if (($gst['govt_fee'] ?? 0) > 0): ?>
    <tr><td>Government Fee (GST Exempt)</td><td>-</td><td>₹<?= number_format($gst['govt_fee'], 2) ?></td><td>0%</td><td>₹0.00</td></tr>
    <?php endif; ?>
    <tr class="total-row"><td colspan="4"><strong>TOTAL</strong></td><td><strong>₹<?= number_format($total, 2) ?></strong></td></tr>
  </tbody>
</table>
<div><em><?= esc_html(GstService::amountInWords($total)) ?></em></div>
<div style="margin-top:15px">
  <?php if (!$gst['is_interstate']): ?>
  CGST (<?= $gst['gst_rate']/2 ?>%): ₹<?= number_format($gst['cgst'], 2) ?> |
  SGST (<?= $gst['gst_rate']/2 ?>%): ₹<?= number_format($gst['sgst'], 2) ?>
  <?php else: ?>
  IGST (<?= $gst['gst_rate'] ?>%): ₹<?= number_format($gst['igst'], 2) ?>
  <?php endif; ?>
</div>
<div class="footer">This is a computer-generated invoice. | <?= $company ?> | <?= date('Y') ?></div>
</body></html>
        <?php
        return ob_get_clean();
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function generateInvoiceNumber(int $leadId): string
    {
        global $wpdb;
        // P5-DB-005 FIX: use advisory lock + year-scoped MAX instead of COUNT(*)+1
        $locked = (int)$wpdb->get_var("SELECT GET_LOCK('rto_invoice_number', 10)");
        if ($locked !== 1) {
            // Lock acquisition failed — use timestamp fallback for uniqueness
            return 'INV-' . date('Y') . '-' . date('mdHis') . '-' . $leadId;
        }
        try {
            $year   = date('Y');
            $prefix = 'INV-' . $year . '-';
            $max    = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(MAX(CAST(SUBSTRING(invoice_number, %d) AS UNSIGNED)), 0)
                 FROM {$wpdb->prefix}rto_invoices WHERE invoice_number LIKE %s",
                strlen($prefix) + 1,
                $prefix . '%'
            ));
            return $prefix . str_pad($max + 1, 6, '0', STR_PAD_LEFT);
        } finally {
            // ENTERPRISE GAP FIX (Phase 9, item — "raw, unparameterized
            // queries in a handful of internal paths"):
            // 'rto_invoice_number' is a fixed literal lock name, not user
            // input — flagged only because tools/check-raw-queries.php's
            // blanket rule requires an explicit annotation on every
            // $wpdb->query() that isn't wrapped in prepare().
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query("SELECT RELEASE_LOCK('rto_invoice_number')");
        }
    }

    private function saveInvoiceRecord(string $invNum, array $lead, array $payment, array $gst): int
    {
        global $wpdb;
        // BUGFIX (ghost-success sweep, same class of bug already fixed
        // elsewhere this pass): this insert's return value was never checked.
        // On a real WordPress/MySQL connection, $wpdb->insert_id after a
        // FAILED insert can still hold a stale value from an unrelated prior
        // insert on the same connection. Since the caller, generate(), keys
        // its later PDF-path/status UPDATE on this returned id, a silent
        // insert failure here previously risked corrupting a completely
        // unrelated pre-existing invoice row -- overwriting ITS pdf_path and
        // marking IT 'issued' -- while the invoice that was actually supposed
        // to be created for this payment silently never existed. Now a
        // failed insert returns 0, and the caller checks for that before
        // doing anything further.
        $inserted = $wpdb->insert($wpdb->prefix . 'rto_invoices', [
            'invoice_number'  => $invNum,
            'lead_id'         => $lead['id'],
            'client_id'       => $lead['client_id'],
            'subtotal'        => $gst['service_fee'] ?? (float)$lead['total_amount'],
            'gst_rate'        => $gst['gst_rate'] ?? 18.0,
            'cgst'            => $gst['cgst'] ?? 0,
            'sgst'            => $gst['sgst'] ?? 0,
            'igst'            => $gst['igst'] ?? 0,
            'total'           => $gst['grand_total'] ?? (float)$lead['total_amount'],
            'status'          => 'draft',
            'place_of_supply' => $gst['supply_state'] ?? '',
            'created_at'      => current_time('mysql'),
        ]);

        if (!$inserted) {
            error_log('RTOFLOW InvoiceService: invoice insert failed for lead ' . $lead['id'] . ' — ' . $wpdb->last_error);
            return 0;
        }

        return (int)$wpdb->insert_id;
    }

    // ── Download URL ──────────────────────────────────────────────────────

    public function downloadUrl(int $invoiceId): string
    {
        return home_url('/rto-invoice/' . $invoiceId . '?token=' . $this->downloadToken($invoiceId));
    }

    private function downloadToken(int $invoiceId): string
    {
        return hash_hmac('sha256', 'invoice-' . $invoiceId, AUTH_KEY);
    }
}
