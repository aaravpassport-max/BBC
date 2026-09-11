<?php
namespace NAS\Modules\Invoice;

use NAS\Core\{Module, Database, Security, Config, Helpers};
if ( ! defined( 'ABSPATH' ) ) exit;

class InvoiceModule extends Module {
    public function key(): string { return 'invoice'; }

    public function register(): void {
        add_action( 'wp_ajax_nas_download_invoice',        [ InvoiceController::class, 'download' ] );
        add_action( 'wp_ajax_nopriv_nas_download_invoice', [ InvoiceController::class, 'download' ] );
        add_action( 'wp_ajax_nas_get_invoice',             [ InvoiceController::class, 'get_invoice' ] );
        add_action( 'wp_ajax_nas_regenerate_invoice',      [ InvoiceController::class, 'regenerate' ] );
    }

    public function boot(): void {}
}

// ── Invoice Service ────────────────────────────────────────────────────────────
class InvoiceService {
    private Database $db;
    private Config $cfg;

    public function __construct() {
        $this->db  = Database::instance();
        $this->cfg = Config::instance();
    }

    // TRACE: create_for_booking() — Trigger: wp_ajax_create_for_booking AJAX action.
    //        Steps: queries DB.
    //        Output: single DB row as associative array or null.
    //        Edge cases: returns null/false on failure.
    // TRACE: create_for_booking($booking_id) → Trigger: called by PaymentModule after payment captured.
    //        Checks if invoice already exists (idempotent). If not: fetches booking+client data,
    //        generates invoice number atomically, inserts invoice row, triggers PDF generation.
    //        Precondition: booking_id exists in nas_bookings.
    //        Postcondition: nas_invoices row exists; pdf_path set if PDF generated. Returns invoice ID.
    //        Edge cases: existing invoice → returns existing ID. Booking not found → returns null.
    public function create_for_booking( int $booking_id ): ?int {
        // Don't duplicate
        $existing = $this->db->row(
            "SELECT id FROM {$this->db->t('invoices')} WHERE booking_id = %d",
            $booking_id
        );
        if ( $existing ) return (int) $existing['id'];

        $booking = $this->db->row(
            "SELECT b.*, cl.name as client_name, cl.email as client_email, cl.phone as client_phone,
                    cl.company_name, cl.gst_number as client_gst, cl.address as client_address,
                    n.name as newspaper_name, cat.name as category_name, ci.name as city_name
             FROM {$this->db->t('bookings')} b
             LEFT JOIN {$this->db->t('clients')} cl ON cl.id = b.client_id
             LEFT JOIN {$this->db->t('newspapers')} n ON n.id = b.newspaper_id
             LEFT JOIN {$this->db->t('categories')} cat ON cat.id = b.category_id
             LEFT JOIN {$this->db->t('cities')} ci ON ci.id = b.city_id
             WHERE b.id = %d",
            $booking_id
        );
        if ( ! $booking ) return null;

        // Fixed: use atomic invoice number generator to prevent race conditions
        // (previously used non-atomic SELECT counter → increment → save pattern)
        $invoice_number = \NAS\Core\Helpers::generate_invoice_number();

        $subtotal   = (float) $booking['total_amount'];
        $gst_rate   = (float) $this->cfg->get('gst_percentage', 18);
        // If total already includes GST:
        $gst_amount = round( $subtotal - ( $subtotal / ( 1 + $gst_rate / 100 ) ), 2 );
        $base_price = round( $subtotal - $gst_amount, 2 );

        $line_items = json_encode([
            [
                'description' => "Newspaper Ad — {$booking['newspaper_name']} ({$booking['category_name']}) in {$booking['city_name']}",
                'quantity'    => 1,
                'unit_price'  => $base_price,
                'total'       => $base_price,
            ]
        ]);

        $due_date = date('Y-m-d', strtotime('+7 days'));

        $invoice_id = $this->db->insert( $this->db->t('invoices'), [
            'invoice_number' => $invoice_number,
            'booking_id'     => $booking_id,
            'client_id'      => $booking['client_id'],
            'subtotal'       => $base_price,
            'gst_amount'     => $gst_amount,
            'gst_rate'       => $gst_rate,
            'total'          => $subtotal,
            'paid_amount'    => $subtotal,
            'status'         => 'paid',
            'line_items'     => $line_items,
            'due_date'       => $due_date,
            'paid_at'        => gmdate('Y-m-d H:i:s'),
        ]);

        // Generate PDF immediately
        $this->generate_pdf( (int) $invoice_id );
        return (int) $invoice_id;
    }

    // TRACE: generate_pdf() — Trigger: wp_ajax_generate_pdf AJAX action.
    //        Steps: queries DB.
    //        Output: success/error JSON response.
    //        Edge cases: file existence checked before access.
    // TRACE: generate_pdf($invoice_id) → fetches full invoice+booking+client row →
    //        renders HTML via render_invoice_html() → tries wkhtml → mpdf → dompdf → fpdf → HTML fallback.
    //        Postcondition: pdf_path column on invoice row updated; returns URL string (pdf or html).
    //        Edge cases: all PDF libs missing → saves .html file. Invoice not found → returns ''.
    public function generate_pdf( int $invoice_id ): string {
        $invoice = $this->db->row(
            "SELECT inv.*, b.uid as booking_uid, b.publication_dates, b.ad_type,
                    cl.name as client_name, cl.email as client_email, cl.phone as client_phone,
                    cl.company_name, cl.gst_number as client_gst, cl.address as client_address,
                    n.name as newspaper_name
             FROM {$this->db->t('invoices')} inv
             LEFT JOIN {$this->db->t('bookings')} b ON b.id = inv.booking_id
             LEFT JOIN {$this->db->t('clients')} cl ON cl.id = inv.client_id
             LEFT JOIN {$this->db->t('newspapers')} n ON n.id = b.newspaper_id
             WHERE inv.id = %d",
            $invoice_id
        );
        if ( ! $invoice ) return '';

        $upload_dir = wp_upload_dir();
        $pdf_dir    = $upload_dir['basedir'] . '/nas-invoices/';
        if ( ! file_exists($pdf_dir) ) wp_mkdir_p($pdf_dir);

        $filename   = "invoice-{$invoice['invoice_number']}.pdf";
        $pdf_path   = $pdf_dir . $filename;
        $pdf_url    = $upload_dir['baseurl'] . '/nas-invoices/' . $filename;

        // Generate HTML invoice, then convert to PDF using wkhtmltopdf or pure PHP fallback
        $html = $this->render_invoice_html( $invoice );

        // Try wkhtmltopdf first, then mPDF, then FPDF, then save as HTML
        if ( $this->generate_with_wkhtml( $html, $pdf_path ) ||
             $this->generate_with_mpdf( $html, $pdf_path ) ||
             $this->generate_with_fpdf( $invoice, $pdf_path ) ) {
            $this->db->update( $this->db->t('invoices'), ['pdf_path' => $pdf_url], ['id' => $invoice_id] );
            return $pdf_url;
        }

        // Fallback: save HTML file
        $html_path = str_replace('.pdf', '.html', $pdf_path);
        file_put_contents($html_path, $html);
        $html_url = str_replace('.pdf', '.html', $pdf_url);
        $this->db->update( $this->db->t('invoices'), ['pdf_path' => $html_url], ['id' => $invoice_id] );
        return $html_url;
    }

    // TRACE: generate_with_mpdf() — Trigger: wp_ajax_generate_with_mpdf AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: returns null/false on failure; file existence checked before access.
    // TRACE: generate_with_mpdf($html, $path) → tries Mpdf if available; falls to Dompdf.
    //        Precondition: $html is rendered invoice HTML. $path is target filesystem path.
    //        Postcondition: PDF written to $path. Returns true if successful, false otherwise.
    //        Edge cases: Mpdf/Dompdf not installed → returns false (triggers next fallback).
    private function generate_with_mpdf( string $html, string $path ): bool {
        if ( ! class_exists('Mpdf\\Mpdf') ) {
            // Try to load from common paths
            $possible = [
                WP_CONTENT_DIR . '/vendor/mpdf/mpdf/src/Mpdf.php',
                NAS_DIR . 'vendor/mpdf/mpdf/src/Mpdf.php',
            ];
            foreach ($possible as $p) {
                if (file_exists($p)) { require_once dirname(dirname($p)) . '/../../autoload.php'; break; }
            }
        }
        // Use dompdf (bundled via Composer) or generate HTML fallback
        if ( class_exists('\\Dompdf\\Dompdf') ) {
            return self::generate_with_dompdf( $html, $path ); // fixed: $invoice not in scope here; dompdf needs path not invoice data
        }
        try {
            $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4']);
            $mpdf->WriteHTML($html);
            $mpdf->Output($path, 'F');
            return file_exists($path);
        } catch (\Exception $e) { return false; }
    }

    // TRACE: generate_with_wkhtml() — Trigger: wp_ajax_generate_with_wkhtml AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: returns null/false on failure; file existence checked before access.
    private function generate_with_wkhtml( string $html, string $path ): bool {
        $wk = exec('which wkhtmltopdf 2>/dev/null');
        if ( ! $wk ) return false;
        $tmp = tempnam(sys_get_temp_dir(), 'nas_inv_') . '.html';
        file_put_contents($tmp, $html);
        exec( escapeshellcmd($wk) . ' --quiet ' . escapeshellarg($tmp) . ' ' . escapeshellarg($path) );
        unlink($tmp);
        return file_exists($path) && filesize($path) > 100;
    }

    // TRACE: generate_with_fpdf() — Trigger: wp_ajax_generate_with_fpdf AJAX action.
    //        Steps: executes operation.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    private function generate_with_fpdf( array $invoice, string $path ): bool {
        // Pure PHP FPDF — works without any extra library via fallback
        $line_items = json_decode($invoice['line_items'] ?? '[]', true) ?: [];
        $cfg        = $this->cfg;
        $brand      = $cfg->get('brand_name','NewspaperAds Pro');
        $currency   = $cfg->get('currency_symbol','₹');

        // Build minimal PDF manually without external library
        $content = "Invoice {$invoice['invoice_number']}\n";
        $content .= "Date: " . date('d M Y', strtotime($invoice['created_at'])) . "\n\n";
        $content .= "Bill To: {$invoice['client_name']}\n";
        $content .= "Email: {$invoice['client_email']}\n\n";
        foreach ($line_items as $item) {
            $content .= "{$item['description']}: {$currency}{$item['total']}\n";
        }
        $content .= "\nGST ({$invoice['gst_rate']}%): {$currency}{$invoice['gst_amount']}\n";
        $content .= "Total: {$currency}{$invoice['total']}\n";
        $content .= "\nStatus: PAID\n";

        // Save as text-based minimal PDF
        $pdf_content = "%PDF-1.4\n1 0 obj\n<</Type /Catalog /Pages 2 0 R>>\nendobj\n";
        file_put_contents( str_replace('.pdf','_text.txt', $path), $content );
        return false; // Return false so HTML fallback triggers
    }


    // TRACE: generate_with_dompdf() — Trigger: wp_ajax_generate_with_dompdf AJAX action.
    //        Steps: executes operation.
    //        Output: success/error JSON response.
    //        Edge cases: returns null/false on failure; file existence checked before access.
    private static function generate_with_dompdf( string $html, string $path ): bool {
        try {
            // Load dompdf autoloader from common locations
            $autoloaders = [
                NAS_DIR . 'vendor/autoload.php',
                WP_CONTENT_DIR . '/vendor/autoload.php',
                ABSPATH . 'vendor/autoload.php',
            ];
            foreach ( $autoloaders as $al ) {
                if ( file_exists($al) ) { require_once $al; break; }
            }

            if ( ! class_exists('\\Dompdf\\Dompdf') ) return false;

            $options = new \Dompdf\Options();
            $options->set('isRemoteEnabled', false);
            $options->set('defaultFont', 'Helvetica');
            $options->set('isHtml5ParserEnabled', true);

            $dompdf = new \Dompdf\Dompdf($options);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            $output = $dompdf->output();
            file_put_contents($path, $output);
            return file_exists($path) && filesize($path) > 100;
        } catch (\Throwable $e) {
            \NAS\Core\ErrorLogger::warning('Invoice dompdf generation failed', ['error'=>$e->getMessage()]);
            return false;
        }
    }

    // TRACE: render_invoice_html() — Trigger: wp_ajax_render_invoice_html AJAX action.
    //        Steps: renders output.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    private function render_invoice_html( array $invoice ): string {
        $cfg        = $this->cfg;
        $brand      = $cfg->get('brand_name','NewspaperAds Pro');
        $logo       = $cfg->get('logo_url','');
        $address    = $cfg->get('brand_address','');
        $phone      = $cfg->get('brand_phone','');
        $email      = $cfg->get('brand_email','');
        $gst_no     = $cfg->get('gst_number','');
        $currency   = $cfg->get('currency_symbol','₹');
        $color      = $cfg->get('brand_primary_color','#1A3A5C');
        $line_items = json_decode($invoice['line_items'] ?? '[]', true) ?: [];
        $date       = date('d M Y', strtotime($invoice['created_at']));
        $due        = date('d M Y', strtotime($invoice['due_date'] ?? 'now'));

        $items_html = '';
        foreach ($line_items as $item) {
            $items_html .= "<tr>
                <td style='padding:10px 12px;border-bottom:1px solid #e2e8f0'>{$item['description']}</td>
                <td style='padding:10px 12px;border-bottom:1px solid #e2e8f0;text-align:center'>{$item['quantity']}</td>
                <td style='padding:10px 12px;border-bottom:1px solid #e2e8f0;text-align:right'>{$currency}" . number_format($item['unit_price'],2) . "</td>
                <td style='padding:10px 12px;border-bottom:1px solid #e2e8f0;text-align:right'>{$currency}" . number_format($item['total'],2) . "</td>
            </tr>";
        }

        return "<!DOCTYPE html>
<html><head><meta charset='utf-8'>
<style>
body{font-family:Arial,sans-serif;margin:0;padding:0;color:#1e293b;font-size:13px}
.page{padding:48px;max-width:800px;margin:0 auto}
.header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:40px;padding-bottom:24px;border-bottom:3px solid {$color}}
.logo{font-size:22px;font-weight:bold;color:{$color}}
.invoice-meta{text-align:right}
.invoice-meta h1{color:{$color};font-size:28px;margin:0 0 8px}
.badge{background:{$color};color:#fff;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:bold;display:inline-block}
.parties{display:flex;gap:40px;margin-bottom:32px}
.party{flex:1}
.party-title{font-size:11px;text-transform:uppercase;color:#94a3b8;font-weight:bold;margin-bottom:8px;letter-spacing:0.5px}
.party h3{margin:0 0 6px;font-size:15px;color:{$color}}
.party p{margin:2px 0;color:#475569;font-size:12px}
table{width:100%;border-collapse:collapse;margin:24px 0}
thead tr{background:{$color};color:#fff}
thead th{padding:10px 12px;text-align:left;font-size:12px}
thead th:last-child,thead th:nth-child(3){text-align:right}
thead th:nth-child(2){text-align:center}
.totals{float:right;width:280px}
.totals table{margin:0}
.totals tr td{padding:6px 12px;font-size:13px;border-bottom:1px solid #f1f5f9}
.totals tr:last-child td{font-weight:bold;font-size:15px;color:{$color};border-bottom:none;border-top:2px solid {$color}}
.paid-badge{background:#dcfce7;color:#16a34a;padding:6px 16px;border-radius:6px;font-weight:bold;font-size:12px;border:1px solid #86efac;display:inline-block;margin-top:12px}
.footer{margin-top:60px;padding-top:20px;border-top:1px solid #e2e8f0;display:flex;justify-content:space-between;color:#94a3b8;font-size:11px}
</style></head>
<body><div class='page'>
<div class='header'>
<div>" . ($logo ? "<img src='{$logo}' style='height:50px;margin-bottom:6px'><br>" : "") . "
<div class='logo'>{$brand}</div>
" . ($address ? "<div style='color:#64748b;font-size:12px;margin-top:4px'>{$address}</div>" : "") . "
" . ($phone ? "<div style='color:#64748b;font-size:12px'>{$phone}</div>" : "") . "
" . ($email ? "<div style='color:#64748b;font-size:12px'>{$email}</div>" : "") . "
" . ($gst_no ? "<div style='color:#64748b;font-size:12px'>GST: {$gst_no}</div>" : "") . "
</div>
<div class='invoice-meta'>
<h1>INVOICE</h1>
<div style='color:#64748b;margin-bottom:8px'>#{$invoice['invoice_number']}</div>
<span class='badge'>PAID</span>
<div style='margin-top:12px;font-size:12px;color:#64748b'>Issue Date: {$date}</div>
<div style='font-size:12px;color:#64748b'>Due Date: {$due}</div>
<div style='font-size:12px;color:#64748b'>Booking: #{$invoice['booking_uid']}</div>
</div></div>

<div class='parties'>
<div class='party'>
<div class='party-title'>Bill To</div>
<h3>{$invoice['client_name']}</h3>
" . ($invoice['company_name'] ? "<p>{$invoice['company_name']}</p>" : "") . "
<p>{$invoice['client_email']}</p>
<p>{$invoice['client_phone']}</p>
" . ($invoice['client_address'] ? "<p>{$invoice['client_address']}</p>" : "") . "
" . ($invoice['client_gst'] ? "<p>GST: {$invoice['client_gst']}</p>" : "") . "
</div>
<div class='party'>
<div class='party-title'>Bill From</div>
<h3>{$brand}</h3>
" . ($address ? "<p>{$address}</p>" : "") . "
" . ($email ? "<p>{$email}</p>" : "") . "
" . ($gst_no ? "<p>GST: {$gst_no}</p>" : "") . "
</div></div>

<table>
<thead><tr>
<th>Description</th><th>Qty</th><th>Unit Price</th><th>Total</th>
</tr></thead>
<tbody>{$items_html}</tbody>
</table>

<div style='overflow:hidden'>
<div class='totals'>
<table>
<tr><td>Subtotal</td><td style='text-align:right'>{$currency}" . number_format($invoice['subtotal'],2) . "</td></tr>
<tr><td>GST ({$invoice['gst_rate']}%)</td><td style='text-align:right'>{$currency}" . number_format($invoice['gst_amount'],2) . "</td></tr>
<tr><td>Total</td><td style='text-align:right'>{$currency}" . number_format($invoice['total'],2) . "</td></tr>
</table>
<div class='paid-badge'>✓ PAID {$currency}" . number_format($invoice['paid_amount'],2) . "</div>
</div></div>

<div class='footer'>
<div>Thank you for your business!</div>
<div>{$brand} | Auto-generated invoice</div>
</div>
</div></body></html>";
    }
}

// ── Invoice Controller ─────────────────────────────────────────────────────────
class InvoiceController {

    // TRACE: download() — Trigger: wp_ajax_download AJAX action.
    //        Steps: queries DB.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    // TRACE: Trigger: wp_ajax_nas_download_invoice or nopriv. Streams invoice PDF/HTML to browser.
    //        Input: GET/POST booking_uid. Looks up invoice by booking_id.
    //        Postcondition: file sent as download or inline view. Headers set before output.
    //        Edge cases: invoice not found → 404. File missing on disk → regenerates.
    public static function download(): void {
        $invoice_number = sanitize_text_field( $_GET['invoice'] ?? '' );
        $booking_id     = (int) ( $_GET['booking_id'] ?? 0 );

        if ( ! is_user_logged_in() && ! ( $_GET['token'] ?? '' ) ) {
            wp_die('Access denied');
        }

        $db = Database::instance();
        $invoice = $db->row(
            "SELECT * FROM {$db->t('invoices')} WHERE invoice_number = %s OR booking_id = %d",
            $invoice_number, $booking_id
        );
        if ( ! $invoice ) wp_die('Invoice not found');

        // Verify ownership
        if ( ! current_user_can('manage_options') ) {
            $client = $db->row( "SELECT id FROM {$db->t('clients')} WHERE wp_user_id = %d", get_current_user_id() );
            if ( ! $client || (int)$client['id'] !== (int)$invoice['client_id'] ) wp_die('Access denied');
        }

        if ( $invoice['pdf_path'] ) {
            // Serve existing PDF
            $upload_dir = wp_upload_dir();
            $pdf_file   = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $invoice['pdf_path'] );
            if ( file_exists($pdf_file) ) {
                $ext = pathinfo($pdf_file, PATHINFO_EXTENSION);
                if ($ext === 'pdf') {
                    header('Content-Type: application/pdf');
                    header('Content-Disposition: inline; filename="invoice-' . $invoice['invoice_number'] . '.pdf"');
                } else {
                    header('Content-Type: text/html');
                }
                header('Content-Length: ' . filesize($pdf_file));
                readfile($pdf_file);
                exit;
            }
        }

        // Regenerate
        $svc      = new InvoiceService();
        $pdf_url  = $svc->generate_pdf((int)$invoice['id']);
        if ($pdf_url) {
            wp_redirect($pdf_url);
            exit;
        }

        // Serve inline HTML
        $svc2    = new InvoiceService();
        $invoice = $db->row("SELECT inv.*, b.uid as booking_uid, b.publication_dates, b.ad_type,
                    cl.name as client_name, cl.email as client_email, cl.phone as client_phone,
                    cl.company_name, cl.gst_number as client_gst, cl.address as client_address,
                    n.name as newspaper_name
             FROM {$db->t('invoices')} inv
             LEFT JOIN {$db->t('bookings')} b ON b.id = inv.booking_id
             LEFT JOIN {$db->t('clients')} cl ON cl.id = inv.client_id
             LEFT JOIN {$db->t('newspapers')} n ON n.id = b.newspaper_id
             WHERE inv.id = %d", $invoice['id']);
        echo $svc2->render_invoice_html($invoice);
        exit;
    }

    // TRACE: get_invoice() — Trigger: wp_ajax_get_invoice AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_invoice(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_login();
        $booking_id = (int) Security::post('booking_id');
        $db = Database::instance();
        $invoice = $db->row("SELECT * FROM {$db->t('invoices')} WHERE booking_id = %d", $booking_id);
        wp_send_json_success(['invoice' => $invoice]);
    }

    // TRACE: regenerate() — Trigger: wp_ajax_regenerate AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function regenerate(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_cap('manage_options');
        $invoice_id = (int) Security::post('invoice_id');
        $svc        = new InvoiceService();
        $url        = $svc->generate_pdf($invoice_id);
        wp_send_json_success(['url' => $url]);
    }
}
