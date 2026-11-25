<?php

namespace Tests\Unit;

use App\Domain\Business\Models\Business;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Ledger\Services\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class LedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_entries_must_balance_and_post_once_per_source(): void
    {
        $ledger = app(Ledger::class);
        $business = Business::factory()->create();
        $clearing = $ledger->providerClearing('mock');
        $payable = $ledger->merchantPayable($business);

        $this->expectExceptionObject(new InvalidArgumentException('Unbalanced ledger entry test: debits 100, credits 90.'));
        $ledger->post('test', $business, [[$clearing, 100, 0], [$payable, 0, 90]]);
    }

    public function test_replay_does_not_double_count_and_entries_are_append_only(): void
    {
        $ledger = app(Ledger::class);
        $business = Business::factory()->create();
        $lines = [[$ledger->providerClearing('mock'), 100, 0], [$ledger->merchantPayable($business), 0, 100]];

        $first = $ledger->post('test', $business, $lines);
        $second = $ledger->post('test', $business, $lines);

        $this->assertTrue($first->is($second));
        $this->assertSame(100, $ledger->balance($ledger->merchantPayable($business)));

        $this->expectException(LogicException::class);
        LedgerEntry::first()->update(['description' => 'edited']);
    }
}
