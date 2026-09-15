<?php

declare(strict_types=1);

namespace RTOFLOW\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use RTOFLOW\Services\GstService;

/**
 * Unit tests for GstService's pure calculation and validation logic.
 *
 * GstService::__construct() reads GST_RATE / COMPANY_STATE_CODE via Env,
 * which falls back to its documented defaults (18.0, '27') when no .env
 * file and no matching environment variables are present — which is the
 * case in this sandbox, so tests assert against those defaults rather than
 * stubbing Env.
 */
final class GstServiceTest extends TestCase
{
    private GstService $gst;

    protected function setUp(): void
    {
        $this->gst = new GstService();
    }

    public function testIntraStateSupplySplitsCgstAndSgst(): void
    {
        // Company default state code is '27' (Maharashtra); same state => intra-state.
        $result = $this->gst->calculate(1000.00, '27');

        $this->assertFalse($result['is_interstate']);
        $this->assertSame(90.00, $result['cgst']);
        $this->assertSame(90.00, $result['sgst']);
        $this->assertSame(0.00, $result['igst']);
        $this->assertSame(180.00, $result['total_gst']);
        $this->assertSame(1180.00, $result['grand_total']);
    }

    public function testInterStateSupplyUsesIgstOnly(): void
    {
        $result = $this->gst->calculate(1000.00, '29'); // Karnataka vs default '27'

        $this->assertTrue($result['is_interstate']);
        $this->assertSame(0.00, $result['cgst']);
        $this->assertSame(0.00, $result['sgst']);
        $this->assertSame(180.00, $result['igst']);
        $this->assertSame(180.00, $result['total_gst']);
        $this->assertSame(1180.00, $result['grand_total']);
    }

    public function testEmptySupplyStateDefaultsToCompanyStateAndIsIntraState(): void
    {
        $result = $this->gst->calculate(1000.00, '');

        $this->assertFalse($result['is_interstate']);
        $this->assertSame('27', $result['supply_state']);
    }

    public function testZeroOrNegativeAmountReturnsZeroResult(): void
    {
        $zero = $this->gst->calculate(0.00, '29');
        $this->assertSame(0.00, $zero['total_gst']);
        $this->assertSame(0.00, $zero['grand_total']);
        $this->assertFalse($zero['is_interstate']);

        $negative = $this->gst->calculate(-500.00, '29');
        $this->assertSame(0.00, $negative['total_gst']);
        $this->assertSame(-500.00, $negative['grand_total']);
    }

    public function testInclusiveAmountIsBackedOutBeforeCalculatingGst(): void
    {
        // 1180 inclusive at 18% => taxable 1000, total_gst 180, grand_total 1180.
        $result = $this->gst->calculate(1180.00, '27', true);

        $this->assertSame(1000.00, $result['taxable_amount']);
        $this->assertSame(180.00, $result['total_gst']);
        $this->assertSame(1180.00, $result['grand_total']);
    }

    public function testValidateGstinAcceptsWellFormedGstin(): void
    {
        $this->assertTrue(GstService::validateGstin('27AAPFU0939F1ZV'));
    }

    public function testValidateGstinNormalisesWhitespaceAndCase(): void
    {
        $this->assertTrue(GstService::validateGstin(' 27aapfu0939f1zv '));
    }

    public function testValidateGstinRejectsMalformedGstin(): void
    {
        $this->assertFalse(GstService::validateGstin('NOT-A-GSTIN'));
        $this->assertFalse(GstService::validateGstin('27AAPFU0939F1Z')); // too short
    }

    public function testStateFromGstinExtractsLeadingTwoDigits(): void
    {
        $this->assertSame('27', GstService::stateFromGstin('27AAPFU0939F1ZV'));
        $this->assertSame('29', GstService::stateFromGstin('29AAPFU0939F1ZV'));
    }

    public function testSacCodeIsFixed(): void
    {
        $this->assertSame('999799', GstService::sacCode());
    }

    public function testGetServiceDescriptionIncludesSacCode(): void
    {
        $desc = GstService::getServiceDescription('Driving Licence Renewal');
        $this->assertStringContainsString('Driving Licence Renewal', $desc);
        $this->assertStringContainsString('999799', $desc);
    }

    public function testFormatInrUsesRupeeSymbolAndTwoDecimals(): void
    {
        $this->assertSame('₹1,180.00', GstService::formatInr(1180.0));
    }

    public function testAmountInWordsHandlesWholeRupees(): void
    {
        $this->assertSame('One Hundred Rupees Only', GstService::amountInWords(100.0));
    }

    public function testAmountInWordsHandlesRupeesAndPaise(): void
    {
        $this->assertSame('One Hundred Rupees and Fifty Paise Only', GstService::amountInWords(100.50));
    }

    public function testAmountInWordsHandlesZero(): void
    {
        $this->assertSame('Zero Rupees Only', GstService::amountInWords(0.0));
    }
}
