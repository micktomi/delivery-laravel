<?php

namespace Tests;

use App\Models\StoreSetting;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TestDatabaseGuard;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();

        // Runs before setUpTraits(), i.e. before RefreshDatabase can
        // migrate:fresh whatever the default connection resolved to.
        TestDatabaseGuard::check($app);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Existing tests predate persisted store settings and assume the
        // previous bootstrap behaviour: manual switch on, no weekly schedule.
        if (Schema::hasTable('store_settings')) {
            StoreSetting::current()->update([
                'accepting_orders' => true,
                'opening_hours' => null,
            ]);
        }
    }
}
