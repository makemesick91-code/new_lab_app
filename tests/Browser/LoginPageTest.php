<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class LoginPageTest extends DuskTestCase
{
    public function test_login_page_can_be_opened(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/login')
                ->assertInputPresent('email')
                ->assertInputPresent('password')
                ->screenshot('login-page');
        });
    }
}
