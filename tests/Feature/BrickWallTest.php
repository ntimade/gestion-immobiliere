<?php

namespace Tests\Feature;

use App\Models\Organisation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrickWallTest extends TestCase
{
    use RefreshDatabase;

    public function test_watermark_is_on_the_login_pages(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('data-brick-wall', false);
        $this->get(route('support.login'))->assertOk()->assertSee('data-brick-wall', false);
    }

    public function test_watermark_is_nowhere_else(): void
    {
        $this->get(route('register'))->assertOk()->assertDontSee('data-brick-wall', false);
        $this->get(route('password.request'))->assertOk()->assertDontSee('data-brick-wall', false);
        $this->get('/page-absente')->assertNotFound()->assertDontSee('data-brick-wall', false);

        $organisation = Organisation::create(['name' => 'Alpha', 'code' => 'ALP-001', 'currency' => 'XAF']);
        $this->actingAs($organisation->users()->create(['name' => 'A', 'email' => 'a@t.test', 'password' => 'secret-pass-1']));

        foreach (['dashboard', 'properties.index', 'tenants.index', 'messages.index', 'settings.edit'] as $route) {
            $this->get(route($route))->assertOk()->assertDontSee('data-brick-wall', false);
        }
    }
}
