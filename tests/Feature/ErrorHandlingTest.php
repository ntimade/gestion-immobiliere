<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class ErrorHandlingTest extends TestCase
{
    use RefreshDatabase;

    public function test_csrf_token_can_be_refreshed(): void
    {
        $this->getJson(route('csrf.refresh'))->assertOk()->assertJsonStructure(['token']);
    }

    public function test_expired_form_returns_to_the_form_without_passwords(): void
    {
        Route::post('/test-expire', fn () => abort(419))->middleware('web');

        $response = $this->from('/inscription')->post('/test-expire', [
            'organisation_name' => 'Eau Nord',
            'password' => 'secret-pass-1',
            'password_confirmation' => 'secret-pass-1',
        ]);

        $response->assertRedirect('/inscription')->assertSessionHas('error');
        $this->assertSame('Eau Nord', session()->getOldInput('organisation_name'));
        $this->assertNull(session()->getOldInput('password'));
        $this->assertNull(session()->getOldInput('password_confirmation'));

        $this->get('/inscription')->assertSee(session('error'))->assertSee('Eau Nord');
    }

    public function test_error_pages_are_in_french(): void
    {
        $this->get('/page-qui-nexiste-pas')->assertNotFound()->assertSee('Page introuvable')->assertDontSee('Not Found');

        Route::get('/test-interdit', fn () => abort(403))->middleware('web');
        $this->get('/test-interdit')->assertForbidden()->assertSee('Action impossible')->assertDontSee('Forbidden');

        Route::get('/test-trop', fn () => abort(429))->middleware('web');
        $this->get('/test-trop')->assertStatus(429)->assertSee('Trop de tentatives');
    }

    public function test_server_error_hides_technical_details(): void
    {
        config(['app.debug' => false]);
        Route::get('/test-panne', fn () => throw new RuntimeException('secret technique'))->middleware('web');

        $this->get('/test-panne')->assertStatus(500)
            ->assertSee('Service momentanément indisponible')
            ->assertDontSee('secret technique');
    }
}
