<?php

namespace Tests;

use App\Models\StoreSetting;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
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
