<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailerException;

/**
 * BOS_Email — SMTP email wrapper using PHPMailer.
 *
 * DESIGN: Email is always optional. Every send call returns a result array
 * {success, error}. Callers check the result but the app never stops working
 * if SMTP is not configured or temporarily unreachable.
 *
 * Configuration is stored in bos_settings:
 *   smtp_enabled     = '0' or '1'
 *   smtp_host        = 'smtp.gmail.com'
 *   smtp_port        = '587'
 *   smtp_encryption  = 'tls' | 'ssl' | 'none'
 *   smtp_username    = 'user@gmail.com'
 *   smtp_password    = 'app_password'
 *   smtp_from_name   = 'My Business'
 *   smtp_from_email  = 'invoices@mybusiness.com'
 */
class BOS_Email {

    public static function is_configured(): bool {
        return BOS_DB::get_setting('smtp_enabled') === '1'
            && BOS_DB::get_setting('smtp_host') !== ''
            && BOS_DB::get_setting('smtp_username') !== '';
    }

    /**
     * Send an email.
     * Returns ['success' => true] or ['success' => false, 'error' => '...']
     */
    public static function send(
        string $to,
        string $to_name,
        string $subject,
        string $html_body,
        string $plain_body = '',
        array  $attachments = []  // [['path' => '/tmp/file.pdf', 'name' => 'invoice.pdf'], ...]
    ): array {
        if (!self::is_configured()) {
            return [
                'success' => false,
                'error'   => 'SMTP is not configured. Go to Settings > Email to set it up.',
            ];
        }

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host     = BOS_DB::get_setting('smtp_host');
            $mail->Port     = (int) BOS_DB::get_setting('smtp_port', '587');
            $mail->SMTPAuth = true;
            $mail->Username = BOS_DB::get_setting('smtp_username');
            $mail->Password = BOS_DB::get_setting('smtp_password');

            $enc = BOS_DB::get_setting('smtp_encryption', 'tls');
            $mail->SMTPSecure = match($enc) {
                'ssl'  => PHPMailer::ENCRYPTION_SMTPS,
                'none' => '',
                default => PHPMailer::ENCRYPTION_STARTTLS,
            };

            $from_name  = BOS_DB::get_setting('smtp_from_name', 'Business OS');
            $from_email = BOS_DB::get_setting('smtp_from_email')
                       ?: BOS_DB::get_setting('smtp_username');

            $mail->setFrom($from_email, $from_name);
            $mail->addAddress($to, $to_name);
            $mail->isHTML(true);
            $mail->CharSet = 'UTF-8';
            $mail->Subject = $subject;
            $mail->Body    = $html_body;
            $mail->AltBody = $plain_body ?: strip_tags($html_body);
            $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]];
            $mail->Timeout = 15; // 15 second timeout — app never hangs

            foreach ($attachments as $att) {
                if (isset($att['path']) && file_exists($att['path'])) {
                    $mail->addAttachment($att['path'], $att['name'] ?? basename($att['path']));
                }
            }

            $mail->send();
            return ['success' => true];

        } catch (MailerException $e) {
            error_log('[BOS_Email] Send failed: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        } catch (Throwable $e) {
            error_log('[BOS_Email] Unexpected error: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Unexpected error sending email.'];
        }
    }

    /** Test SMTP connection — used from settings page */
    public static function test(array $cfg): array {
        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = $cfg['host'];
            $mail->Port       = (int)($cfg['port'] ?? 587);
            $mail->SMTPAuth   = true;
            $mail->Username   = $cfg['username'];
            $mail->Password   = $cfg['password'];
            $mail->SMTPSecure = match($cfg['encryption'] ?? 'tls') {
                'ssl'  => PHPMailer::ENCRYPTION_SMTPS,
                'none' => '',
                default => PHPMailer::ENCRYPTION_STARTTLS,
            };
            $mail->SMTPOptions = ['ssl' => ['verify_peer' => false]];
            $mail->Timeout = 10;
            $mail->SMTPDebug  = 0;
            $result = $mail->smtpConnect();
            $mail->smtpClose();
            return $result ? ['success' => true] : ['success' => false, 'error' => 'Connection failed'];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /** Build a simple invoice email body */
    public static function invoice_html(array $invoice, array $settings): string {
        $biz  = $settings['business_name'] ?? 'Business OS';
        $sym  = $settings['currency_symbol'] ?? '₹';
        $num  = $invoice['invoice_number'] ?? '';
        $amt  = number_format((float)($invoice['grand_total'] ?? 0), 2);
        $due  = $invoice['due_date'] ?? '';
        $client = $invoice['client_name'] ?? '';

        return <<<HTML
        <div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:24px">
          <h2 style="color:#1e3a5f">{$biz}</h2>
          <p>Dear {$client},</p>
          <p>Please find your invoice details below:</p>
          <table style="width:100%;border-collapse:collapse;margin:16px 0">
            <tr><td style="padding:8px;background:#f8fafc;font-weight:bold">Invoice Number</td><td style="padding:8px">{$num}</td></tr>
            <tr><td style="padding:8px;background:#f8fafc;font-weight:bold">Amount</td><td style="padding:8px;color:#059669;font-weight:bold">{$sym}{$amt}</td></tr>
            <tr><td style="padding:8px;background:#f8fafc;font-weight:bold">Due Date</td><td style="padding:8px">{$due}</td></tr>
          </table>
          <p>Thank you for your business.</p>
          <p style="color:#64748b;font-size:12px">This email was sent from {$biz} via Business OS Desktop.</p>
        </div>
        HTML;
    }
}
