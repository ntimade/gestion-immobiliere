<?php

namespace Tests\Feature;

use Tests\TestCase;

class InstallableAppTest extends TestCase
{
    public function test_manifest_describes_the_app_with_existing_icons(): void
    {
        $manifest = json_decode(file_get_contents(public_path('manifest.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('Gestion Immobilière', $manifest['name']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('/?app=immobilier', $manifest['start_url']);
        $this->assertSame('/immobilier', $manifest['id']);

        $sizes = [];
        foreach ($manifest['icons'] as $icon) {
            $file = public_path(ltrim($icon['src'], '/'));
            $this->assertFileExists($file);
            [$width, $height] = getimagesize($file);
            $this->assertSame($icon['sizes'], "{$width}x{$height}");
            $sizes[] = $icon['sizes'].' '.$icon['purpose'];
        }

        // Les tailles qu'Android demande pour proposer l'installation.
        $this->assertContains('192x192 any', $sizes);
        $this->assertContains('512x512 any', $sizes);
        $this->assertContains('512x512 maskable', $sizes);
    }

    public function test_login_page_offers_the_installation(): void
    {
        $this->get(route('login'))->assertOk()
            ->assertSee('manifest.json', false)
            ->assertSee('apple-touch-icon', false)
            ->assertSee('data-install-app', false)
            ->assertSee(__('Installer l\'application'));
    }

    public function test_service_worker_keeps_only_the_offline_page(): void
    {
        $worker = file_get_contents(public_path('sw.js'));

        $this->assertStringContainsString("'/offline.html'", $worker);
        $this->assertFileExists(public_path('offline.html'));
        // Aucune page de l'application n'est mise en cache : elles contiennent des données de clients.
        $this->assertStringNotContainsString('cache.put', $worker);
    }
}
