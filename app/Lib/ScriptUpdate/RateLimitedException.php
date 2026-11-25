<?php

namespace App\Lib\ScriptUpdate;

use Carbon\Carbon;
use RuntimeException;

/**
 * Une API (GitHub) refuse nos appels jusqu'à $retryAt : on arrête de l'interroger d'ici là.
 */
class RateLimitedException extends RuntimeException
{
    public function __construct(public readonly string $source, public readonly Carbon $retryAt)
    {
        parent::__construct("$source rate limited until $retryAt");
    }
}
