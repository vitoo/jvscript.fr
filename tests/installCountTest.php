<?php

namespace Tests;

use App\Model\Script;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class installCountTest extends BrowserKitTestCase
{
    use DatabaseTransactions;

    private Script $script;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->script = Script::forceCreate([
            'name' => 'Script compteur',
            'slug' => 'script-compteur',
            'js_url' => 'https://example.com/compteur.user.js',
            'status' => 1,
        ]);
    }

    private function fakeProxycheck(string $ip, string $proxy, string $type): void
    {
        Http::fake(['proxycheck.io/*' => ['status' => 'ok', $ip => ['proxy' => $proxy, 'type' => $type]]]);
    }

    private function install(string $ip): void
    {
        $this->call('GET', '/script/install/script-compteur', [], [], [], ['HTTP_CF_CONNECTING_IP' => $ip]);
    }

    private function installCount(): int
    {
        return $this->script->fresh()->install_count;
    }

    public function test_une_ip_d_utilisateur_est_comptee_une_seule_fois()
    {
        $this->fakeProxycheck('93.31.164.209', 'no', 'Residential');

        $this->install('93.31.164.209');
        $this->assertResponseOk();
        $this->assertStringContainsString('https://example.com/compteur.user.js', $this->response->getContent());
        $this->install('93.31.164.209');

        $this->assertSame(1, $this->installCount());
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://proxycheck.io/v2/93.31.164.209') && $request['vpn'] == 1);
    }

    public function test_une_ip_de_vpn_ou_de_serveur_n_est_pas_comptee_mais_l_installation_marche()
    {
        $this->fakeProxycheck('43.212.144.121', 'yes', 'VPN');

        $this->install('43.212.144.121');

        $this->assertResponseOk();
        $this->assertStringContainsString('https://example.com/compteur.user.js', $this->response->getContent());
        $this->assertSame(0, $this->installCount());
    }

    public function test_le_verdict_est_mis_en_cache_par_ip()
    {
        $this->fakeProxycheck('43.212.144.121', 'yes', 'VPN');

        $this->install('43.212.144.121');
        $this->install('43.212.144.121');
        $this->install('43.212.144.121');

        Http::assertSentCount(1);
        $this->assertSame(0, $this->installCount());
    }

    public function test_si_l_api_ne_repond_pas_l_installation_n_est_pas_comptee()
    {
        Http::fake(['proxycheck.io/*' => Http::response('erreur', 500)]);

        $this->install('93.31.164.209');

        $this->assertResponseOk();
        $this->assertSame(0, $this->installCount());
    }

    public function test_si_l_api_refuse_la_requete_l_installation_n_est_pas_comptee()
    {
        Http::fake(['proxycheck.io/*' => ['status' => 'denied', 'message' => 'quota dépassé']]);

        $this->install('93.31.164.209');

        $this->assertSame(0, $this->installCount());
    }

    public function test_une_ip_privee_n_interroge_pas_l_api()
    {
        Http::fake();

        $this->install('192.168.1.10');
        $this->install('127.0.0.1');

        Http::assertNothingSent();
        $this->assertSame(0, $this->installCount());
    }

    public function test_x_forwarded_for_n_est_plus_utilise_pour_l_ip()
    {
        $this->fakeProxycheck('93.31.164.209', 'no', 'Residential');

        $this->call('GET', '/script/install/script-compteur', [], [], [], [
            'HTTP_CF_CONNECTING_IP' => '93.31.164.209',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
        ]);
        $this->call('GET', '/script/install/script-compteur', [], [], [], [
            'HTTP_CF_CONNECTING_IP' => '93.31.164.209',
            'HTTP_X_FORWARDED_FOR' => '5.6.7.8',
        ]);

        $this->assertSame(1, $this->installCount());
    }

    public function test_la_cle_api_est_envoyee_quand_elle_est_configuree()
    {
        config(['services.proxycheck.key' => 'ma-cle']);
        $this->fakeProxycheck('93.31.164.209', 'no', 'Residential');

        $this->install('93.31.164.209');

        Http::assertSent(fn (Request $request) => $request['key'] === 'ma-cle');
        $this->assertSame(1, $this->installCount());
    }

    public function test_le_comptage_se_fait_apres_l_envoi_de_la_reponse()
    {
        $this->fakeProxycheck('93.31.164.209', 'no', 'Residential');
        $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
        $request = \Illuminate\Http\Request::create('/script/install/script-compteur', 'GET', [], [], [], ['HTTP_CF_CONNECTING_IP' => '93.31.164.209']);

        $response = $kernel->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        Http::assertNothingSent();
        $this->assertSame(0, $this->installCount());

        $kernel->terminate($request, $response);

        Http::assertSentCount(1);
        $this->assertSame(1, $this->installCount());
    }

    public function test_une_erreur_pendant_le_comptage_ne_casse_pas_l_installation()
    {
        Http::fake(fn () => throw new \RuntimeException('réseau coupé'));

        $this->install('93.31.164.209');

        $this->assertResponseOk();
        $this->assertSame(0, $this->installCount());
    }
}
