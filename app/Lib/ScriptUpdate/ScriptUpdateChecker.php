<?php

namespace App\Lib\ScriptUpdate;

use App\Model\Script;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Récupère la date de dernière modification et la version d'un script selon son hébergeur.
 *
 * GitHub limite l'API à 60 appels/h sans token (5000 avec GITHUB_TOKEN) : quand la limite est atteinte,
 * on le mémorise en cache jusqu'au reset et check() lève RateLimitedException pour les scripts GitHub.
 */
class ScriptUpdateChecker
{
    public const GITHUB_BLOCKED_CACHE_KEY = 'script-update:github-blocked-until';

    private const USER_AGENT = 'jvscript.fr-update-checker';

    /**
     * Convertit une URL github.com/…/blob/… (page HTML) en URL raw (le fichier lui-même).
     */
    public static function normalizeUrl(string $url): string
    {
        return preg_replace('#^(https://github\.com/[^/]+/[^/]+)/blob/(.+)$#', '$1/raw/$2', $url);
    }

    public function check(Script $script): UpdateInfo
    {
        $url = $script->js_url;

        if (preg_match('#^https://github\.com/([^/]+)/([^/]+)/raw/(?:refs/heads/)?([^/]+)/(.+)$#i', $url, $m)
            || preg_match('#^https://raw\.githubusercontent\.com/([^/]+)/([^/]+)/(?:refs/heads/)?([^/]+)/(.+)$#i', $url, $m)) {
            return $this->fromGithub($url, $m[1], $m[2], $m[3], $m[4]);
        }

        if (preg_match('#^https://([^/.]+)\.github\.io/(.+)$#i', $url, $m)) {
            // site GitHub Pages : owner.github.io/repo/fichier.js, ou owner.github.io/fichier.js (repo owner.github.io)
            $segments = explode('/', $m[2]);
            [$repo, $path] = count($segments) > 1
                ? [array_shift($segments), implode('/', $segments)]
                : [$m[1].'.github.io', $m[2]];

            return $this->fromGithub($url, $m[1], $repo, null, $path);
        }

        if (preg_match('#^https://codeberg\.org/([^/]+)/([^/]+)/raw/branch/([^/]+)/(.+)$#i', $url, $m)) {
            return $this->fromCodeberg($url, $m[1], $m[2], $m[3], $m[4]);
        }

        if (preg_match('#^https://(?:update\.|api\.)?greasyfork\.org/(?:[a-z-]+/)?scripts/(\d+)#i', $url, $m)) {
            return $this->fromGreasyfork($m[1]);
        }

        if (preg_match('#^https://openuserjs\.org/(?:install|src/scripts)/([^/]+)/([^/]+?)(?:\.min)?\.user\.js#i', $url, $m)) {
            return $this->fromOpenuserjs($m[1], $m[2]);
        }

        return $this->fromFile($url, 'file');
    }

    private function fromGithub(string $url, string $owner, string $repo, ?string $branch, string $path): UpdateInfo
    {
        $blockedUntil = Cache::get(self::GITHUB_BLOCKED_CACHE_KEY);
        if ($blockedUntil && now()->lt($blockedUntil)) {
            throw new RateLimitedException('github', Carbon::parse($blockedUntil));
        }

        $request = $this->http()->accept('application/vnd.github+json');
        if (config('services.github.token')) {
            $request = $request->withToken(config('services.github.token'));
        }

        $query = array_filter(['path' => rawurldecode($path), 'sha' => $branch, 'per_page' => 1]);
        $response = $request->get("https://api.github.com/repos/$owner/$repo/commits", $query);

        if (in_array($response->status(), [403, 429]) && $response->header('X-RateLimit-Remaining') === '0') {
            $retryAt = Carbon::createFromTimestamp((int) $response->header('X-RateLimit-Reset'));
            Cache::put(self::GITHUB_BLOCKED_CACHE_KEY, $retryAt->toIso8601String(), $retryAt);
            throw new RateLimitedException('github', $retryAt);
        }

        $date = $response->successful() ? $response->json('0.commit.committer.date') : null;
        $file = $this->fromFile($url, 'github');

        return new UpdateInfo(
            source: 'github',
            date: $date ? Carbon::parse($date) : null,
            version: $file->version,
            contentHash: $file->contentHash,
            errors: $date ? $file->errors : ["github API {$response->status()} pour $owner/$repo/$path", ...$file->errors],
        );
    }

    private function fromCodeberg(string $url, string $owner, string $repo, string $branch, string $path): UpdateInfo
    {
        $response = $this->http()->get("https://codeberg.org/api/v1/repos/$owner/$repo/commits", [
            'path' => rawurldecode($path),
            'sha' => $branch,
            'limit' => 1,
            'stat' => 'false',
        ]);

        $date = $response->successful() ? $response->json('0.commit.committer.date') : null;
        $file = $this->fromFile($url, 'codeberg');

        return new UpdateInfo(
            source: 'codeberg',
            date: $date ? Carbon::parse($date) : null,
            version: $file->version,
            contentHash: $file->contentHash,
            errors: $date ? $file->errors : ["codeberg API {$response->status()} pour $owner/$repo/$path", ...$file->errors],
        );
    }

    private function fromGreasyfork(string $id): UpdateInfo
    {
        $response = $this->http()->get("https://api.greasyfork.org/scripts/$id.json");
        $date = $response->successful() ? $response->json('code_updated_at') : null;

        return new UpdateInfo(
            source: 'greasyfork',
            date: $date ? Carbon::parse($date) : null,
            version: $response->successful() ? $response->json('version') : null,
            errors: $date ? [] : ["greasyfork API {$response->status()} pour le script $id"],
        );
    }

    private function fromOpenuserjs(string $author, string $name): UpdateInfo
    {
        // pas d'API pour la date : on la lit sur la page du script
        $page = $this->http()->get("https://openuserjs.org/scripts/$author/$name");
        $date = null;
        if ($page->successful()
            && (preg_match('/<time class="script-updated" datetime="([^"]+)"/i', $page->body(), $m)
                || preg_match('/<b>Published:<\/b> <time datetime="([^"]+)"/i', $page->body(), $m))) {
            $date = Carbon::parse($m[1]);
        }

        $meta = $this->http()->get("https://openuserjs.org/meta/$author/$name.meta.json");
        $version = $meta->successful() ? $meta->json('UserScript.version.0.value') : null;

        return new UpdateInfo(
            source: 'openuserjs',
            date: $date,
            version: $version,
            errors: $date ? [] : ["openuserjs : date introuvable pour $author/$name"],
        );
    }

    /**
     * Télécharge le fichier lui-même : version (@version), empreinte du contenu et Last-Modified.
     */
    private function fromFile(string $url, string $source): UpdateInfo
    {
        try {
            $response = $this->http()->get($url);
        } catch (ConnectionException) {
            return new UpdateInfo(source: $source, errors: ["fichier injoignable : $url"]);
        }

        if (! $response->successful()) {
            return new UpdateInfo(source: $source, errors: ["fichier {$response->status()} : $url"]);
        }

        $content = $response->body();
        $version = preg_match('#//\s*@version\s+([\w.\-]+)#i', $content, $m) ? $m[1] : null;

        return new UpdateInfo(
            source: $source,
            version: $version,
            contentHash: hash('sha256', $content),
            lastModified: $this->lastModified($response),
            errors: $version ? [] : ["@version introuvable : $url"],
        );
    }

    private function lastModified(Response $response): ?Carbon
    {
        $header = $response->header('Last-Modified');
        if (! $header) {
            return null;
        }
        try {
            return Carbon::parse($header);
        } catch (\Exception) {
            return null;
        }
    }

    private function http(): PendingRequest
    {
        // on ne réessaie que sur une panne réseau ou une erreur serveur : jamais sur un 403/429 de quota
        return Http::withUserAgent(self::USER_AGENT)->timeout(15)->retry(
            2,
            500,
            fn ($e) => $e instanceof ConnectionException || ($e instanceof RequestException && $e->response->serverError()),
            throw: false,
        );
    }
}
