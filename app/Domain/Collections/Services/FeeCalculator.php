<?php

namespace App\Domain\Collections\Services;

use App\Domain\Business\Models\Business;

/**
 * Fees on a collection. The merchant absorbs both (D-015): the payer pays the
 * invoice amount, and the merchant receives amount − provider fee − NguzoPay fee.
 */
class FeeCalculator
{
    public function platformFee(Business $business, int $amount): int
    {
        $bps = $business->fee_bps ?? (int) config('nguzopay.pricing.fee_bps');
        $fixed = $business->fee_fixed ?? (int) config('nguzopay.pricing.fee_fixed');

        return (int) round($amount * $bps / 10_000) + (int) $fixed;
    }

    /** Provider fee when the provider does not report it. */
    public function estimatedProviderFee(string $provider, int $amount): int
    {
        $bps = (int) config("nguzopay.payments.{$provider}.fee_bps", 0);

        return (int) round($amount * $bps / 10_000);
    }

    /**
     * @return array{provider_fee: int, platform_fee: int, net: int}
     */
    public function split(Business $business, int $amount, int $providerFee): array
    {
        $providerFee = min($providerFee, $amount);
        $platformFee = min($this->platformFee($business, $amount), $amount - $providerFee);

        return [
            'provider_fee' => $providerFee,
            'platform_fee' => $platformFee,
            'net' => $amount - $providerFee - $platformFee,
        ];
    }
}
