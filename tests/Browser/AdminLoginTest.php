<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class AdminLoginTest extends DuskTestCase
{
    public function test_admin_can_login(): void
    {
        $email = env('DUSK_ADMIN_EMAIL', 'admin@asiadentallab.com');
        $password = env('DUSK_ADMIN_PASSWORD', 'password');

        $this->browse(function (Browser $browser) use ($email, $password) {
            $browser->visit('/login')
                ->type('email', $email)
                ->type('password', $password)
                ->click('button[type="submit"]')
                ->waitForText('Dashboard', 10)
                ->assertSee('Dashboard')
                ->screenshot('admin-login-success');
        });
    }
}
