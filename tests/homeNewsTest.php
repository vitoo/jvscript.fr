<?php

namespace Tests;

use App\Model\Script;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class homeNewsTest extends BrowserKitTestCase
{
    use DatabaseTransactions;

    /**
     * Crée un script validé (ou non) avec les dates voulues
     */
    private function createScript(string $name, array $attributes = []): Script
    {
        return Script::forceCreate(array_merge([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name),
            'autor' => 'khey',
            'js_url' => 'https://example.com/'.\Illuminate\Support\Str::slug($name).'.user.js',
            'status' => 1,
        ], $attributes));
    }

    public function test_les_nouveaux_scripts_sont_mis_en_avant()
    {
        $this->createScript('Script tout neuf', ['created_at' => now()->subDay()]);

        $this->visit('/')
            ->see('Nouveaux scripts');

        $newTab = $this->crawler->filter('#nouveaux-scripts')->text();
        $this->assertStringContainsString('Script tout neuf', $newTab);
        $this->assertStringNotContainsString('Nouveau', $newTab);
    }

    public function test_les_scripts_mis_a_jour_ont_leur_onglet_sans_doublon_avec_les_nouveaux()
    {
        $this->createScript('Vieux script mis a jour', [
            'created_at' => now()->subYears(2),
            'last_update' => now()->subDays(2),
        ]);
        $this->createScript('Script neuf et mis a jour', [
            'created_at' => now()->subMinute(),
            'last_update' => now(),
        ]);
        for ($i = 1; $i < Script::HOME_NEWS_COUNT; $i++) {
            $this->createScript("Autre script recent $i", ['created_at' => now()->subHours($i)]);
        }

        $this->visit('/');

        $this->assertStringContainsString('Mis à jour récemment', $this->crawler->filter('#nouveautes .nav-tabs')->text());
        $updatedTab = $this->crawler->filter('#scripts-mis-a-jour')->text();
        $this->assertStringContainsString('Vieux script mis a jour', $updatedTab);
        $this->assertStringNotContainsString('Script neuf et mis a jour', $updatedTab);
        $this->assertStringContainsString('Script neuf et mis a jour', $this->crawler->filter('#nouveaux-scripts')->text());
    }

    public function test_un_script_non_valide_n_apparait_pas_dans_les_nouveautes()
    {
        $this->createScript('Script en attente', ['status' => 0, 'created_at' => now()]);
        $this->createScript('Script refuse', ['status' => 2, 'created_at' => now(), 'last_update' => now()]);

        $this->visit('/');

        $news = $this->crawler->filter('#nouveautes')->text();
        $this->assertStringNotContainsString('Script en attente', $news);
        $this->assertStringNotContainsString('Script refuse', $news);
    }

    public function test_le_bloc_est_limite_au_nombre_de_scripts_prevu()
    {
        for ($i = 1; $i <= Script::HOME_NEWS_COUNT + 3; $i++) {
            $this->createScript("Script en serie $i", ['created_at' => now()->subMinutes($i)]);
        }

        $this->visit('/');

        $this->assertCount(Script::HOME_NEWS_COUNT, $this->crawler->filter('#nouveaux-scripts .thumbnail'));
        $this->assertStringContainsString('Script en serie 1', $this->crawler->filter('#nouveaux-scripts')->text());
        $this->assertStringNotContainsString('Script en serie '.(Script::HOME_NEWS_COUNT + 1), $this->crawler->filter('#nouveaux-scripts')->text());
    }

    public function test_la_grille_principale_garde_tous_les_scripts_sans_badges()
    {
        $oldScript = $this->createScript('Script ancien sans badge', [
            'created_at' => now()->subYears(3),
            'last_update' => now()->subYear(),
        ]);
        $newScript = $this->createScript('Script recent dans la grille', ['created_at' => now()->subDays(3)]);

        $this->visit('/');

        $this->assertCount(1, $this->crawler->filter('hr.separation-nouveautes'));
        $this->assertStringContainsString('Scripts populaires', $this->crawler->filter('#titre-liste')->text());

        $oldCard = $this->crawler->filter("#scripts .list #{$oldScript->id}-script")->text();
        $this->assertStringContainsString('Script ancien sans badge', $oldCard);
        $this->assertCount(0, $this->crawler->filter("#scripts .list #{$oldScript->id}-script .label-info, #scripts .list #{$oldScript->id}-script .label-success"));

        $newCard = $this->crawler->filter("#scripts .list #{$newScript->id}-script")->text();
        $this->assertStringNotContainsString('Nouveau', $newCard);
    }
}
