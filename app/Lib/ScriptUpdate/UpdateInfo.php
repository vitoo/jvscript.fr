<?php

namespace App\Lib\ScriptUpdate;

use Carbon\Carbon;

/**
 * Ce qu'on a appris d'une source sur un script.
 */
class UpdateInfo
{
    /**
     * @param  string  $source  github, codeberg, greasyfork, openuserjs ou file
     * @param  Carbon|null  $date  date de dernière modification donnée par la source (commit, API…)
     * @param  string|null  $version  @version du script
     * @param  string|null  $contentHash  sha256 du fichier .user.js, quand on l'a téléchargé
     * @param  Carbon|null  $lastModified  en-tête Last-Modified du fichier, quand le serveur en donne un
     * @param  string[]  $errors
     */
    public function __construct(
        public readonly string $source,
        public readonly ?Carbon $date = null,
        public readonly ?string $version = null,
        public readonly ?string $contentHash = null,
        public readonly ?Carbon $lastModified = null,
        public readonly array $errors = [],
    ) {}

    /**
     * La source donne une vraie date de modification (commit, API) : elle fait foi,
     * contrairement à un simple fichier dont on ne peut que détecter le changement.
     */
    public function hasReliableDate(): bool
    {
        return $this->source !== 'file' && $this->date !== null;
    }
}
