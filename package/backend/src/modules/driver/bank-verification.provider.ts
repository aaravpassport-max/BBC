import { randomUUID } from 'crypto';

export interface BankVerificationInput {
  accountNumber: string;
  ifsc: string;
  holderName: string;
}

export interface BankVerificationResult {
  verified: boolean;
  verification_id?: string;
  failure_reason?: string;
}

/**
 * Penny-drop style bank verification (PRD 3.2). Production would call
 * RazorpayX Fund Account validation; this reference implementation
 * validates format and simulates a successful micro-deposit match.
 */
export async function verifyBankAccount(input: BankVerificationInput): Promise<BankVerificationResult> {
  const { accountNumber, ifsc, holderName } = input;

  if (!/^[A-Z]{4}0[A-Z0-9]{6}$/.test(ifsc.toUpperCase())) {
    return { verified: false, failure_reason: 'INVALID_IFSC' };
  }
  if (!/^\d{9,18}$/.test(accountNumber.replace(/\s/g, ''))) {
    return { verified: false, failure_reason: 'INVALID_ACCOUNT' };
  }
  if (!holderName || holderName.trim().length < 2) {
    return { verified: false, failure_reason: 'INVALID_HOLDER_NAME' };
  }

  // Test hook: account ending in 0000 simulates verification failure.
  if (accountNumber.replace(/\s/g, '').endsWith('0000')) {
    return { verified: false, failure_reason: 'NAME_MISMATCH' };
  }

  return { verified: true, verification_id: `pdrop_${randomUUID().slice(0, 12)}` };
}
