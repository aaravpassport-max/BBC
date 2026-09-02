import PDFDocument from 'pdfkit';
import { pool } from '../../db/pool';
import { Errors } from '../../utils/errors';
import { getInvoiceDetail } from './corporate.service';
import * as emailProvider from '../notifications/email.provider';

const PLATFORM_GSTIN = process.env.PLATFORM_GSTIN || '29AABCP1234A1Z5';
const PLATFORM_NAME = process.env.PLATFORM_LEGAL_NAME || 'PORTMYSTUFF Logistics Pvt Ltd';
const PLATFORM_ADDRESS = process.env.PLATFORM_ADDRESS || 'Bengaluru, Karnataka, India';

export async function generateCorporateInvoicePdf(
  accountId: string,
  invoiceId: string,
  requestingUserId: string
): Promise<Buffer> {
  const invoice = await getInvoiceDetail(accountId, invoiceId, requestingUserId);

  const account = await pool.query(`SELECT name FROM corporate_accounts WHERE id = $1`, [accountId]);
  const accountName = account.rows[0]?.name ?? 'Corporate Account';

  return new Promise((resolve, reject) => {
    const doc = new PDFDocument({ margin: 50 });
    const chunks: Buffer[] = [];
    doc.on('data', (c) => chunks.push(c as Buffer));
    doc.on('end', () => resolve(Buffer.concat(chunks)));
    doc.on('error', reject);

    doc.fontSize(18).text('TAX INVOICE', { align: 'center' });
    doc.moveDown(0.5);
    doc.fontSize(10).text(PLATFORM_NAME);
    doc.text(`GSTIN: ${PLATFORM_GSTIN}`);
    doc.text(PLATFORM_ADDRESS);
    doc.moveDown();

    doc.text(`Invoice No: ${invoice.invoice_number}`);
    doc.text(`Date: ${new Date(invoice.generated_at).toLocaleDateString('en-IN')}`);
    doc.text(
      `Period: ${new Date(invoice.period_start).toLocaleDateString('en-IN')} – ${new Date(invoice.period_end).toLocaleDateString('en-IN')}`
    );
    doc.moveDown();

    doc.fontSize(11).text('Bill To:', { underline: true });
    doc.fontSize(10).text(accountName);
    doc.moveDown();

    doc.fontSize(11).text('Trip', 50, doc.y, { continued: true, width: 200 });
    doc.text('Employee', { continued: true, width: 120 });
    doc.text('Date', { continued: true, width: 80 });
    doc.text('Amount (₹)', { align: 'right' });
    doc.moveDown(0.3);
    doc.fontSize(9);

    for (const item of invoice.lineItems) {
      const y = doc.y;
      doc.text(`#${(item.id as string).slice(0, 8).toUpperCase()}`, 50, y, { width: 200 });
      doc.text(`+91 ${item.employee_phone}`, 250, y, { width: 120 });
      doc.text(new Date(item.created_at).toLocaleDateString('en-IN'), 370, y, { width: 80 });
      doc.text(parseFloat(String((item.fare_breakdown as { final_fare: number }).final_fare)).toFixed(2), {
        align: 'right',
      });
      doc.moveDown(0.2);
    }

    doc.moveDown();
    doc.fontSize(12).text(`Total: ₹${parseFloat(String(invoice.total_amount)).toFixed(2)}`, { align: 'right' });
    doc.moveDown();
    doc.fontSize(8).fillColor('#666').text(
      `${invoice.booking_count} trip(s) · Status: ${invoice.status} · Computer-generated invoice.`,
      { align: 'center' }
    );
    doc.end();
  });
}

async function getBillingEmails(accountId: string): Promise<string[]> {
  const admins = await pool.query(
    `SELECT email FROM corporate_employees
     WHERE corporate_account_id = $1 AND role = 'account_admin' AND status = 'active'`,
    [accountId]
  );
  return admins.rows.map((r) => r.email as string);
}

export async function emailCorporateInvoice(params: {
  accountId: string;
  invoiceId: string;
  requestingUserId: string;
  toEmail?: string;
}): Promise<{ sent: boolean; recipients: string[] }> {
  const { accountId, invoiceId, requestingUserId, toEmail } = params;

  const invoice = await pool.query(
    `SELECT invoice_number, total_amount, period_start, period_end
     FROM corporate_invoices WHERE id = $1 AND corporate_account_id = $2`,
    [invoiceId, accountId]
  );
  if (invoice.rowCount === 0) throw Errors.notFound('Invoice');

  const recipients = toEmail ? [toEmail] : await getBillingEmails(accountId);
  if (recipients.length === 0) {
    throw Errors.validation({ email: 'No billing contact found for this account.' });
  }

  const pdf = await generateCorporateInvoicePdf(accountId, invoiceId, requestingUserId);
  const row = invoice.rows[0];
  const subject = `Invoice ${row.invoice_number} — PORTMYSTUFF Business`;
  const text = `Your corporate invoice ${row.invoice_number} for the period ${new Date(row.period_start).toLocaleDateString('en-IN')} to ${new Date(row.period_end).toLocaleDateString('en-IN')} is attached. Total due: ₹${parseFloat(row.total_amount).toFixed(2)}.`;

  for (const email of recipients) {
    await emailProvider.sendEmail({
      to: email,
      subject,
      text,
      attachments: [
        {
          filename: `${row.invoice_number}.pdf`,
          content: pdf,
          contentType: 'application/pdf',
        },
      ],
    });
  }

  await pool.query(`UPDATE corporate_invoices SET email_sent_at = now() WHERE id = $1`, [invoiceId]);

  return { sent: true, recipients };
}
