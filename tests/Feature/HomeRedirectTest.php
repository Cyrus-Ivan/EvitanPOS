<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_redirects_to_dashboard()
    {
        // Etivan POS has no public landing page; the desktop app opens on the
        // dashboard, which in turn redirects guests to the login screen.
        $this->get(route('home'))->assertRedirect('/dashboard');
    }
}
