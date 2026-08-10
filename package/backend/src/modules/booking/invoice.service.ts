import PDFDocument from 'pdfkit';
import { pool } from '../../db/pool';
import { Errors } from '../../utils/errors';

const PLATFORM_GSTIN = process.env.PLATFORM_GSTIN || '29AABCP1234A1Z5';
const PLATFORM_NAME = process.env.PLATFORM_LEGAL_NAME || 'PORTMYSTUFF Logistics Pvt Ltd';
const PLATFORM_ADDRESS = process.env.PLATFORM_ADDRESS || 'Bengaluru, Karnataka, India';

function currentFinancialYear(): string {
  const now = new Date();
  const year = now.getFullYear();
  const month = now.getMonth() + 1;
  if (month >= 4) return `${year}-${(year + 1).toString().slice(-2)}`;
  return `${year - 1}-${year.toString().slice(-2)}`;
}

async function nextInvoiceNumber(): Promise<string> {
  const fy = currentFinancialYear();
  const result = await pool.query(
    `INSERT INTO invoice_sequences (prefix, financial_year, last_number)
     VALUES ('PMS', $1, 1)
     ON CONFLICT (prefix, financial_year) DO UPDATE SET last_number = invoice_sequences.last_number + 1
     RETURNING last_number`,
    [fy]
  );
  const num = result.rows[0].last_number as number;
  return `PMS/${fy}/${String(num).padStart(6, '0')}`;
}

function splitGst(taxAmount: number, customerState = 'KA'): { cgst: number; sgst: number; igst: number } {
  const half = taxAmount / 2;
  if (customerState === 'KA') return { cgst: half, sgst: half, igst: 0 };
  return { cgst: 0, sgst: 0, igst: taxAmount };
}

export async function generateTripInvoicePdf(bookingId: string, customerId: string): Promise<Buffer> {
  const booking = await pool.query(
    `SELECT b.id, b.fare_breakdown, b.created_at, b.status,
            u.name AS customer_name, u.phone, u.gstin, u.billing_address, u.business_name
     FROM bookings b
     JOIN users u ON u.id = b.customer_id
     WHERE b.id = $1 AND b.customer_id = $2`,
    [bookingId, customerId]
  );
  if (booking.rowCount === 0) throw Errors.notFound('Booking');
  if (booking.rows[0].status !== 'completed') {
    throw Errors.validation({ booking: 'Invoice is available only for completed trips.' });
  }

  const row = booking.rows[0];
  const fb = row.fare_breakdown as {
    base_fare: number;
    distance_charge: number;
    platform_fee: number;
    tax: number;
    final_fare: number;
    coupon_discount?: number;
    subscription_benefit?: number;
  };
  const invoiceNo = await nextInvoiceNumber();
  const gst = splitGst(fb.tax || 0);
  const taxable = fb.final_fare - (fb.tax || 0);

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

    doc.text(`Invoice No: ${invoiceNo}`);
    doc.text(`Date: ${new Date(row.created_at).toLocaleDateString('en-IN')}`);
    doc.text(`Trip ID: ${bookingId.slice(0, 8).toUpperCase()}`);
    doc.moveDown();

    doc.fontSize(11).text('Bill To:', { underline: true });
    doc.fontSize(10);
    doc.text(row.business_name || row.customer_name || 'Customer');
    if (row.gstin) doc.text(`GSTIN: ${row.gstin}`);
    if (row.billing_address) doc.text(row.billing_address);
    doc.text(`Phone: +91 ${row.phone}`);
    doc.moveDown();

    doc.fontSize(11).text('Description', 50, doc.y, { continued: true, width: 300 });
    doc.text('Amount (₹)', { align: 'right' });
    doc.moveDown(0.3);
    doc.fontSize(10);
    const lines: [string, number][] = [
      ['Base fare + distance', fb.base_fare + fb.distance_charge],
      ['Platform fee', fb.platform_fee],
    ];
    if (fb.coupon_discount) lines.push(['Coupon discount', -fb.coupon_discount]);
    if (fb.subscription_benefit) lines.push(['Membership benefit', -fb.subscription_benefit]);
    for (const [label, amt] of lines) {
      doc.text(label, 50, doc.y, { continued: true, width: 300 });
      doc.text(amt.toFixed(2), { align: 'right' });
    }
    doc.moveDown();
    doc.text(`Taxable value: ₹${taxable.toFixed(2)}`);
    if (gst.cgst > 0) {
      doc.text(`CGST (9%): ₹${gst.cgst.toFixed(2)}`);
      doc.text(`SGST (9%): ₹${gst.sgst.toFixed(2)}`);
    } else {
      doc.text(`IGST (18%): ₹${gst.igst.toFixed(2)}`);
    }
    doc.moveDown();
    doc.fontSize(12).text(`Total: ₹${fb.final_fare.toFixed(2)}`, { align: 'right' });
    doc.moveDown(2);
    doc.fontSize(8).fillColor('#666').text('SAC: 996511 — Goods transport agency services. Computer-generated invoice.', {
      align: 'center',
    });
    doc.end();
  });
}
