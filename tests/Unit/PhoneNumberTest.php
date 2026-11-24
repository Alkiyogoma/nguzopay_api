<?php

namespace Tests\Unit;

use App\Domain\Identity\Support\PhoneNumber;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    public static function validNumbers(): array
    {
        return [
            ['0712345678', '255712345678'],
            ['712345678', '255712345678'],
            ['255712345678', '255712345678'],
            ['+255 712 345 678', '255712345678'],
            ['0612-345-678', '255612345678'],
        ];
    }

    #[DataProvider('validNumbers')]
    public function test_it_normalises_tanzanian_mobile_numbers(string $input, string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::normalize($input));
    }

    public function test_it_rejects_non_mobile_or_foreign_numbers(): void
    {
        foreach (['0222123456', '254712345678', '12345', '07123456789'] as $bad) {
            try {
                PhoneNumber::normalize($bad);
                $this->fail("Accepted {$bad}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_it_masks_numbers(): void
    {
        $this->assertSame('+255 7•• ••• 678', PhoneNumber::mask('255712345678'));
    }
}
