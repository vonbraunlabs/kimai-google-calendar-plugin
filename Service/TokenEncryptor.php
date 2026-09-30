<?php

/*
 * This file is part of the Kimai Google Calendar plugin.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\GoogleCalendarBundle\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Encrypts OAuth tokens at rest, using a key derived from the Kimai APP_SECRET.
 */
final class TokenEncryptor
{
    private const PREFIX = 'enc1:';

    private string $key;

    public function __construct(#[Autowire('%kernel.secret%')] string $secret)
    {
        $this->key = hash('sha256', 'kimai-google-calendar|' . $secret, true);
    }

    public function encrypt(?string $plain): ?string
    {
        if ($plain === null || $plain === '') {
            return $plain;
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    public function decrypt(?string $cipher): ?string
    {
        if ($cipher === null || $cipher === '') {
            return $cipher;
        }

        if (!str_starts_with($cipher, self::PREFIX)) {
            return $cipher;
        }

        $raw = base64_decode(substr($cipher, \strlen(self::PREFIX)), true);
        if ($raw === false || \strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key
        );

        return $plain === false ? null : $plain;
    }
}
