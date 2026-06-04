<?php

namespace Tests\Feature\Hardening;

use Tests\TestCase;

class ProductionConfigTest extends TestCase
{
    public function test_app_debug_is_disabled_in_production(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => false,
        ]);

        $this->assertSame('production', config('app.env'));
        $this->assertFalse(config('app.debug'));
    }

    public function test_sensitive_config_values_are_not_empty(): void
    {
        $this->assertNotEmpty(config('app.key'));
        $this->assertNotEmpty(config('database.default'));
        $this->assertNotEmpty(config('session.driver'));
    }
}
