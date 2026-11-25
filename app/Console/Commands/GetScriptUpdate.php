<?php

namespace App\Console\Commands;

use App\Lib\ScriptUpdate\RateLimitedException;
use App\Lib\ScriptUpdate\ScriptUpdateChecker;
use App\Lib\ScriptUpdate\UpdateInfo;
use App\Model\Script;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Met à jour la date de dernière MAJ (last_update) et la version des scripts validés.
 *
 * Lancée toutes les heures : chaque passage ne traite que les --limit scripts vérifiés il y a le plus
 * longtemps (et pas depuis moins d'un jour), pour étaler les appels et rester sous les quotas (GitHub).
 */
class GetScriptUpdate extends Command
{
    protected $signature = 'get-script-update
        {--slug= : vérifier un seul script, même vérifié récemment}
        {--limit=25 : nombre maximum de scripts vérifiés par passage}
        {--all : ignorer le délai de 24h entre deux vérifications}
        {--dry-run : afficher ce qui changerait sans rien enregistrer}';

    protected $description = 'Met à jour la date de MAJ et la version des scripts depuis leur hébergeur';

    /**
     * Un Last-Modified plus vieux que ça au moment où le contenu change n'est pas crédible
     * (serveur qui renvoie une date fixe) : on prend alors la date du jour.
     */
    private const LAST_MODIFIED_MAX_AGE_DAYS = 2;

    public function handle(ScriptUpdateChecker $checker): int
    {
        $query = Script::where('status', 1);
        if ($slug = $this->option('slug')) {
            $query->where('slug', $slug);
        } else {
            if (! $this->option('all')) {
                $query->where('updated_at', '<', now()->subDay());
            }
            $query->orderBy('updated_at')
                ->limit((int) $this->option('limit'));
        }

        foreach ($query->get() as $script) {
            $this->checkScript($checker, $script);
        }

        return self::SUCCESS;
    }

    private function checkScript(ScriptUpdateChecker $checker, Script $script): void
    {
        $dryRun = $this->option('dry-run');
        $this->line("<info>{$script->name}</info> <comment>{$script->js_url}</comment>");

        $normalized = ScriptUpdateChecker::normalizeUrl($script->js_url);
        if ($normalized !== $script->js_url) {
            $this->warn("  url corrigée : $normalized");
            $script->js_url = $normalized;
        }

        try {
            $info = $checker->check($script);
        } catch (RateLimitedException $e) {
            // pas de touch() : le script sera repris au prochain passage après le reset du quota
            $this->warn("  ignoré : quota {$e->source} atteint jusqu'à {$e->retryAt}");

            return;
        } catch (\Throwable $e) {
            $info = new UpdateInfo(source: 'error', errors: [$e->getMessage()]);
        }

        foreach ($info->errors as $error) {
            $this->error("  $error");
            Log::warning("get-script-update {$script->slug} : $error");
        }

        $versionChanged = $info->version !== null && $info->version !== $script->version;
        $contentChanged = $info->contentHash !== null && $script->content_hash !== null && $info->contentHash !== $script->content_hash;
        $newDate = $this->newDate($script, $info, $versionChanged, $contentChanged);

        if ($versionChanged) {
            $this->warn("  version : {$script->version} => {$info->version}");
            $script->version = $info->version;
        }
        if ($newDate) {
            $this->warn("  last_update : {$script->last_update} => $newDate");
            $script->last_update = $newDate;
        }
        if ($info->contentHash) {
            $script->content_hash = $info->contentHash;
        }

        if ($dryRun) {
            return;
        }

        // on enregistre même en cas d'erreur : updated_at sert de « dernière vérification »,
        // sinon un script en erreur serait réinterrogé à chaque passage
        $script->updated_at = now();
        $script->save();
    }

    /**
     * La nouvelle last_update, ou null si elle ne change pas.
     */
    private function newDate(Script $script, UpdateInfo $info, bool $versionChanged, bool $contentChanged): ?Carbon
    {
        $current = $script->last_update;

        // hébergeur avec une vraie date (commit, API) : elle fait foi
        if ($info->hasReliableDate()) {
            $date = $info->date->copy()->setTimezone(config('app.timezone'));

            return $current === null || $this->isDifferentDay($date, $current) ? $date : null;
        }

        // simple fichier : on ne sait que s'il a changé
        $candidate = null;
        if ($contentChanged || $versionChanged) {
            $recent = $info->lastModified && $info->lastModified->gt(now()->subDays(self::LAST_MODIFIED_MAX_AGE_DAYS));
            $candidate = $recent ? $info->lastModified->copy()->setTimezone(config('app.timezone')) : now();
        } elseif ($script->content_hash === null && $info->lastModified && $info->lastModified->lt(now()->subHour())) {
            // premier passage depuis l'ajout de content_hash : le Last-Modified du serveur est le meilleur indice
            // (sauf s'il vaut « maintenant » : page générée à la volée, comme pastebin)
            $candidate = $info->lastModified->copy()->setTimezone(config('app.timezone'));
        }

        // un simple fichier ne fait jamais reculer la date
        if ($candidate === null || ($current !== null && ($candidate->lte($current) || ! $this->isDifferentDay($candidate, $current)))) {
            return null;
        }

        return $candidate;
    }

    /**
     * Les dates existantes ont été enregistrées avec des fuseaux incohérents (UTC sans conversion,
     * colonne timestamp convertie par MySQL) : on ignore les écarts de moins de 12h pour ne pas
     * réécrire des dates qui n'ont en fait pas changé.
     */
    private function isDifferentDay(Carbon $date, Carbon $current): bool
    {
        return ! $date->isSameDay($current) && abs($date->diffInHours($current)) >= 12;
    }
}
