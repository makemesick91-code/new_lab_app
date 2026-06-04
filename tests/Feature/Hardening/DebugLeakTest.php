<?php

namespace Tests\Feature\Hardening;

use Tests\TestCase;

class DebugLeakTest extends TestCase
{
    public function test_production_404_response_does_not_expose_debug_details(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => false,
        ]);

        $response = $this->get('/this-route-should-not-exist');

        $response->assertNotFound();

        $response->assertDontSee('Stack trace');
        $response->assertDontSee('APP_KEY');
        $response->assertDontSee('DB_PASSWORD');
        $response->assertDontSee('.env');
        $response->assertDontSee('SQLSTATE');
    }
}
