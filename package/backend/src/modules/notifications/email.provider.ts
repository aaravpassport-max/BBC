/**
 * Email delivery — uses SMTP when configured, otherwise logs to console
 * (same dev pattern as SMS in auth.service).
 */

import nodemailer from 'nodemailer';

export function isConfigured(): boolean {
  return Boolean(process.env.SMTP_HOST && process.env.SMTP_FROM);
}

export interface EmailAttachment {
  filename: string;
  content: Buffer;
  contentType: string;
}

export async function sendEmail(params: {
  to: string;
  subject: string;
  text: string;
  html?: string;
  attachments?: EmailAttachment[];
}): Promise<{ sent: boolean; providerRef?: string }> {
  const { to, subject, text, html, attachments } = params;

  if (!isConfigured()) {
    console.log(`[DEV ONLY] Email to=${to} subject="${subject}" body="${text.slice(0, 120)}..."`);
    if (attachments?.length) {
      console.log(`[DEV ONLY] Email attachments: ${attachments.map((a) => a.filename).join(', ')}`);
    }
    return { sent: true, providerRef: `dev_email_${Date.now()}` };
  }

  const transport = nodemailer.createTransport({
    host: process.env.SMTP_HOST,
    port: parseInt(process.env.SMTP_PORT || '587', 10),
    secure: process.env.SMTP_SECURE === 'true',
    auth:
      process.env.SMTP_USER && process.env.SMTP_PASS
        ? { user: process.env.SMTP_USER, pass: process.env.SMTP_PASS }
        : undefined,
  });

  const info = await transport.sendMail({
    from: process.env.SMTP_FROM,
    to,
    subject,
    text,
    html: html ?? text.replace(/\n/g, '<br>'),
    attachments: attachments?.map((a) => ({
      filename: a.filename,
      content: a.content,
      contentType: a.contentType,
    })),
  });

  return { sent: true, providerRef: info.messageId };
}
