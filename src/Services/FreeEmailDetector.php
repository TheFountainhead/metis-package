<?php

namespace TheFountainhead\Metis\Services;

class FreeEmailDetector
{
    public function isFreeEmail(string $email): bool
    {
        $domain = strtolower(substr($email, strrpos($email, '@') + 1));

        return in_array($domain, config('metis.gating.free_email_domains', []));
    }
}
