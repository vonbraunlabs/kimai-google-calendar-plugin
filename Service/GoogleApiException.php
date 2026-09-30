<?php

/*
 * This file is part of the Kimai Google Calendar plugin.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\GoogleCalendarBundle\Service;

final class GoogleApiException extends \RuntimeException
{
    /**
     * True if the refresh token was revoked or expired and the user has to connect again.
     */
    public function __construct(string $message, private readonly bool $authorizationLost = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function isAuthorizationLost(): bool
    {
        return $this->authorizationLost;
    }
}
