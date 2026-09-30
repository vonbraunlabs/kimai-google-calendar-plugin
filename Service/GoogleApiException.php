<?php

/*
 * This file is part of the Kimai Google Calendar plugin.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\GoogleCalendarBundle\Service;

/**
 * The message is English, for logs; the translation key (flashmessages domain) is what users see.
 */
final class GoogleApiException extends \RuntimeException
{
    /**
     * @param bool $authorizationLost true if the refresh token was revoked or expired and the user has to connect again
     * @param array<string, string> $translationParameters
     */
    public function __construct(
        string $message,
        private readonly bool $authorizationLost = false,
        ?\Throwable $previous = null,
        private readonly ?string $translationKey = null,
        private readonly array $translationParameters = [],
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function isAuthorizationLost(): bool
    {
        return $this->authorizationLost;
    }

    public function getTranslationKey(): ?string
    {
        return $this->translationKey;
    }

    /**
     * @return array<string, string>
     */
    public function getTranslationParameters(): array
    {
        return $this->translationParameters;
    }
}
