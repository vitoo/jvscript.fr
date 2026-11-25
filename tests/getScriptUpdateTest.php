<?php

namespace Tests;

use App\Lib\ScriptUpdate\ScriptUpdateChecker;
use App\Model\Script;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class getScriptUpdateTest extends BrowserKitTestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        // les scripts de la base de test ne doivent pas être pris dans les passages sans --slug
        DB::table('scripts')->update(['updated_at' => now()]);
    }

    private function createScript(string $slug, string $jsUrl, array $attributes = []): Script
    {
        $script = Script::forceCreate(array_merge([
            'name' => $slug,
            'slug' => $slug,
            'js_url' => $jsUrl,
            'status' => 1,
        ], $attributes));
        DB::table('scripts')->where('id', $script->id)->update(['updated_at' => now()->subDays(2)]);

        return $script->fresh();
    }

    private function userscript(string $version, string $body = ''): string
    {
        return "// ==UserScript==\n// @name test\n// @version      $version\n// ==/UserScript==\n$body";
    }

    public function test_un_fichier_sans_date_prend_le_last_modified_puis_detecte_les_changements()
    {
        $script = $this->createScript('risibank-like', 'https://risibank.fr/jvc.user.js', [
            'version' => '1.10.1',
            'last_update' => '2022-01-09 22:50:41',
        ]);

        $lastModified = ['Last-Modified' => 'Mon, 23 Nov 2026 01:11:28 GMT'];
        Http::fake(['risibank.fr/*' => Http::sequence()
            ->push($this->userscript('1.10.1'), 200, $lastModified)
            ->push($this->userscript('1.10.1'), 200, $lastModified)
            ->push($this->userscript('1.10.1', 'nouveau code'), 200, $lastModified)]);

        // 1er passage : contenu inconnu, on prend le Last-Modified du serveur
        $this->assertSame(0, $this->artisan('get-script-update'));
        $script->refresh();
        $this->assertSame('2026-11-23', $script->last_update->toDateString());
        $this->assertNotNull($script->content_hash);
        $this->assertTrue($script->updated_at->gt(now()->subMinute()));

        // même contenu : rien ne bouge
        $this->artisan('get-script-update', ['--slug' => 'risibank-like']);
        $this->assertSame('2026-11-23', $script->fresh()->last_update->toDateString());

        // contenu modifié sans changement de version, Last-Modified figé : on prend la date du jour
        Carbon::setTestNow('2026-12-01 10:00:00');
        $this->artisan('get-script-update', ['--slug' => 'risibank-like']);
        $this->assertSame('2026-12-01', $script->fresh()->last_update->toDateString());
        Carbon::setTestNow();
    }

    public function test_un_last_modified_genere_a_la_volee_est_ignore()
    {
        $script = $this->createScript('pastebin-like', 'https://pastebin.com/raw/abc', ['version' => '1.0']);
        Http::fake(['pastebin.com/*' => Http::response($this->userscript('1.0'), 200, ['Last-Modified' => now()->toRfc7231String()])]);

        $this->artisan('get-script-update', ['--slug' => 'pastebin-like']);

        $this->assertNull($script->fresh()->last_update);
    }

    public function test_un_changement_de_version_met_a_jour_la_date()
    {
        $script = $this->createScript('version-bump', 'https://example.com/script.user.js', [
            'version' => '1.0',
            'last_update' => '2020-01-01 00:00:00',
            'content_hash' => 'ancien',
        ]);
        Http::fake(['example.com/*' => Http::response($this->userscript('1.1'))]);

        $this->artisan('get-script-update', ['--slug' => 'version-bump']);

        $script->refresh();
        $this->assertSame('1.1', $script->version);
        $this->assertSame(now()->toDateString(), $script->last_update->toDateString());
    }

    public function test_github_date_du_dernier_commit_et_version_du_fichier()
    {
        config(['services.github.token' => 'token-de-test']);
        $script = $this->createScript('gh', 'https://github.com/owner/repo/blob/main/src/mon%20script.user.js', ['last_update' => '2020-01-01']);
        Http::fake([
            'api.github.com/*' => Http::response([['commit' => ['committer' => ['date' => '2026-10-02T23:55:20Z']]]]),
            'github.com/*' => Http::response($this->userscript('3.2')),
        ]);

        $this->artisan('get-script-update', ['--slug' => 'gh']);

        $script->refresh();
        $this->assertSame('https://github.com/owner/repo/raw/main/src/mon%20script.user.js', $script->js_url);
        $this->assertSame('3.2', $script->version);
        $this->assertSame('2026-10-03', $script->last_update->toDateString());
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.github.com/repos/owner/repo/commits')
            && $request['path'] === 'src/mon script.user.js'
            && $request['sha'] === 'main'
            && $request->hasHeader('Authorization', 'Bearer token-de-test'));
    }

    public function test_quota_github_atteint_les_scripts_github_sont_reportes()
    {
        $first = $this->createScript('gh-1', 'https://raw.githubusercontent.com/o/r/main/a.user.js');
        $second = $this->createScript('gh-2', 'https://raw.githubusercontent.com/o/r/main/b.user.js');
        $other = $this->createScript('gf', 'https://greasyfork.org/scripts/27001-jvc-tweet/code/JVC%20tweet.user.js');
        Http::fake([
            'api.github.com/*' => Http::response(['message' => 'API rate limit exceeded'], 403, [
                'X-RateLimit-Remaining' => '0',
                'X-RateLimit-Reset' => (string) now()->addMinutes(30)->timestamp,
            ]),
            'api.greasyfork.org/*' => Http::response(['code_updated_at' => '2017-02-03T16:25:38.000Z', 'version' => '0.2']),
        ]);

        $this->assertSame(0, $this->artisan('get-script-update'));

        // un seul appel GitHub : le quota est mémorisé, le 2e script n'est même pas interrogé
        Http::assertSentCount(2);
        $this->assertNotNull(Cache::get(ScriptUpdateChecker::GITHUB_BLOCKED_CACHE_KEY));
        // les scripts GitHub ne sont pas marqués comme vérifiés : ils seront repris après le reset
        $this->assertTrue($first->fresh()->updated_at->lt(now()->subDay()));
        $this->assertTrue($second->fresh()->updated_at->lt(now()->subDay()));
        // les autres hébergeurs continuent
        $this->assertSame('0.2', $other->fresh()->version);
        $this->assertSame('2017-02-03', $other->fresh()->last_update->toDateString());
    }

    public function test_codeberg_et_openuserjs()
    {
        $codeberg = $this->createScript('cb', 'https://codeberg.org/Annapurna/Oncheures/raw/branch/main/Oncheures.user.js');
        $oujs = $this->createScript('oujs', 'https://openuserjs.org/install/CrazyJeux/PostFinder.user.js');
        Http::fake([
            'codeberg.org/api/*' => Http::response([['commit' => ['committer' => ['date' => '2024-04-24T01:24:06Z']]]]),
            'codeberg.org/*' => Http::response($this->userscript('2.0')),
            'openuserjs.org/scripts/*' => Http::response('<time class="script-updated" datetime="2017-07-09T22:20:59.712Z" title="x">'),
            'openuserjs.org/meta/*' => Http::response(['UserScript' => ['version' => [['value' => '9']]]]),
        ]);

        $this->artisan('get-script-update');

        $this->assertSame('2024-04-24', $codeberg->fresh()->last_update->toDateString());
        $this->assertSame('2.0', $codeberg->fresh()->version);
        $this->assertSame('2017-07-10', $oujs->fresh()->last_update->toDateString());
        $this->assertSame('9', $oujs->fresh()->version);
    }

    public function test_gist_date_de_la_revision_figee_ou_de_la_derniere_revision()
    {
        $pinned = $this->createScript('gist-fige', 'https://gist.github.com/someone/2f0d041a/raw/'.str_repeat('a', 40).'/script.user.js');
        $latest = $this->createScript('gist-courant', 'https://gist.githubusercontent.com/other/9d84616b/raw/script.user.js');
        Http::fake([
            'api.github.com/gists/2f0d041a' => Http::response([
                'updated_at' => '2026-04-15T19:50:58Z',
                'history' => [
                    ['version' => str_repeat('b', 40), 'committed_at' => '2026-04-15T19:50:58Z'],
                    ['version' => str_repeat('a', 40), 'committed_at' => '2025-01-10T12:00:00Z'],
                ],
            ]),
            'api.github.com/gists/9d84616b' => Http::response(['updated_at' => '2026-04-16T10:00:00Z', 'history' => []]),
            'gist.github.com/*' => Http::response($this->userscript('1.0')),
            'gist.githubusercontent.com/*' => Http::response($this->userscript('2.0')),
        ]);

        $this->artisan('get-script-update');

        // l'URL fige la révision « aaa… » : c'est sa date qui compte, pas celle de la dernière révision
        $this->assertSame('2025-01-10', $pinned->fresh()->last_update->toDateString());
        $this->assertSame('2026-04-16', $latest->fresh()->last_update->toDateString());
        $this->assertSame('2.0', $latest->fresh()->version);
    }

    public function test_un_script_en_erreur_est_quand_meme_marque_verifie()
    {
        $script = $this->createScript('mort', 'https://example.com/disparu.user.js', ['version' => '1.0']);
        Http::fake(['example.com/*' => Http::response('', 404)]);

        $this->artisan('get-script-update');

        $script->refresh();
        $this->assertSame('1.0', $script->version);
        $this->assertTrue($script->updated_at->gt(now()->subMinute()));
    }

    public function test_dry_run_n_enregistre_rien()
    {
        $script = $this->createScript('dry', 'https://example.com/dry.user.js', ['version' => '1.0']);
        Http::fake(['example.com/*' => Http::response($this->userscript('2.0'))]);

        $this->artisan('get-script-update', ['--dry-run' => true]);

        $script->refresh();
        $this->assertSame('1.0', $script->version);
        $this->assertTrue($script->updated_at->lt(now()->subDay()));
    }
}
