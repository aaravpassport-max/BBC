<?php

declare(strict_types=1);

namespace RTOFLOW\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use RTOFLOW\Security\Encryption;

/**
 * Unit tests for Encryption's encrypt()/decrypt() round-trip and related
 * helpers.
 *
 * Encryption::key() falls back to an auto-generated key stored via
 * get_option()/update_option() (stubbed in tests/bootstrap.php) when no
 * ENCRYPTION_KEY env var/.env value is present, which is the case here —
 * so a key is generated once and reused for the whole test run via
 * Encryption's own static cache.
 */
final class EncryptionTest extends TestCase
{
    public function testEncryptDecryptRoundTrip(): void
    {
        $plaintext = 'Aadhaar: 1234 5678 9012';
        $cipher    = Encryption::encrypt($plaintext);

        $this->assertNotSame($plaintext, $cipher);
        $this->assertSame($plaintext, Encryption::decrypt($cipher));
    }

    public function testEncryptEmptyStringReturnsEmptyString(): void
    {
        $this->assertSame('', Encryption::encrypt(''));
        $this->assertSame('', Encryption::decrypt(''));
    }

    public function testCiphertextIsNotDeterministic(): void
    {
        // Random IV per call means two encryptions of the same plaintext differ.
        $a = Encryption::encrypt('same value');
        $b = Encryption::encrypt('same value');

        $this->assertNotSame($a, $b);
        $this->assertSame('same value', Encryption::decrypt($a));
        $this->assertSame('same value', Encryption::decrypt($b));
    }

    public function testDecryptRejectsInvalidBase64(): void
    {
        $this->expectException(\RuntimeException::class);
        Encryption::decrypt('not-valid-base64===***');
    }

    public function testDecryptRejectsTamperedCiphertext(): void
    {
        $cipher = Encryption::encrypt('sensitive data');
        $raw    = base64_decode($cipher, true);
        // Flip a byte in the middle of the payload (past the version+iv+tag header).
        $raw[20] = chr(ord($raw[20]) ^ 0xFF);
        $tampered = base64_encode($raw);

        $this->expectException(\RuntimeException::class);
        Encryption::decrypt($tampered);
    }

    public function testDecryptSafeReturnsEmptyStringOnFailureInsteadOfThrowing(): void
    {
        $this->assertSame('', Encryption::decryptSafe('not-valid-base64===***'));
        $this->assertSame('', Encryption::decryptSafe(''));
    }

    public function testHmacRoundTripVerifies(): void
    {
        $hash = Encryption::hmac('some data');
        $this->assertTrue(Encryption::verifyHmac('some data', $hash));
        $this->assertFalse(Encryption::verifyHmac('other data', $hash));
    }

    public function testSignedTokenRoundTrip(): void
    {
        $token = Encryption::signedToken('lead-id-123', 3600);
        $this->assertSame('lead-id-123', Encryption::verifySignedToken($token));
    }

    public function testSignedTokenExpiredReturnsNull(): void
    {
        $token = Encryption::signedToken('lead-id-123', -1); // already expired
        $this->assertNull(Encryption::verifySignedToken($token));
    }

    public function testVerifySignedTokenRejectsGarbage(): void
    {
        $this->assertNull(Encryption::verifySignedToken('not-a-real-token'));
    }

    public function testMaskAadhaarKeepsLastFourDigits(): void
    {
        $this->assertSame('XXXX-XXXX-9012', Encryption::maskAadhaar('1234 5678 9012'));
    }

    public function testMaskAadhaarFallsBackWhenNotTwelveDigits(): void
    {
        $this->assertSame('XXXX-XXXX-XXXX', Encryption::maskAadhaar('12345'));
    }

    public function testMaskPanKeepsLastFourCharacters(): void
    {
        $this->assertSame('XXXXXX939F', Encryption::maskPan('AAPFU0939F'));
    }

    public function testMaskPanFallsBackWhenInvalidFormat(): void
    {
        $this->assertSame('XXXXXXXXXX', Encryption::maskPan('invalid-pan'));
    }

    public function testMaskMobileKeepsLastFourDigits(): void
    {
        $this->assertSame('XXXXXX7890', Encryption::maskMobile('9876547890'));
    }

    public function testMaskEmailKeepsFirstTwoCharsOfLocalPart(): void
    {
        $this->assertSame('jo**@example.com', Encryption::maskEmail('john@example.com'));
    }

    public function testMaskEmailFallsBackForMalformedEmail(): void
    {
        $this->assertSame('****@****.***', Encryption::maskEmail('not-an-email'));
    }
}
