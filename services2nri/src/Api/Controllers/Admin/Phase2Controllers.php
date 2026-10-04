<?php
/**
 * Phase 2 Controllers — M-11 through M-29 + UX gaps
 *
 * M-11: Form Submissions admin tab + per-booking exports
 * M-12: Bulk export Excel (selected IDs)
 * M-14: WhatsApp CallMeBot integration
 * M-17: Bulk document download (ZIP)
 * M-18: Form Details & Export tab per booking
 * M-19: Standalone quote widget shortcode
 * M-20: Ticket search on request detail
 * M-21: Currently assigned label
 * M-24: Conditional field logic (admin + frontend read)
 * M-25: Searchable dropdown field type backend
 * M-26: Drag-to-reorder form fields
 * M-27: Dropdown data manager
 * M-28: Bulk import for dropdown options
 * M-29: Help text per form field
 * UX:   Document type badges, email sent badge, service_not_available status
 */

namespace S2NRI\Api\Controllers\Admin;

use S2NRI\Api\{Request, Response};
use S2NRI\Api\Controllers\BaseController;

defined( 'ABSPATH' ) || exit;

// ══════════════════════════════════════════════════════════════════════════════
// FormSubmissionsController
// M-11: Admin tab showing all submitted form data with per-booking export
// M-18: Form Details & Export tab (PDF / CSV / plain text)
// ══════════════════════════════════════════════════════════════════════════════

class FormSubmissionsController extends BaseController {

    /**
     * GET admin/form-submissions
     * Paginated table of all bookings with their submitted field_data.
     * Supports search by booking_ref, customer name, service name.
     *
     * TRACE: requireManager → paginate → WHERE search → JOIN services/customers →
     *        decode field_data JSON → return rows + total.
     */
    public function index( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $p = $wpdb->prefix;

        [ 'page' => $page, 'per_page' => $per_page ] = $this->paginate( $req );
        $offset  = $this->offset( $page, $per_page );
        $search  = sanitize_text_field( $req->query( 'search', '' ) );
        $service = (int) $req->query( 'service_id', '0' );

        $where = 'WHERE 1=1';
        $args  = [];

        if ( $search ) {
            $like   = '%' . $wpdb->esc_like( $search ) . '%';
            $where .= ' AND (b.booking_ref LIKE %s OR COALESCE(u.display_name, u.user_login) LIKE %s OR s.name LIKE %s)';
            $args[] = $like; $args[] = $like; $args[] = $like;
        }
        if ( $service ) {
            $where .= ' AND b.service_id = %d';
            $args[] = $service;
        }

        $count_sql = "SELECT COUNT(*) FROM {$p}s2nri_bookings b
                      LEFT JOIN {$p}s2nri_services s ON s.id=b.service_id
                      LEFT JOIN {$p}s2nri_customers cu ON cu.id=b.customer_id
                      LEFT JOIN {$p}users u ON u.ID=cu.wp_user_id
                      {$where}";
        $total = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, ...$args ) ) : $wpdb->get_var( $count_sql ) );

        $rows_sql = "SELECT b.id, b.booking_ref, b.status, b.created_at, b.field_data,
                            s.name AS service_name,
                            COALESCE(u.display_name, u.user_login, '') AS customer_name,
                            u.user_email AS customer_email
                     FROM {$p}s2nri_bookings b
                     LEFT JOIN {$p}s2nri_services s ON s.id=b.service_id
                     LEFT JOIN {$p}s2nri_customers cu ON cu.id=b.customer_id
                     LEFT JOIN {$p}users u ON u.ID=cu.wp_user_id
                     {$where}
                     ORDER BY b.created_at DESC LIMIT %d OFFSET %d";
        $all_args = array_merge( $args, [ $per_page, $offset ] );
        $rows = $args
            ? $wpdb->get_results( $wpdb->prepare( $rows_sql, ...$all_args ), ARRAY_A )
            : $wpdb->get_results( $wpdb->prepare( $rows_sql, $per_page, $offset ), ARRAY_A );

        foreach ( $rows as &$row ) {
            $row['field_data'] = json_decode( $row['field_data'] ?? '{}', true ) ?: [];
        }
        unset( $row );

        Response::json( compact( 'rows', 'total', 'page', 'per_page' ) );
    }

    /**
     * GET admin/form-submissions/{id}/export?format=csv|txt|html
     * M-18: Export submitted form data for a single booking.
     * format=csv  → RFC 4180 CSV with Field,Value columns
     * format=txt  → plain text list
     * format=html → printable HTML card grid (inline-printable, no external deps)
     *
     * TRACE: requireStaff → get booking + field_data + service_name →
     *        decode JSON → switch format → output with correct Content-Disposition.
     * EDGE CASES: empty field_data → outputs header row only.
     *             unknown format → defaults to csv.
     */
    public function export( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $p      = $wpdb->prefix;
        $id     = (int) $req->param( 'id' );
        $format = sanitize_key( $req->query( 'format', 'pdf' ) );
        if ( ! in_array( $format, ['csv', 'txt', 'html', 'pdf', 'png', 'docx', 'xlsx'], true ) ) $format = 'pdf';

        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT b.id, b.booking_ref, b.status, b.created_at, b.field_data,
                    b.quoted_amount, b.secondary_status, b.due_date,
                    s.name AS service_name,
                    COALESCE(u.display_name, u.user_login, '') AS customer_name,
                    u.user_email AS customer_email,
                    cu.phone AS customer_phone, cu.country AS customer_country
             FROM {$p}s2nri_bookings b
             LEFT JOIN {$p}s2nri_services s ON s.id=b.service_id
             LEFT JOIN {$p}s2nri_customers cu ON cu.id=b.customer_id
             LEFT JOIN {$p}users u ON u.ID=cu.wp_user_id
             WHERE b.id=%d LIMIT 1",
            $id
        ), ARRAY_A );

        if ( ! $booking ) {
            status_header(404); echo json_encode(['error'=>'Not found']); exit;
        }

        $fields = json_decode( $booking['field_data'] ?? '{}', true ) ?: [];
        $ref    = $booking['booking_ref'];
        $site   = \S2NRI\Models\Setting::get('platform_name','Services2NRI');

        // Build clean flat array of label => value (exclude internal __ keys as label-less)
        $clean = [];
        foreach ( $fields as $key => $value ) {
            if ( str_starts_with($key, '__') ) continue; // internal meta
            $label = ucwords( str_replace(['_','-'], ' ', $key) );
            $val   = is_array($value) ? implode(', ', $value) : (string) $value;
            $clean[$label] = $val;
        }

        $filename_base = sanitize_file_name( $ref . '-form-details-' . date('Y-m-d') );

        match($format) {
            'txt'  => $this->exportTxt(  $clean, $booking, $filename_base, $site ),
            'html' => $this->exportHtml( $clean, $booking, $filename_base, $site ),
            'pdf'  => $this->exportPdf(  $clean, $booking, $filename_base, $site ),
            'png'  => $this->exportPng(  $clean, $booking, $filename_base, $site ),
            'docx' => $this->exportDocx( $clean, $booking, $filename_base, $site ),
            'xlsx' => $this->exportXlsx( $clean, $booking, $filename_base, $site ),
            default => $this->exportCsv( $clean, $booking, $filename_base ),
        };
    }

    /**
     * Build a self-contained printable HTML page used as the base for PDF and PNG.
     * Returns the complete HTML string.
     */
    private function buildPrintableHtml( array $fields, array $booking, string $site ): string {
        $color   = esc_attr( \S2NRI\Models\Setting::get('primary_color','#4A6FA5') );
        $ref_h   = esc_html( $booking['booking_ref'] );
        $svc_h   = esc_html( $booking['service_name'] );
        $cust_h  = esc_html( $booking['customer_name'] );
        $email_h = esc_html( $booking['customer_email'] );
        $date_h  = esc_html( date('d M Y', strtotime($booking['created_at'])) );
        $stat_h  = esc_html( ucwords( str_replace('_',' ', $booking['status']) ) );
        $site_h  = esc_html( $site );
        $cards   = '';
        foreach ($fields as $label => $value) {
            $lh = esc_html($label); $vh = esc_html($value) ?: '—';
            $cards .= "<div style='background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:14px 16px;break-inside:avoid'>
                <p style='font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin:0 0 6px'>{$lh}</p>
                <p style='font-size:14px;color:#1E2D40;margin:0;font-weight:600'>{$vh}</p></div>\n";
        }
        return "<!DOCTYPE html><html><head><meta charset='UTF-8'>
<title>Form Details — {$ref_h}</title>
<style>
body{font-family:Arial,sans-serif;margin:0;padding:24px;background:#f4f6f9;color:#1E2D40}
.hdr{background:{$color};color:#fff;padding:20px 28px;border-radius:10px;margin-bottom:20px;display:flex;justify-content:space-between;align-items:flex-start}
.meta{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:14px 20px;margin-bottom:20px;display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px}
.mi p:first-child{font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin:0 0 3px;font-weight:700}
.mi p:last-child{font-size:13px;color:#374151;margin:0;font-weight:600}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px}
</style></head><body>
<div class='hdr'>
 <div><p style='margin:0 0 4px;font-size:12px;opacity:.8'>{$site_h}</p>
  <h1 style='margin:0;font-size:20px'>{$ref_h}</h1>
  <p style='margin:4px 0 0;font-size:13px;opacity:.8'>{$svc_h}</p></div>
 <div style='text-align:right;font-size:12px;opacity:.85'><p style='margin:0'>{$stat_h}</p><p style='margin:2px 0 0'>{$date_h}</p></div>
</div>
<div class='meta'>
 <div class='mi'><p>Customer</p><p>{$cust_h}</p></div>
 <div class='mi'><p>Email</p><p>{$email_h}</p></div>
 <div class='mi'><p>Reference</p><p>{$ref_h}</p></div>
 <div class='mi'><p>Date</p><p>{$date_h}</p></div>
</div>
<h3 style='font-size:13px;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin:0 0 12px'>Form Responses</h3>
<div class='grid'>{$cards}</div>
</body></html>";
    }

    /**
     * M-18: PDF export — generates a printable HTML page that the browser can save as PDF.
     * Delivered inline so the browser Print dialog opens directly.
     * Full PDF generation (e.g. mPDF) requires a PHP extension not available on shared hosting;
     * the printable HTML approach is identical to what the reference plugin provides.
     */
    private function exportPdf( array $fields, array $booking, string $filename, string $site ): void {
        $html = $this->buildPrintableHtml($fields, $booking, $site);
        // Inject auto-print trigger so the PDF save dialog opens immediately
        $html = str_replace('</body>', '<script>window.onload=function(){window.print();}</script></body>', $html);
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: inline; filename="' . $filename . '.pdf"');
        header('Cache-Control: no-store');
        echo $html;
        exit;
    }

    /**
     * M-18: PNG/Image export — delivers printable HTML, same as PDF.
     * The browser's "Save as Image" feature handles the conversion on the client side.
     */
    private function exportPng( array $fields, array $booking, string $filename, string $site ): void {
        $html = $this->buildPrintableHtml($fields, $booking, $site);
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: inline; filename="' . $filename . '.html"');
        header('Cache-Control: no-store');
        echo $html;
        exit;
    }

    /**
     * M-18: Word (.docx) export — pure PHP, no external libraries.
     * Generates a valid Office Open XML (.docx) as a minimal in-memory ZIP.
     */
    private function exportDocx( array $fields, array $booking, string $filename, string $site ): void {
        $ref  = esc_html( $booking['booking_ref'] );
        $svc  = esc_html( $booking['service_name'] );
        $cust = esc_html( $booking['customer_name'] );
        $date = esc_html( date('d M Y', strtotime($booking['created_at'])) );
        $stat = esc_html( ucwords( str_replace('_',' ', $booking['status']) ) );

        // Build paragraph XML for each field
        $paras = '';
        $paras .= "<w:p><w:pPr><w:pStyle w:val='Heading1'/></w:pPr><w:r><w:t>Form Details — {$ref}</w:t></w:r></w:p>";
        $paras .= "<w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Service: </w:t></w:r><w:r><w:t>{$svc}</w:t></w:r></w:p>";
        $paras .= "<w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Customer: </w:t></w:r><w:r><w:t>{$cust}</w:t></w:r></w:p>";
        $paras .= "<w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Date: </w:t></w:r><w:r><w:t>{$date}</w:t></w:r></w:p>";
        $paras .= "<w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Status: </w:t></w:r><w:r><w:t>{$stat}</w:t></w:r></w:p>";
        $paras .= "<w:p><w:r><w:t xml:space='preserve'></w:t></w:r></w:p>";
        $paras .= "<w:p><w:pPr><w:pStyle w:val='Heading2'/></w:pPr><w:r><w:t>Submitted Fields</w:t></w:r></w:p>";
        foreach ($fields as $label => $value) {
            $lx = esc_xml($label); $vx = esc_xml($value);
            $paras .= "<w:p><w:r><w:rPr><w:b/></w:rPr><w:t xml:space='preserve'>{$lx}: </w:t></w:r><w:r><w:t>{$vx}</w:t></w:r></w:p>";
        }

        $doc_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:wpc="http://schemas.microsoft.com/office/word/2010/wordprocessingCanvas"
 xmlns:mo="http://schemas.microsoft.com/office/mac/office/2008/main"
 xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006"
 xmlns:mv="urn:schemas-microsoft-com:mac:vml"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"
 xmlns:m="http://schemas.openxmlformats.org/officeDocument/2006/math"
 xmlns:v="urn:schemas-microsoft-com:vml"
 xmlns:wp14="http://schemas.microsoft.com/office/word/2010/wordprocessingDrawing"
 xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"
 xmlns:w10="urn:schemas-microsoft-com:office:word"
 xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"
 xmlns:w14="http://schemas.microsoft.com/office/word/2010/wordml"
 xmlns:wpg="http://schemas.microsoft.com/office/word/2010/wordprocessingGroup"
 xmlns:wpi="http://schemas.microsoft.com/office/word/2010/wordprocessingInk"
 xmlns:wne="http://schemas.microsoft.com/office/word/2006/wordml"
 xmlns:wps="http://schemas.microsoft.com/office/word/2010/wordprocessingShape">
<w:body>' . $paras . '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440"/></w:sectPr></w:body></w:document>';

        $rels_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>';

        $word_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>';

        $content_types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
</Types>';

        $styles_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
<w:style w:type="paragraph" w:styleId="Normal"><w:name w:val="Normal"/><w:rPr><w:sz w:val="24"/><w:szCs w:val="24"/></w:rPr></w:style>
<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/><w:rPr><w:b/><w:sz w:val="32"/></w:rPr></w:style>
<w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:basedOn w:val="Normal"/><w:rPr><w:b/><w:sz w:val="28"/></w:rPr></w:style>
</w:styles>';

        // Build ZIP in memory
        if ( ! class_exists('ZipArchive') ) {
            // Fallback: HTML download
            $this->exportHtml($fields, $booking, $filename, $site); return;
        }
        $tmp = tempnam(sys_get_temp_dir(), 's2nri_docx_');
        @unlink($tmp);
        $tmp .= '.docx';
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $this->exportHtml($fields, $booking, $filename, $site); return;
        }
        $zip->addFromString('[Content_Types].xml', $content_types);
        $zip->addFromString('_rels/.rels', $rels_xml);
        $zip->addFromString('word/document.xml', $doc_xml);
        $zip->addFromString('word/_rels/document.xml.rels', $word_rels);
        $zip->addFromString('word/styles.xml', $styles_xml);
        $zip->close();

        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . $filename . '.docx"');
        header('Cache-Control: no-store');
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    /**
     * M-18: Excel (.xlsx) export — pure PHP Open XML spreadsheet.
     */
    private function exportXlsx( array $fields, array $booking, string $filename, string $site ): void {
        if ( ! class_exists('ZipArchive') ) {
            $this->exportCsv($fields, $booking, $filename); return;
        }

        // Build sheet data rows
        $rows = [
            ['Field', 'Value'],
            ['Reference',    $booking['booking_ref']],
            ['Service',      $booking['service_name']],
            ['Customer',     $booking['customer_name']],
            ['Email',        $booking['customer_email']],
            ['Date',         $booking['created_at']],
            ['Status',       $booking['status']],
            ['', ''],
        ];
        foreach ($fields as $label => $value) {
            $rows[] = [$label, $value];
        }

        // Build shared strings
        $allStrings = [];
        $strIndex = [];
        foreach ($rows as $row) {
            foreach ($row as $cell) {
                $s = (string)$cell;
                if (!isset($strIndex[$s])) { $strIndex[$s] = count($allStrings); $allStrings[] = $s; }
            }
        }
        $ssXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($allStrings) . '" uniqueCount="' . count($allStrings) . '">';
        foreach ($allStrings as $s) { $ssXml .= '<si><t xml:space="preserve">' . esc_xml($s) . '</t></si>'; }
        $ssXml .= '</sst>';

        // Build sheet
        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        $cols = ['A','B','C','D','E','F','G','H'];
        foreach ($rows as $ri => $row) {
            $rowNum = $ri + 1;
            $sheetXml .= "<row r=\"{$rowNum}\">";
            foreach ($row as $ci => $cell) {
                $col = $cols[$ci] ?? chr(65+$ci);
                $ref = $col . $rowNum;
                $si  = $strIndex[(string)$cell] ?? 0;
                $sheetXml .= "<c r=\"{$ref}\" t=\"s\"><v>{$si}</v></c>";
            }
            $sheetXml .= '</row>';
        }
        $sheetXml .= '</sheetData></worksheet>';

        $wbXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Form Details" sheetId="1" r:id="rId1"/></sheets></workbook>';
        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/></Relationships>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/></Types>';

        $tmp = tempnam(sys_get_temp_dir(), 's2nri_xlsx_') . '.xlsx';
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $this->exportCsv($fields, $booking, $filename); return;
        }
        $zip->addFromString('[Content_Types].xml', $ct);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('xl/workbook.xml', $wbXml);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->addFromString('xl/sharedStrings.xml', $ssXml);
        $zip->close();

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
        header('Cache-Control: no-store');
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    private function exportCsv( array $fields, array $booking, string $filename ): void {
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '.csv"' );
        header( 'Cache-Control: no-store' );
        $out = fopen('php://output','w');
        // Meta header rows
        fputcsv($out, ['Reference', $booking['booking_ref']]);
        fputcsv($out, ['Service',   $booking['service_name']]);
        fputcsv($out, ['Customer',  $booking['customer_name']]);
        fputcsv($out, ['Date',      $booking['created_at']]);
        fputcsv($out, ['Status',    $booking['status']]);
        fputcsv($out, []);
        fputcsv($out, ['Field', 'Value']);
        foreach ($fields as $label => $value) {
            fputcsv($out, [$label, $value]);
        }
        fclose($out);
        exit;
    }

    private function exportTxt( array $fields, array $booking, string $filename, string $site ): void {
        header( 'Content-Type: text/plain; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '.txt"' );
        header( 'Cache-Control: no-store' );
        echo "{$site} — Form Submission Export\n";
        echo str_repeat('=', 50) . "\n\n";
        echo "Reference : {$booking['booking_ref']}\n";
        echo "Service   : {$booking['service_name']}\n";
        echo "Customer  : {$booking['customer_name']} <{$booking['customer_email']}>\n";
        echo "Date      : {$booking['created_at']}\n";
        echo "Status    : {$booking['status']}\n\n";
        echo str_repeat('-', 50) . "\n\n";
        foreach ($fields as $label => $value) {
            echo str_pad($label, 28) . ": {$value}\n";
        }
        exit;
    }

    private function exportHtml( array $fields, array $booking, string $filename, string $site ): void {
        $color    = esc_attr( \S2NRI\Models\Setting::get('primary_color','#4A6FA5') );
        $ref_h    = esc_html( $booking['booking_ref'] );
        $svc_h    = esc_html( $booking['service_name'] );
        $cust_h   = esc_html( $booking['customer_name'] );
        $email_h  = esc_html( $booking['customer_email'] );
        $date_h   = esc_html( date('d M Y', strtotime($booking['created_at'])) );
        $status_h = esc_html( ucwords(str_replace('_',' ',$booking['status'])) );
        $site_h   = esc_html($site);

        $cards = '';
        foreach ($fields as $label => $value) {
            $lh = esc_html($label);
            $vh = esc_html($value) ?: '—';
            $cards .= "<div style='background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:14px 16px;break-inside:avoid'>
                <p style='font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin:0 0 6px'>{$lh}</p>
                <p style='font-size:14px;color:#1E2D40;margin:0;font-weight:600'>{$vh}</p>
              </div>\n";
        }

        header( 'Content-Type: text/html; charset=utf-8' );
        header( 'Content-Disposition: inline; filename="' . $filename . '.html"' );
        header( 'Cache-Control: no-store' );
        echo "<!DOCTYPE html><html><head><meta charset='UTF-8'>
<title>Form Details — {$ref_h}</title>
<style>
 @media print { .no-print{display:none} body{margin:0} }
 body{font-family:Arial,sans-serif;margin:0;padding:24px;background:#f4f6f9;color:#1E2D40}
 .header{background:{$color};color:#fff;padding:20px 28px;border-radius:10px;margin-bottom:20px;display:flex;justify-content:space-between;align-items:flex-start}
 .meta{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:14px 20px;margin-bottom:20px;display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px}
 .meta-item p:first-child{font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin:0 0 3px;font-weight:700}
 .meta-item p:last-child{font-size:13px;color:#374151;margin:0;font-weight:600}
 .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px}
 .btn{background:{$color};color:#fff;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;font-size:13px;font-weight:700}
</style>
</head><body>
<div class='no-print' style='margin-bottom:16px'>
 <button class='btn' onclick='window.print()'>🖨 Print</button>
</div>
<div class='header'>
 <div>
  <p style='margin:0 0 4px;font-size:12px;opacity:.8'>{$site_h}</p>
  <h1 style='margin:0;font-size:20px'>{$ref_h}</h1>
  <p style='margin:4px 0 0;font-size:13px;opacity:.8'>{$svc_h}</p>
 </div>
 <div style='text-align:right;font-size:12px;opacity:.85'>
  <p style='margin:0'>{$status_h}</p>
  <p style='margin:2px 0 0'>{$date_h}</p>
 </div>
</div>
<div class='meta'>
 <div class='meta-item'><p>Customer</p><p>{$cust_h}</p></div>
 <div class='meta-item'><p>Email</p><p>{$email_h}</p></div>
 <div class='meta-item'><p>Reference</p><p>{$ref_h}</p></div>
 <div class='meta-item'><p>Date</p><p>{$date_h}</p></div>
</div>
<h3 style='font-size:13px;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin:0 0 12px'>Form Responses</h3>
<div class='grid'>{$cards}</div>
</body></html>";
        exit;
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// BulkExportController
// M-12: Bulk export selected booking IDs as CSV (Extended ExportController)
// ══════════════════════════════════════════════════════════════════════════════

class BulkExportController extends BaseController {

    /**
     * POST admin/export/bookings/bulk
     * Body: { ids: [1,2,3,...] }
     * Downloads CSV with all columns for selected booking IDs.
     *
     * TRACE: requireManager → validate ids array (max 500) →
     *        IN (%d,...) query → outputCsv.
     * EDGE CASES: empty ids → 422. IDs > 500 → truncated to 500.
     *             non-integer IDs → cast + filtered.
     */
    public function bulkBookings( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $p = $wpdb->prefix;

        $raw_ids = $req->input('ids', []);
        if ( ! is_array($raw_ids) || empty($raw_ids) ) {
            Response::json(['error' => 'No booking IDs provided.'], 422); return;
        }
        $ids = array_unique( array_filter( array_map('intval', $raw_ids) ) );
        if ( count($ids) > 500 ) $ids = array_slice($ids, 0, 500);

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT b.booking_ref AS 'Reference', b.status AS 'Status',
                    b.payment_status AS 'Payment Status',
                    b.quoted_amount AS 'Quoted Amount',
                    b.paid_amount AS 'Paid Amount',
                    b.secondary_status AS 'Sub-Status',
                    b.due_date AS 'Due Date',
                    b.created_at AS 'Created',
                    b.updated_at AS 'Updated',
                    b.shipping_type AS 'Shipping Type',
                    s.name AS 'Service',
                    c.name AS 'Category',
                    COALESCE(u.display_name, u.user_login, '') AS 'Customer Name',
                    u.user_email AS 'Customer Email',
                    cu.phone AS 'Phone',
                    cu.country AS 'Country',
                    COALESCE(au.display_name, au.user_login, '') AS 'Assigned To',
                    v.name AS 'Vendor'
             FROM {$p}s2nri_bookings b
             LEFT JOIN {$p}s2nri_services s ON s.id=b.service_id
             LEFT JOIN {$p}s2nri_categories c ON c.id=b.category_id
             LEFT JOIN {$p}s2nri_customers cu ON cu.id=b.customer_id
             LEFT JOIN {$p}users u ON u.ID=cu.wp_user_id
             LEFT JOIN {$p}users au ON au.ID=b.assigned_to
             LEFT JOIN {$p}s2nri_vendors v ON v.id=b.vendor_id
             WHERE b.id IN ($placeholders)
             ORDER BY b.created_at DESC",
            ...$ids
        ), ARRAY_A );

        if ( empty($rows) ) {
            Response::json(['error' => 'No matching bookings found.'], 404); return;
        }

        $filename = 'bookings-selected-' . date('Y-m-d-His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');
        header('Pragma: no-cache');
        $out = fopen('php://output','w');
        fputcsv($out, array_keys($rows[0]));
        foreach ($rows as $row) {
            fputcsv($out, array_values($row));
        }
        fclose($out);
        exit;
    }

    /**
     * POST admin/export/bookings/bulk-json
     * Returns JSON for client-side Excel generation (no server-side xlsx needed).
     * Body: { ids: [1,2,...] }
     * Returns: { rows: [...], columns: [...] }
     */
    public function bulkBookingsJson( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $p = $wpdb->prefix;

        $raw_ids = $req->input('ids', []);
        if ( ! is_array($raw_ids) || empty($raw_ids) ) {
            Response::json(['error' => 'No IDs provided.'], 422); return;
        }
        $ids = array_unique( array_filter( array_map('intval', $raw_ids) ) );
        if ( count($ids) > 500 ) $ids = array_slice($ids, 0, 500);

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT b.id, b.booking_ref, b.status, b.payment_status,
                    b.quoted_amount, b.paid_amount, b.secondary_status, b.due_date,
                    b.created_at, b.shipping_type,
                    s.name AS service_name, c.name AS category_name,
                    COALESCE(u.display_name, u.user_login, '') AS customer_name,
                    u.user_email AS customer_email, cu.phone, cu.country,
                    COALESCE(au.display_name, '') AS assigned_to
             FROM {$p}s2nri_bookings b
             LEFT JOIN {$p}s2nri_services s ON s.id=b.service_id
             LEFT JOIN {$p}s2nri_categories c ON c.id=b.category_id
             LEFT JOIN {$p}s2nri_customers cu ON cu.id=b.customer_id
             LEFT JOIN {$p}users u ON u.ID=cu.wp_user_id
             LEFT JOIN {$p}users au ON au.ID=b.assigned_to
             WHERE b.id IN ($placeholders) ORDER BY b.created_at DESC",
            ...$ids
        ), ARRAY_A );

        Response::json(['rows' => $rows ?: [], 'columns' => $rows ? array_keys($rows[0]) : []]);
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// WhatsAppController
// M-14: CallMeBot API integration for WhatsApp notifications
// ══════════════════════════════════════════════════════════════════════════════

class WhatsAppController extends BaseController {

    /**
     * POST admin/whatsapp/test
     * Body: { number, message }
     * Sends a test WhatsApp message via CallMeBot.
     *
     * TRACE: requireManager → validate number (E.164) + message →
     *        call CallMeBot API → return result.
     * EDGE CASES: CallMeBot API down → 502. Invalid number → 422.
     *             Rate limiting by CallMeBot → surfaced as error.
     */
    public function test( Request $req ): void {
        $this->requireManager();
        $number  = sanitize_text_field( $req->input('number','') );
        $message = sanitize_textarea_field( $req->input('message','Test WhatsApp from Services2NRI') );
        if ( ! $number ) { Response::json(['error'=>'WhatsApp number required (E.164 format e.g. +919876543210)'], 422); return; }
        $result = \S2NRI\Services\WhatsAppService::send($number, $message);
        Response::json($result);
    }

    /**
     * POST admin/whatsapp/send
     * Body: { number, message } — direct admin-initiated send
     */
    public function send( Request $req ): void {
        $this->requireStaff();
        $number  = sanitize_text_field( $req->input('number','') );
        $message = sanitize_textarea_field( $req->input('message','') );
        if ( ! $number || ! $message ) { Response::json(['error'=>'Number and message required.'], 422); return; }
        $result = \S2NRI\Services\WhatsAppService::send($number, $message);
        Response::json($result);
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// DocumentBulkController
// M-17: Bulk document download as ZIP
// ══════════════════════════════════════════════════════════════════════════════

class DocumentBulkController extends BaseController {

    /**
     * POST bookings/{id}/documents/download-zip
     * Body: { doc_ids: [1,2,3] } — specific selection, or omit for all docs
     * Downloads a ZIP containing selected (or all) documents for the booking.
     *
     * TRACE: auth check (customer owns booking OR is staff) →
     *        get doc rows from s2nri_documents →
     *        download each file from file_url to temp dir →
     *        ZipArchive → stream → cleanup.
     * EDGE CASES: ZipArchive extension missing → 503.
     *             Doc URLs not reachable → skip with warning.
     *             Customer requesting staff-only docs → filtered out.
     *             No docs → 404.
     */
    public function downloadZip( Request $req ): void {
        global $wpdb;
        $p          = $wpdb->prefix;
        $booking_id = (int) $req->param('id');
        $doc_ids    = $req->input('doc_ids', []);

        // Auth: customer must own the booking, or user must be staff
        $is_staff = $this->userIsStaff();
        if ( ! $is_staff ) {
            $customer = $wpdb->get_row( $wpdb->prepare(
                "SELECT c.id FROM {$p}s2nri_customers c WHERE c.wp_user_id=%d LIMIT 1",
                $this->user['wp_id']
            ), ARRAY_A );
            $owns = $customer ? $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}s2nri_bookings WHERE id=%d AND customer_id=%d LIMIT 1",
                $booking_id, $customer['id']
            ) ) : false;
            if ( ! $owns ) { Response::json(['error'=>'Access denied.'], 403); return; }
        }

        if ( ! class_exists('ZipArchive') ) {
            Response::json(['error'=>'ZIP extension not available on this server.'], 503); return;
        }

        // Build WHERE clause
        $where = $wpdb->prepare("WHERE d.booking_id=%d", $booking_id);
        if ( ! $is_staff ) {
            $where .= " AND d.is_visible_to_customer=1";
        }
        if ( is_array($doc_ids) && count($doc_ids) > 0 ) {
            $safe_ids    = array_map('intval', $doc_ids);
            $placeholders = implode(',', $safe_ids);
            $where .= " AND d.id IN ({$placeholders})";
        }

        $docs = $wpdb->get_results(
            "SELECT d.id, d.file_name, d.file_url, d.doc_type FROM {$p}s2nri_documents d {$where} LIMIT 100",
            ARRAY_A
        );

        if ( empty($docs) ) { Response::json(['error'=>'No documents found.'], 404); return; }

        $booking_ref = $wpdb->get_var( $wpdb->prepare(
            "SELECT booking_ref FROM {$p}s2nri_bookings WHERE id=%d LIMIT 1", $booking_id
        ) );

        // Create temp directory for this download
        $tmp_dir = wp_upload_dir()['basedir'] . '/s2nri-tmp/' . uniqid('zip_', true);
        wp_mkdir_p($tmp_dir);

        $zip_path = $tmp_dir . '/' . sanitize_file_name($booking_ref) . '-documents.zip';
        $zip = new \ZipArchive();
        if ( $zip->open($zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true ) {
            Response::json(['error'=>'Failed to create ZIP file.'], 500); return;
        }

        $added = 0;
        $upload_dir = wp_upload_dir()['basedir'];

        foreach ($docs as $doc) {
            $file_url  = $doc['file_url'];
            $file_name = $doc['file_name'] ?: basename($file_url);

            // Convert URL to local path when possible (avoids HTTP overhead for local files)
            $local_path = $this->urlToLocalPath($file_url, $upload_dir);

            if ( $local_path && file_exists($local_path) ) {
                $zip->addFile($local_path, $file_name);
                $added++;
            } else {
                // Fallback: download via HTTP with 30s timeout
                $response = wp_remote_get($file_url, ['timeout' => 30, 'stream' => true,
                    'filename' => $tmp_dir . '/' . sanitize_file_name($file_name)]);
                if ( ! is_wp_error($response) ) {
                    $tmp_file = $tmp_dir . '/' . sanitize_file_name($file_name);
                    if ( file_exists($tmp_file) ) {
                        $zip->addFile($tmp_file, $file_name);
                        $added++;
                    }
                }
            }
        }

        $zip->close();

        if ( $added === 0 ) {
            // Cleanup and return error
            array_map('unlink', glob($tmp_dir . '/*'));
            rmdir($tmp_dir);
            Response::json(['error'=>'No files could be added to the ZIP.'], 500); return;
        }

        // Stream ZIP to browser
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($booking_ref) . '-documents.zip"');
        header('Content-Length: ' . filesize($zip_path));
        header('Cache-Control: no-store');
        readfile($zip_path);

        // Cleanup temp directory after streaming
        register_shutdown_function(function() use ($tmp_dir) {
            array_map('unlink', glob($tmp_dir . '/*'));
            @rmdir($tmp_dir);
        });
        exit;
    }

    private function urlToLocalPath( string $url, string $upload_basedir ): ?string {
        $upload_url = wp_upload_dir()['baseurl'];
        if ( strpos($url, $upload_url) === 0 ) {
            return $upload_basedir . substr($url, strlen($upload_url));
        }
        return null;
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// FormFieldAdminController
// M-24: Conditional field logic admin CRUD
// M-25: Searchable dropdown field type (options management)
// M-26: Drag-to-reorder form fields
// M-27: Dropdown data manager
// M-28: Bulk import for dropdown options
// M-29: Help text per form field
//
// Architecture: s2nri_form_fields table per-service, supports all field types
// including searchable with DB-backed options, conditional logic JSON rules.
// ══════════════════════════════════════════════════════════════════════════════

class FormFieldAdminController extends BaseController {

    /**
     * GET admin/services/{id}/form-fields
     *
     * Returns all form fields for a service ordered by sort_order.
     *
     * IMPORTANT: The public booking form uses qualification_schema stored as JSON
     * in the s2nri_services row. The s2nri_form_fields table is the editable version.
     * On first call for a service that has never been edited here, we auto-import
     * qualification_schema → s2nri_form_fields so the portal shows the real form.
     * All subsequent edits are saved to s2nri_form_fields.
     */
    public function index( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $p          = $wpdb->prefix;
        $service_id = (int) $req->param('id');

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}s2nri_form_fields WHERE service_id=%d ORDER BY sort_order ASC, id ASC",
            $service_id
        ), ARRAY_A );

        // If no custom fields exist yet, auto-import from qualification_schema
        if ( empty( $rows ) ) {
            $service = $wpdb->get_row( $wpdb->prepare(
                "SELECT qualification_schema, form_schema FROM {$p}s2nri_services WHERE id=%d LIMIT 1",
                $service_id
            ), ARRAY_A );

            if ( $service ) {
                // Prefer qualification_schema; fall back to form_schema
                $raw_schema = null;
                foreach ( ['qualification_schema', 'form_schema'] as $col ) {
                    if ( ! empty( $service[ $col ] ) ) {
                        $decoded = json_decode( $service[ $col ], true );
                        if ( is_array( $decoded ) && count( $decoded ) > 0 ) {
                            $raw_schema = $decoded;
                            break;
                        }
                    }
                }

                if ( $raw_schema ) {
                    $sort = 1;
                    foreach ( $raw_schema as $field ) {
                        $key  = sanitize_key( $field['key'] ?? '' );
                        $type = sanitize_key( $field['type'] ?? 'text' );
                        if ( ! $key ) continue;

                        // Map qualification_schema type names to our allowed types
                        // IMPORTANT: 'dropdown' and 'searchable' are preserved as-is
                        // because the portal Oo (Dropdown Data Manager) filters for exactly
                        // these type names. Mapping 'dropdown' → 'select' would break the DM.
                        $type_map = [
                            'multi'      => 'checkbox',
                            'toggle'     => 'checkbox',
                            'paragraph'  => 'textarea',
                            // dropdown → keep as 'dropdown' (portal Oo looks for this)
                            // searchable → keep as 'searchable' (portal Oo looks for this)
                            // radio → keep as 'radio' (portal Oo looks for this)
                        ];
                        if ( isset( $type_map[ $type ] ) ) $type = $type_map[ $type ];

                        $allowed_types = ['text','number','email','phone','textarea','select',
                                          'dropdown','searchable','radio','checkbox','date','file'];
                        if ( ! in_array( $type, $allowed_types, true ) ) $type = 'text';

                        // Convert conditions: {show_if: {key: value}} format
                        $conditions = null;
                        if ( ! empty( $field['conditions'] ) ) {
                            $conditions = wp_json_encode( $field['conditions'] );
                        }

                        // Convert options: array of strings
                        $options_raw = $field['options'] ?? [];
                        $options = null;
                        if ( is_array( $options_raw ) && count( $options_raw ) > 0 ) {
                            $options = wp_json_encode( array_values( $options_raw ) );
                        }

                        // Skip duplicate keys silently
                        $exists = $wpdb->get_var( $wpdb->prepare(
                            "SELECT id FROM {$p}s2nri_form_fields WHERE service_id=%d AND field_key=%s LIMIT 1",
                            $service_id, $key
                        ) );
                        if ( $exists ) { $sort++; continue; }

                        if ( $wpdb->insert( $p . 's2nri_form_fields', [
                            'service_id'  => $service_id,
                            'field_key'   => $key,
                            'label'       => sanitize_text_field( $field['label']       ?? $key ),
                            'field_type'  => $type,
                            'step'        => (int) ( $field['step'] ?? 1 ),
                            'required'    => ! empty( $field['required'] ) ? 1 : 0,
                            'placeholder' => sanitize_text_field( $field['placeholder'] ?? '' ),
                            'help_text'   => sanitize_textarea_field( $field['description'] ?? $field['help_text'] ?? '' ),
                            'options'     => $options,
                            'conditions'  => $conditions,
                            'is_active'   => 1,
                            'sort_order'  => $sort++,
                        ] ) === false ) {
                            error_log( '[S2NRI] Bulk import field insert failed for key "' . $key . '" on service ' . $service_id . ': ' . $wpdb->last_error );
                        }
                    }

                    // Reload the freshly-imported rows
                    $rows = $wpdb->get_results( $wpdb->prepare(
                        "SELECT * FROM {$p}s2nri_form_fields WHERE service_id=%d ORDER BY sort_order ASC, id ASC",
                        $service_id
                    ), ARRAY_A );
                }
            }
        }

        foreach ($rows as &$row) {
            $row['options']    = $row['options']    ? json_decode($row['options'],    true) : [];
            $row['conditions'] = $row['conditions'] ? json_decode($row['conditions'], true) : null;
        }
        unset($row);

        Response::json(['fields' => $rows ?: []]);
    }

    /**
     * POST admin/form-fields/import-all
     *
     * ISSUE 12 FIX: Bulk-imports qualification_schema from every service into
     * s2nri_form_fields so the Dropdown Data Manager is never empty on first load.
     * Safe to call multiple times — skips services that already have fields imported.
     * Called automatically on plugin activation and available as an admin action.
     *
     * TRACE: requireStaff → fetch all services with qualification_schema/form_schema
     *        → for each, if no rows in form_fields yet, import fields
     *        → return {imported_services, total_fields} summary.
     */
    public function importAll( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $p = $wpdb->prefix;

        // Load all services that have a schema but no imported fields yet
        $services = $wpdb->get_results(
            "SELECT id, name, qualification_schema, form_schema
             FROM `{$p}s2nri_services`
             WHERE is_active = 1
               AND (qualification_schema IS NOT NULL OR form_schema IS NOT NULL)
             ORDER BY id ASC",
            ARRAY_A
        ) ?: [];

        $imported_services = 0;
        $total_fields      = 0;
        $skipped           = 0;

        foreach ( $services as $service ) {
            $sid = (int) $service['id'];

            // Check if already imported
            $existing_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$p}s2nri_form_fields` WHERE service_id = %d",
                $sid
            ) );

            if ( $existing_count > 0 ) {
                $skipped++;
                continue; // Already imported for this service
            }

            // Find the schema to import
            $raw_schema = null;
            foreach ( ['qualification_schema', 'form_schema'] as $col ) {
                if ( ! empty( $service[ $col ] ) ) {
                    $decoded = json_decode( $service[ $col ], true );
                    if ( is_array( $decoded ) && count( $decoded ) > 0 ) {
                        $raw_schema = $decoded;
                        break;
                    }
                }
            }

            if ( ! $raw_schema ) continue;

            $sort         = 1;
            $fields_added = 0;

            $type_map = [
                'multi'     => 'checkbox',
                'toggle'    => 'checkbox',
                'paragraph' => 'textarea',
            ];
            $allowed_types = ['text','number','email','phone','textarea','select',
                              'dropdown','searchable','radio','checkbox','date','file'];

            foreach ( $raw_schema as $field ) {
                $key  = sanitize_key( $field['key'] ?? '' );
                $type = sanitize_key( $field['type'] ?? 'text' );
                if ( ! $key ) continue;

                if ( isset( $type_map[ $type ] ) ) $type = $type_map[ $type ];
                if ( ! in_array( $type, $allowed_types, true ) ) $type = 'text';

                $options_raw = $field['options'] ?? [];
                $options     = ( is_array( $options_raw ) && count( $options_raw ) > 0 )
                    ? wp_json_encode( array_values( $options_raw ) )
                    : null;

                $conditions = ! empty( $field['conditions'] )
                    ? wp_json_encode( $field['conditions'] )
                    : null;

                if ( $wpdb->insert( $p . 's2nri_form_fields', [
                    'service_id'  => $sid,
                    'field_key'   => $key,
                    'label'       => sanitize_text_field( $field['label'] ?? $key ),
                    'field_type'  => $type,
                    'step'        => (int) ( $field['step'] ?? 1 ),
                    'required'    => ! empty( $field['required'] ) ? 1 : 0,
                    'placeholder' => sanitize_text_field( $field['placeholder'] ?? '' ),
                    'help_text'   => sanitize_textarea_field( $field['description'] ?? $field['help_text'] ?? '' ),
                    'options'     => $options,
                    'conditions'  => $conditions,
                    'is_active'   => 1,
                    'sort_order'  => $sort++,
                ] ) === false ) {
                    error_log( '[S2NRI] Form field insert failed for key "' . $key . '" on service ' . $sid . ': ' . $wpdb->last_error );
                } else {
                    $fields_added++;
                }
            }

            if ( $fields_added > 0 ) {
                $imported_services++;
                $total_fields += $fields_added;
            }
        }

        Response::json( [
            'success'           => true,
            'imported_services' => $imported_services,
            'total_fields'      => $total_fields,
            'skipped_services'  => $skipped,
            'message'           => "Imported {$total_fields} fields across {$imported_services} services. Skipped {$skipped} (already imported).",
        ] );
    }

    /**
     * POST admin/services/{id}/form-fields
     * Body: { label, key, type, step, required, placeholder, help_text, options, conditions, sort_order }
     * Allowed types: text|number|email|phone|textarea|select|searchable|radio|checkbox|date|file
     *
     * TRACE: requireManager → validate required fields (label, key, type) →
     *        sanitize all inputs → INSERT → return new id.
     * EDGE CASES: duplicate key within service → unique key constraint error → 422.
     *             Unknown type → defaults to 'text'.
     *             Options for searchable: JSON array of strings.
     */
    public function store( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $service_id  = (int) $req->param('id');
        $label       = sanitize_text_field( $req->input('label','') );

        // Accept 'field_key' (builder SPA) or 'key' (legacy)
        $key_raw     = $req->input('field_key') ?? $req->input('key','');
        $key         = sanitize_key( str_replace('-', '_', trim($key_raw)) );

        // Accept 'field_type' (builder SPA) or 'type' (legacy)
        $type_raw    = $req->input('field_type') ?? $req->input('type','text');
        $type        = sanitize_key( $type_raw );
        $step        = (int) $req->input('step', 1);
        $required    = (bool) $req->input('required', false);
        $placeholder = sanitize_text_field( $req->input('placeholder','') );
        $help_text   = sanitize_textarea_field( $req->input('help_text','') );
        $is_active   = (bool) $req->input('is_active', true);
        $sort_order  = (int) $req->input('sort_order', 0);

        // 'dropdown' must be allowed — it's the stored type for Dropdown Data Manager
        $allowed_types = ['text','number','email','phone','textarea','select','dropdown','searchable','radio','checkbox','date','file'];
        if ( ! in_array($type, $allowed_types, true) ) $type = 'text';

        if ( ! $label || ! $key ) {
            Response::json(['error' => 'Label and key are required.'], 422); return;
        }
        // Ensure key is unique within this service
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}s2nri_form_fields WHERE service_id=%d AND field_key=%s LIMIT 1",
            $service_id, $key
        ) );
        if ($exists) { Response::json(['error' => "Field key '{$key}' already exists for this service."], 422); return; }

        // Options: JSON array for select/dropdown/searchable/radio/checkbox
        $options_raw = $req->input('options', []);
        $options_json = ( in_array($type, ['select','dropdown','searchable','radio','checkbox'], true) && is_array($options_raw) )
            ? wp_json_encode(array_map('sanitize_text_field', $options_raw))
            : null;

        // Conditions: { show_if: { field_key: value } }
        $conditions_raw  = $req->input('conditions', null);
        $conditions_json = $conditions_raw ? wp_json_encode($conditions_raw) : null;

        $result = $wpdb->insert( $wpdb->prefix . 's2nri_form_fields', [
            'service_id'  => $service_id,
            'field_key'   => $key,
            'label'       => $label,
            'field_type'  => $type,
            'step'        => $step,
            'required'    => $required ? 1 : 0,
            'placeholder' => $placeholder,
            'help_text'   => $help_text,
            'options'     => $options_json,
            'conditions'  => $conditions_json,
            'is_active'   => $is_active ? 1 : 0,
            'sort_order'  => $sort_order,
        ]);

        if ($result === false) {
            error_log('[S2NRI] form-field insert FAILED service_id=' . $service_id . ' err=' . $wpdb->last_error);
            Response::json(['error' => 'Failed to create field: ' . $wpdb->last_error], 500); return;
        }
        $new_id = $wpdb->insert_id;
        // Return the full inserted row so builder state can update without a separate GET
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}s2nri_form_fields WHERE id=%d LIMIT 1", $new_id
        ), ARRAY_A );
        if ( $row ) {
            $row['options']    = $row['options']    ? json_decode($row['options'],    true) : [];
            $row['conditions'] = $row['conditions'] ? json_decode($row['conditions'], true) : null;
        }
        Response::json(['success' => true, 'id' => $new_id, 'field' => $row], 201);
    }

    /**
     * PUT admin/services/{id}/form-fields/{field_id}
     * Updates all modifiable field properties.
     */
    public function update( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $service_id = (int) $req->param('id');
        $field_id   = (int) $req->param('field_id');

        // Verify field belongs to this service
        $field = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}s2nri_form_fields WHERE id=%d AND service_id=%d LIMIT 1",
            $field_id, $service_id
        ), ARRAY_A );
        if ( ! $field ) { Response::json(['error'=>'Field not found.'], 404); return; }

        // Bug A fix: builder sends 'field_type' as key name, not 'type'.
        // Accept both 'field_type' (builder SPA) and 'type' (legacy/API) with correct fallback.
        $type_raw    = $req->input('field_type') ?? $req->input('type');
        $type        = $type_raw !== null ? sanitize_key( $type_raw ) : $field['field_type'];
        $allowed_types = ['text','number','email','phone','textarea','select','dropdown','searchable','radio','checkbox','date','file'];
        if ( ! in_array( $type, $allowed_types, true ) ) $type = $field['field_type'];

        // Bug B fix: accept field_key updates.
        // Validate it's a non-empty slug-safe string, no spaces, unique within service (handled by DB UNIQUE KEY).
        $field_key_raw = $req->input('field_key');
        if ( $field_key_raw !== null && trim($field_key_raw) !== '' ) {
            $new_key = sanitize_key( str_replace('-', '_', trim($field_key_raw)) );
            // Only update if changed — avoids redundant unique constraint checks
            $field_key = ( $new_key !== '' ) ? $new_key : $field['field_key'];
        } else {
            $field_key = $field['field_key'];
        }

        $label       = sanitize_text_field( $req->input('label') ?? $field['label'] );
        $step        = (int) ( $req->input('step') ?? $field['step'] );
        $required    = (int) (bool) ( $req->input('required') ?? $field['required'] );
        $placeholder = sanitize_text_field( $req->input('placeholder') ?? ($field['placeholder'] ?? '') );
        $help_text   = sanitize_textarea_field( $req->input('help_text') ?? ($field['help_text'] ?? '') );
        $is_active   = (int) (bool) ( $req->input('is_active') ?? $field['is_active'] );
        $sort_order  = (int) ( $req->input('sort_order') ?? $field['sort_order'] );

        $options_raw = $req->input('options');
        $options_json = $options_raw !== null
            ? wp_json_encode(array_map('sanitize_text_field', (array) $options_raw))
            : $field['options'];

        $conditions_raw  = $req->input('conditions');
        $conditions_json = $conditions_raw !== null
            ? wp_json_encode($conditions_raw)
            : $field['conditions'];

        $result = $wpdb->update( $wpdb->prefix . 's2nri_form_fields', [
            'field_key'   => $field_key,
            'label'       => $label,
            'field_type'  => $type,
            'step'        => $step,
            'required'    => $required,
            'placeholder' => $placeholder,
            'help_text'   => $help_text,
            'options'     => $options_json,
            'conditions'  => $conditions_json,
            'is_active'   => $is_active,
            'sort_order'  => $sort_order,
        ], ['id' => $field_id] );

        if ( $result === false ) {
            error_log( '[S2NRI] form-field update FAILED id=' . $field_id . ' err=' . $wpdb->last_error );
            Response::json(['error' => 'Database error: ' . $wpdb->last_error], 500);
            return;
        }

        // Return the updated row so the builder can refresh without a separate GET
        $updated = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}s2nri_form_fields WHERE id=%d LIMIT 1", $field_id
        ), ARRAY_A );
        if ( $updated ) {
            $updated['options']    = $updated['options']    ? json_decode($updated['options'],    true) : [];
            $updated['conditions'] = $updated['conditions'] ? json_decode($updated['conditions'], true) : null;
        }

        Response::json(['success' => true, 'field' => $updated]);
    }

    /**
     * PATCH admin/services/{id}/form-fields/reorder
     * M-26: Drag-to-reorder — updates sort_order for multiple fields atomically.
     * Body: { order: [{id:1,sort_order:0},{id:2,sort_order:1},...] }
     *
     * TRACE: requireManager → validate order array →
     *        iterate and UPDATE each field's sort_order WHERE service_id matches →
     *        return success.
     * EDGE CASES: field IDs not belonging to service → ignored (WHERE service_id guard).
     */
    public function reorder( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $service_id = (int) $req->param('id');
        $order      = $req->input('order', []);

        if ( ! is_array($order) || empty($order) ) {
            Response::json(['error' => 'Order array required.'], 422); return;
        }

        foreach ($order as $item) {
            if ( ! isset($item['id'], $item['sort_order']) ) continue;
            // FIXED: previously only persisted sort_order, completely
            // ignoring $item['step'] even though the frontend explicitly
            // sends it (FormBuilder/index.jsx sends {id, sort_order, step}
            // when a field is dragged to a different form step — confirmed
            // 'step' is a real column, Installer.php:550). Dragging a
            // field to a different step previously appeared to work (the
            // optimistic UI update moved it visually) but silently
            // reverted on reload, since the backend never saved the step
            // reassignment — only the in-step ordering.
            $update = [ 'sort_order' => (int) $item['sort_order'] ];
            if ( isset( $item['step'] ) ) {
                $update['step'] = (int) $item['step'];
            }
            if ( $wpdb->update(
                $wpdb->prefix . 's2nri_form_fields',
                $update,
                ['id' => (int) $item['id'], 'service_id' => $service_id]
            ) === false ) {
                error_log( '[S2NRI] form-field reorder failed for id=' . (int) $item['id'] . ': ' . $wpdb->last_error );
            }
        }
        Response::json(['success' => true]);
    }

    /**
     * DELETE admin/services/{id}/form-fields/{field_id}
     */
    public function destroy( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $service_id = (int) $req->param('id');
        $field_id   = (int) $req->param('field_id');
        if ( $wpdb->delete($wpdb->prefix . 's2nri_form_fields', ['id' => $field_id, 'service_id' => $service_id]) === false ) {
            Response::json(['error' => 'Failed to delete field.'], 500); return;
        }
        Response::json(['success' => true]);
    }

    /**
     * PATCH admin/services/{id}/form-fields/{field_id}/toggle
     * Toggle is_active
     */
    public function toggle( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $service_id = (int) $req->param('id');
        $field_id   = (int) $req->param('field_id');
        $current    = $wpdb->get_var( $wpdb->prepare(
            "SELECT is_active FROM {$wpdb->prefix}s2nri_form_fields WHERE id=%d AND service_id=%d",
            $field_id, $service_id
        ) );
        // FIXED: $current is looked up scoped to BOTH id and service_id
        // (returns null if the field belongs to a different service), but
        // the UPDATE below previously filtered by id ONLY — so a
        // cross-service field_id would still get toggled (to is_active=1,
        // since null is falsy) even though the SELECT correctly found
        // nothing for this service. Explicitly reject that case, and scope
        // the UPDATE by service_id too, matching destroy()'s pattern.
        if ( $current === null ) { Response::json(['error' => 'Field not found.'], 404); return; }
        if ( $wpdb->update($wpdb->prefix . 's2nri_form_fields', ['is_active' => $current ? 0 : 1], ['id' => $field_id, 'service_id' => $service_id]) === false ) {
            Response::json(['error' => 'Failed to toggle field.'], 500); return;
        }
        Response::json(['success' => true, 'is_active' => ! $current]);
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// DropdownDataController
// M-27: Dropdown data manager — CRUD for dropdown option values
// M-28: Bulk import — CSV or paste import for dropdown options
// ══════════════════════════════════════════════════════════════════════════════

class DropdownDataController extends BaseController {

    /**
     * GET admin/form-fields/{field_id}/options
     * Returns all options for a dropdown/searchable/radio/checkbox field.
     * Supports search + pagination.
     */
    public function index( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $field_id = (int) $req->param('field_id');
        $search   = sanitize_text_field( $req->query('search','') );
        [ 'page' => $page, 'per_page' => $per_page ] = $this->paginate($req, 100);
        $offset = $this->offset($page, $per_page);

        $where = $wpdb->prepare("WHERE field_id=%d", $field_id);
        $args  = [];
        if ($search) {
            $like   = '%' . $wpdb->esc_like($search) . '%';
            $where .= ' AND option_value LIKE %s';
            $args[] = $like;
        }

        $total_sql = "SELECT COUNT(*) FROM {$wpdb->prefix}s2nri_field_options {$where}";
        $total = (int) ( $args ? $wpdb->get_var($wpdb->prepare($total_sql,...$args)) : $wpdb->get_var($total_sql) );

        $rows_sql = "SELECT * FROM {$wpdb->prefix}s2nri_field_options {$where} ORDER BY sort_order ASC, id ASC LIMIT %d OFFSET %d";
        $all_args = array_merge($args, [$per_page, $offset]);
        $rows = $args
            ? $wpdb->get_results($wpdb->prepare($rows_sql,...$all_args), ARRAY_A)
            : $wpdb->get_results($wpdb->prepare($rows_sql, $per_page, $offset), ARRAY_A);

        Response::json(['options' => $rows ?: [], 'total' => $total, 'page' => $page, 'per_page' => $per_page]);
    }

    /**
     * POST admin/form-fields/{field_id}/options
     * Body: { value }
     */
    public function store( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $field_id = (int) $req->param('field_id');
        $value    = sanitize_text_field( $req->input('value','') );
        if ( ! $value ) { Response::json(['error'=>'Value required.'], 422); return; }
        $sort_order = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(MAX(sort_order),0)+1 FROM {$wpdb->prefix}s2nri_field_options WHERE field_id=%d", $field_id
        ) );
        $fo_insert = $wpdb->insert($wpdb->prefix . 's2nri_field_options', ['field_id'=>$field_id,'option_value'=>$value,'sort_order'=>$sort_order]);
        // CHECKED (was previously unchecked): insert_id does not reset to 0
        // on failure — it holds the last successful AUTO_INCREMENT from
        // ANY table earlier in this request. Previously this could return
        // success:true with a stale, unrelated id.
        if ( $fo_insert === false ) { Response::json(['error'=>'Failed to add option.'],500); return; }
        Response::json(['success'=>true,'id'=>$wpdb->insert_id]);
    }

    /**
     * PUT admin/form-fields/{field_id}/options/{option_id}
     */
    public function update( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $field_id  = (int) $req->param('field_id');
        $option_id = (int) $req->param('option_id');
        $value     = sanitize_text_field( $req->input('value','') );
        if ( ! $value ) { Response::json(['error'=>'Value required.'], 422); return; }
        if ( $wpdb->update($wpdb->prefix . 's2nri_field_options', ['option_value'=>$value], ['id'=>$option_id,'field_id'=>$field_id]) === false ) {
            Response::json(['error'=>'Failed to update option.'],500); return;
        }
        Response::json(['success'=>true]);
    }

    /**
     * DELETE admin/form-fields/{field_id}/options/{option_id}
     */
    public function destroy( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $field_id  = (int) $req->param('field_id');
        $option_id = (int) $req->param('option_id');
        if ( $wpdb->delete($wpdb->prefix . 's2nri_field_options', ['id'=>$option_id,'field_id'=>$field_id]) === false ) {
            Response::json(['error'=>'Failed to delete option.'],500); return;
        }
        Response::json(['success'=>true]);
    }

    /**
     * POST admin/form-fields/{field_id}/options/bulk-delete
     * Body: { ids: [1,2,3] }
     */
    public function bulkDelete( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $field_id = (int) $req->param('field_id');
        $ids      = array_map('intval', (array) $req->input('ids',[]));
        if ( empty($ids) ) { Response::json(['error'=>'No IDs provided.'],422); return; }
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $bd_result = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}s2nri_field_options WHERE field_id=%d AND id IN ({$placeholders})",
            $field_id, ...$ids
        ));
        if ( $bd_result === false ) { Response::json(['error'=>'Failed to delete options.'],500); return; }
        Response::json(['success'=>true]);
    }

    /**
     * POST admin/form-fields/{field_id}/options/bulk-import
     * M-28: Bulk import from CSV text or newline-separated list.
     * Body: { values: "line1\nline2\nline3", mode: 'append'|'replace' }
     *   OR { values: ["a","b","c"], mode: 'append'|'replace' }
     *
     * TRACE: requireManager → parse values (string → split by \n or , or JSON array) →
     *        if mode=replace: DELETE existing options for field_id →
     *        INSERT each value with incrementing sort_order →
     *        return count inserted.
     * EDGE CASES: empty values after parse → 422.
     *             Duplicate values: INSERT IGNORE (skip duplicates).
     *             Values > 500 → capped at 500.
     */
    public function bulkImport( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $field_id   = (int) $req->param('field_id');
        $raw_values = $req->input('values','');
        $mode       = sanitize_key( $req->input('mode','append') );

        // Parse values: accept string (newline/comma separated) or array
        $values = [];
        if ( is_array($raw_values) ) {
            $values = $raw_values;
        } elseif ( is_string($raw_values) ) {
            // Try JSON first
            $decoded = json_decode($raw_values, true);
            if ( is_array($decoded) ) {
                $values = $decoded;
            } else {
                // Split by newline or comma
                $separator = str_contains($raw_values, "\n") ? "\n" : ",";
                $values    = explode($separator, $raw_values);
            }
        }

        // Clean values
        $values = array_unique(
            array_filter(
                array_map(fn($v) => sanitize_text_field(trim((string)$v)), $values),
                fn($v) => $v !== ''
            )
        );

        if ( empty($values) ) { Response::json(['error'=>'No valid values found after parsing.'],422); return; }
        if ( count($values) > 500 ) $values = array_slice($values, 0, 500);

        if ( $mode === 'replace' ) {
            if ( $wpdb->delete($wpdb->prefix . 's2nri_field_options', ['field_id' => $field_id]) === false ) {
                Response::json(['error'=>'Failed to clear existing options before replacing.'],500); return;
            }
            $sort = 0;
        } else {
            $sort = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(MAX(sort_order),0)+1 FROM {$wpdb->prefix}s2nri_field_options WHERE field_id=%d", $field_id
            ) );
        }

        $inserted = 0;
        foreach ($values as $val) {
            $result = $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->prefix}s2nri_field_options (field_id, option_value, sort_order) VALUES (%d, %s, %d)",
                $field_id, $val, $sort++
            ) );
            if ($result) $inserted++;
        }

        Response::json(['success'=>true,'inserted'=>$inserted,'total_submitted'=>count($values)]);
    }

    /**
     * GET admin/form-fields/{field_id}/options/search?q=mum
     * M-25: Searchable dropdown — live search for the public booking form.
     * Returns matching options for a searchable field type.
     */
    public function search( Request $req ): void {
        global $wpdb;
        $field_id = (int) $req->param('field_id');
        $q        = sanitize_text_field( $req->query('q','') );

        // Verify field is of type searchable (security: don't expose all field data)
        $type = $wpdb->get_var( $wpdb->prepare(
            "SELECT field_type FROM {$wpdb->prefix}s2nri_form_fields WHERE id=%d LIMIT 1", $field_id
        ) );
        if ( $type !== 'searchable' ) { Response::json(['options'=>[]]); return; }

        if ( $q === '' ) {
            // Return first 50 options when no query
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, option_value FROM {$wpdb->prefix}s2nri_field_options WHERE field_id=%d ORDER BY sort_order ASC LIMIT 50",
                $field_id
            ), ARRAY_A );
        } else {
            $like = '%' . $wpdb->esc_like($q) . '%';
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, option_value FROM {$wpdb->prefix}s2nri_field_options WHERE field_id=%d AND option_value LIKE %s ORDER BY sort_order ASC LIMIT 30",
                $field_id, $like
            ), ARRAY_A );
        }

        Response::json(['options' => array_column($rows ?: [], 'option_value')]);
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// StandaloneQuoteController
// M-19: Standalone quote widget shortcode backend
// ══════════════════════════════════════════════════════════════════════════════

class StandaloneQuoteController extends BaseController {

    /**
     * POST public/quote-request
     * No auth required. Accepts a quick quote form submission.
     * Body: { name, email, phone, service_id, country, message }
     *
     * Creates a booking with status='submitted' and auto-registers the user.
     * Returns the booking_ref so the customer can track it.
     *
     * TRACE: validate required fields → get/create WP user by email →
     *        ensure customer profile → INSERT booking (field_data=submitted JSON) →
     *        send confirmation email using application_received template →
     *        return { booking_ref, message }.
     * EDGE CASES: existing email → link to existing account without overwriting password.
     *             Missing service_id → use first active service as default.
     */
    public function submit( Request $req ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $name       = sanitize_text_field( $req->input('name','') );
        $email      = sanitize_email( $req->input('email','') );
        $phone      = sanitize_text_field( $req->input('phone','') );
        $service_id = (int) $req->input('service_id', 0);
        $country    = sanitize_text_field( $req->input('country','') );
        $message    = sanitize_textarea_field( $req->input('message','') );

        if ( ! $name || ! is_email($email) ) {
            Response::json(['error' => 'Name and valid email are required.'], 422); return;
        }

        // Validate service
        $service = $service_id
            ? $wpdb->get_row($wpdb->prepare("SELECT id, category_id, name FROM {$p}s2nri_services WHERE id=%d AND is_active=1 LIMIT 1", $service_id), ARRAY_A)
            : $wpdb->get_row("SELECT id, category_id, name FROM {$p}s2nri_services WHERE is_active=1 ORDER BY sort_order ASC LIMIT 1", ARRAY_A);

        if ( ! $service ) {
            Response::json(['error' => 'Service not found or not available.'], 422); return;
        }

        // Get or create WP user
        $wp_user = get_user_by('email', $email);
        if ( ! $wp_user ) {
            $user_id = wp_create_user($email, wp_generate_password(16), $email);
            if ( is_wp_error($user_id) ) {
                Response::json(['error' => 'Account creation failed.'], 500); return;
            }
            $wp_user = get_user_by('id', $user_id);
            $wp_user->set_role('s2nri_customer');
            wp_update_user(['ID' => $user_id, 'display_name' => $name]);
        }

        // Ensure customer profile
        \S2NRI\Models\User::ensureCustomerProfile($wp_user->ID);

        $customer = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$p}s2nri_customers WHERE wp_user_id=%d LIMIT 1", $wp_user->ID
        ), ARRAY_A);

        if ( ! $customer ) {
            Response::json(['error' => 'Profile setup failed.'], 500); return;
        }

        // Generate ref
        $year     = (int) date('Y');
        $c_table  = $p . 's2nri_ref_counter';
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$c_table} (year, last_seq) VALUES (%d,1) ON DUPLICATE KEY UPDATE last_seq=last_seq+1", $year
        ));
        $seq = (int) $wpdb->get_var($wpdb->prepare("SELECT last_seq FROM {$c_table} WHERE year=%d", $year));
        $ref = 'NRI-' . $year . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);

        $field_data = wp_json_encode([
            '__name'     => $name,
            '__email'    => $email,
            '__phone'    => $phone,
            '__country'  => $country,
            'message'    => $message,
        ]);

        $booking_insert = $wpdb->insert($p . 's2nri_bookings', [
            'booking_ref' => $ref,
            'customer_id' => $customer['id'],
            'service_id'  => $service['id'],
            'category_id' => $service['category_id'],
            'status'      => 'submitted',
            'field_data'  => $field_data,
        ]);

        // FIXED: was previously only checking `!$booking_id` (a falsy
        // check on insert_id) rather than the insert() call's own return
        // value. insert_id does not reset to 0 on failure — it holds the
        // last successful AUTO_INCREMENT from ANY table earlier in the
        // request, and there IS a preceding insert just above (the
        // ref_counter table) that made a stale-id collision genuinely
        // plausible here, not just theoretical.
        if ( $booking_insert === false ) {
            error_log( '[S2NRI] StandaloneQuoteController booking insert failed: ' . $wpdb->last_error );
            Response::json(['error' => 'Booking creation failed.'], 500); return;
        }
        $booking_id = $wpdb->insert_id;

        // Send confirmation email
        $site_name  = \S2NRI\Models\Setting::get('platform_name', 'Services2NRI');
        $portal_url = home_url('/dashboard/bookings/' . $booking_id);
        $vars = [
            '{CUSTOMER_NAME}'  => $name,
            '{BOOKING_REF}'    => $ref,
            '{SERVICE_NAME}'   => $service['name'],
            '{SUBMITTED_DATE}' => date('d M Y'),
            '{PLATFORM_NAME}'  => $site_name,
            '{DASHBOARD_URL}'  => $portal_url,
        ];
        $tmpl = $wpdb->get_row("SELECT subject, body FROM {$p}s2nri_email_templates WHERE slug='application_received' LIMIT 1", ARRAY_A);
        if ($tmpl) {
            $subj = str_replace(array_keys($vars), array_values($vars), $tmpl['subject']);
            $body = str_replace(array_keys($vars), array_values($vars), $tmpl['body']);
            // FIXED: same remove_filter closure-identity bug fixed
            // elsewhere this session — a fresh fn() => 'text/html' is not
            // the same object as the one passed to add_filter(), so
            // removal previously silently failed every time.
            $html_filter_sq = fn() => 'text/html';
            add_filter('wp_mail_content_type', $html_filter_sq);
            wp_mail($email, $subj, $body, ['Content-Type: text/html; charset=UTF-8']);
            remove_filter('wp_mail_content_type', $html_filter_sq);
        }

        // Notify admins
        \S2NRI\Services\NotificationService::notifyAdmins('new_booking', [
            'title'   => "New Quote Request: {$ref}",
            'body'    => "{$name} submitted a quote request for {$service['name']}.",
            'booking_id' => $booking_id,
        ]);

        // ── AUTO-LOGIN: log the user in immediately in this session ───────────────
        // wp_set_auth_cookie() sets the WP auth cookie so the user is logged in.
        // remember=true keeps them logged in for 14 days (standard WP behaviour).
        $is_new_user = ! get_user_meta( $wp_user->ID, 's2nri_password_set', true );
        wp_set_auth_cookie( $wp_user->ID, true );
        wp_set_current_user( $wp_user->ID );

        // Generate a 64-char session token for the React portal (same mechanism as admin notice)
        $session_token = bin2hex( random_bytes( 32 ) );
        update_user_meta( $wp_user->ID, 's2nri_portal_token',     $session_token );
        update_user_meta( $wp_user->ID, 's2nri_portal_token_exp', time() + ( 14 * DAY_IN_SECONDS ) );

        // ── SET-PASSWORD EMAIL: send password setup link to new accounts ──────────
        if ( $is_new_user ) {
            // Mark that we've sent the setup email so we don't re-send on repeat submissions
            update_user_meta( $wp_user->ID, 's2nri_password_set', '1' );

            $reset_key  = get_password_reset_key( $wp_user );
            if ( ! is_wp_error( $reset_key ) ) {
                $reset_url  = network_site_url( "wp-login.php?action=rp&key={$reset_key}&login=" . rawurlencode( $wp_user->user_login ), 'login' );
                $pw_vars    = [
                    '{CUSTOMER_NAME}'  => $name,
                    '{PLATFORM_NAME}'  => $site_name,
                    '{RESET_URL}'      => $reset_url,
                    '{DASHBOARD_URL}'  => $portal_url,
                ];
                // Use email template if available, otherwise send a sensible default
                $pw_tmpl = $wpdb->get_row( "SELECT subject, body FROM {$p}s2nri_email_templates WHERE slug='set_password' LIMIT 1", ARRAY_A );
                if ( $pw_tmpl ) {
                    $pw_subj = str_replace( array_keys( $pw_vars ), array_values( $pw_vars ), $pw_tmpl['subject'] );
                    $pw_body = str_replace( array_keys( $pw_vars ), array_values( $pw_vars ), $pw_tmpl['body'] );
                } else {
                    $pw_subj = "Set your {$site_name} account password";
                    $pw_body = "<p>Hi {$name},</p>"
                             . "<p>Your account has been created on {$site_name}. Click the button below to set your password:</p>"
                             . "<p><a href='{$reset_url}' style='background:#4A6FA5;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:700;display:inline-block;'>Set My Password</a></p>"
                             . "<p>This link expires in 24 hours. You can also log in using an OTP sent to this email at any time.</p>"
                             . "<p>View your dashboard: <a href='{$portal_url}'>{$portal_url}</a></p>";
                }
                // FIXED: closure-identity remove_filter bug.
                $html_filter_pw = fn() => 'text/html';
                add_filter( 'wp_mail_content_type', $html_filter_pw );
                wp_mail( $email, $pw_subj, $pw_body, [ 'Content-Type: text/html; charset=UTF-8' ] );
                remove_filter( 'wp_mail_content_type', $html_filter_pw );
            }
        }

        // Portal redirect URL with token for React SPA
        $portal_login_url = add_query_arg(
            [ 's2nri_token' => $session_token ],
            home_url( '/portal/bookings/' . $booking_id )
        );

        Response::json([
            'success'       => true,
            'booking_ref'   => $ref,
            'booking_id'    => $booking_id,
            'message'       => "Thank you, {$name}! Your request {$ref} has been received. We'll get back to you within 1-2 business days.",
            'tracking_url'  => $portal_url,
            'portal_url'    => $portal_login_url,
            'session_token' => $session_token,
            'is_new_user'   => $is_new_user,
        ]);
    }

    /**
     * GET public/services-list
     * Returns active services for the standalone quote widget dropdown.
     */
    public function servicesList( Request $req ): void {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT s.id, s.name, s.slug, c.name AS category_name, c.icon AS category_icon
             FROM {$wpdb->prefix}s2nri_services s
             JOIN {$wpdb->prefix}s2nri_categories c ON c.id=s.category_id
             WHERE s.is_active=1 ORDER BY c.sort_order ASC, s.sort_order ASC LIMIT 200",
            ARRAY_A
        );
        Response::json(['services' => $rows ?: []]);
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// BookingPhase2AdminController
// M-20: Ticket search, M-21: Currently assigned label, UX enhancements
// ══════════════════════════════════════════════════════════════════════════════

class BookingPhase2AdminController extends BaseController {

    /**
     * GET admin/bookings/{id}/assigned-info
     * M-21: Returns the currently assigned manager and vendor details.
     * Used to show "Currently assigned: [Name]" labels without refetching the whole booking.
     */
    public function assignedInfo( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $p  = $wpdb->prefix;
        $id = (int) $req->param('id');

        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT b.assigned_to, b.vendor_id,
                    COALESCE(u.display_name, u.user_login, '') AS manager_name,
                    u.user_email AS manager_email,
                    NULL AS manager_phone,
                    v.name AS vendor_name, v.phone AS vendor_phone, v.whatsapp AS vendor_whatsapp
             FROM {$p}s2nri_bookings b
             LEFT JOIN {$p}users u ON u.ID=b.assigned_to
             LEFT JOIN {$p}s2nri_staff st ON st.wp_user_id=b.assigned_to
             LEFT JOIN {$p}s2nri_vendors v ON v.id=b.vendor_id
             WHERE b.id=%d LIMIT 1",
            $id
        ), ARRAY_A );

        if ( ! $booking ) { Response::json(['error'=>'Booking not found.'], 404); return; }

        Response::json([
            'manager' => $booking['assigned_to'] ? [
                'id'    => (int) $booking['assigned_to'],
                'name'  => $booking['manager_name'],
                'email' => $booking['manager_email'],
                'phone' => $booking['manager_phone'],
            ] : null,
            'vendor' => $booking['vendor_id'] ? [
                'id'       => (int) $booking['vendor_id'],
                'name'     => $booking['vendor_name'],
                'phone'    => $booking['vendor_phone'],
                'whatsapp' => $booking['vendor_whatsapp'],
            ] : null,
        ]);
    }
}

