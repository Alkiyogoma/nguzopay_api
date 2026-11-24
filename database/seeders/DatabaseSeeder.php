<?php

namespace Database\Seeders;

use App\Domain\Business\Enums\BusinessType;
use App\Domain\Business\Services\BusinessRegistrar;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Seeder;

/**
 * Local demo data only. Never run in sandbox/production.
 * Passwords: "password123" (see database/factories/UserFactory.php).
 * All three have SMS two-step on: the code appears in storage/logs/laravel.log.
 */
class DatabaseSeeder extends Seeder
{
    public function run(BusinessRegistrar $registrar): void
    {
        if (app()->isProduction()) {
            return;
        }

        $owner = User::factory()->withTwoFactor('255700000001')->create(['name' => 'Demo Owner', 'email' => 'owner@nguzopay.test']);
        User::factory()->admin()->withTwoFactor('255700000011')->create(['name' => 'Admin Maker', 'email' => 'maker@nguzopay.test']);
        User::factory()->admin()->withTwoFactor('255700000012')->create(['name' => 'Admin Checker', 'email' => 'checker@nguzopay.test']);

        $registrar->register($owner, [
            'type' => BusinessType::School->value,
            'legal_name' => 'Demo Academy Ltd',
            'trading_name' => 'Demo Academy',
            'registration_authority' => 'brela',
            'registration_number' => '123456789',
            'tin' => '123456789',
            'email' => 'accounts@demo-academy.test',
            'phone' => '255700000002',
            'region' => 'Dar es Salaam',
            'physical_address' => 'Plot 1, Mikocheni',
        ]);
    }
}
