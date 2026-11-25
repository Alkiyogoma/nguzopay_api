<?php

namespace App\Domain\Collections\Providers;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

class ProviderRegistry
{
    private const MAP = [
        'mock' => MockProvider::class,
        'snippe' => SnippeProvider::class,
        'azampay' => AzampayProvider::class,
        'selcom' => SelcomProvider::class,
    ];

    public function __construct(private readonly Container $app) {}

    /** @return list<string> */
    public function enabled(): array
    {
        return array_values(array_filter(
            (array) config('nguzopay.payments.providers'),
            fn (string $code) => isset(self::MAP[$code]) && ! ($code === 'mock' && $this->app->isProduction()),
        ));
    }

    public function isEnabled(string $code): bool
    {
        return in_array($code, $this->enabled(), true);
    }

    public function get(string $code): CollectionProvider
    {
        if (! $this->isEnabled($code)) {
            throw new InvalidArgumentException("Payment provider [{$code}] is not enabled.");
        }

        return $this->app->make(self::MAP[$code]);
    }

    public function default(): string
    {
        $default = (string) config('nguzopay.payments.default_provider');

        return $this->isEnabled($default) ? $default : ($this->enabled()[0] ?? $default);
    }
}
