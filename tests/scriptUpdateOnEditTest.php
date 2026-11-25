<?php

namespace Tests;

use App\Model\Script;
use App\Model\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Modifier l'URL d'un script ou le valider lance get-script-update pour ce script, après la réponse.
 */
class scriptUpdateOnEditTest extends BrowserKitTestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        Http::fake([
            'example.com/*' => Http::response("// ==UserScript==\n// @version 4.2\n// ==/UserScript==", 200, ['Last-Modified' => 'Mon, 02 Nov 2026 10:00:00 GMT']),
        ]);
        // admin du seeder : créer un utilisateur décalerait les ids attendus par scriptsTest (l'auto-incrément n'est pas annulé)
        $this->admin = User::where('name', 'superadmin')->firstOrFail();
    }

    private function createScript(array $attributes = []): Script
    {
        $suffix = uniqid();

        return Script::forceCreate(array_merge([
            'name' => "script maj $suffix",
            'slug' => "script-maj-$suffix",
            'js_url' => 'https://example.com/ancien.user.js',
            'status' => 1,
            'version' => '1.0',
        ], $attributes));
    }

    private function update(Script $script, array $changes): void
    {
        $this->actingAs($this->admin)->call('PUT', route('script.update', $script->slug), array_merge([
            '_token' => csrf_token(),
            'name' => $script->name,
            'js_url' => $script->js_url,
            'description' => $script->description,
        ], $changes));
    }

    public function test_changer_l_url_recupere_la_version_et_la_date_tout_de_suite()
    {
        $script = $this->createScript();

        $this->update($script, ['js_url' => 'https://example.com/nouveau.user.js']);

        $script->refresh();
        $this->assertSame('4.2', $script->version);
        // la version a changé (1.0 => 4.2) : la date de MAJ passe à aujourd'hui
        $this->assertSame(now()->toDateString(), $script->last_update->toDateString());
        Http::assertSent(fn ($request) => $request->url() === 'https://example.com/nouveau.user.js');
    }

    public function test_modifier_seulement_la_description_ne_lance_rien()
    {
        $script = $this->createScript();

        $this->update($script, ['description' => 'nouvelle description']);

        $this->assertSame('1.0', $script->fresh()->version);
        Http::assertNothingSent();
    }

    public function test_valider_un_script_lance_sa_premiere_verification()
    {
        $script = $this->createScript(['status' => 0]);

        $this->actingAs($this->admin)->call('GET', route('script.validate', $script->slug));

        $script->refresh();
        $this->assertSame(1, (int) $script->status);
        $this->assertSame('4.2', $script->version);
    }
}
