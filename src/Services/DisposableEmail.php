<?php

namespace TheFountainhead\Metis\Services;

class DisposableEmail
{
    private const DOMAINS = [
        'mailinator.com', 'guerrillamail.com', 'tempmail.com', 'throwaway.email',
        'yopmail.com', 'sharklasers.com', 'guerrillamailblock.com', 'grr.la',
        'dispostable.com', 'mailnesia.com', 'maildrop.cc', 'discard.email',
        'trashmail.com', 'temp-mail.org', 'fakeinbox.com', 'mailcatch.com',
        'mintemail.com', 'tempinbox.com', 'emailondeck.com', 'mohmal.com',
        '10minutemail.com', 'guerrillamail.info', 'safetymail.info', 'tmail.com',
        'harakirimail.com', 'jetable.org', 'trash-mail.com', 'mytrashmail.com',
        'getairmail.com', 'mailexpire.com', 'spamgourmet.com', 'nospam.ze.tc',
    ];

    public function isDisposable(string $email): bool
    {
        $domain = strtolower(substr($email, strrpos($email, '@') + 1));

        return in_array($domain, self::DOMAINS, true);
    }
}
