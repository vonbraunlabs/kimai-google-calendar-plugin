<?php

/*
 * This file is part of the Kimai Google Calendar plugin.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\GoogleCalendarBundle\Service;

use KimaiPlugin\GoogleCalendarBundle\Entity\GoogleCalendarAccount;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Minimal client for the Google OAuth2, Calendar v3 and Tasks v1 REST APIs.
 */
class GoogleApiClient
{
    public const SCOPES = [
        'openid',
        'email',
        'https://www.googleapis.com/auth/calendar.readonly',
        'https://www.googleapis.com/auth/tasks.readonly',
    ];

    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
    private const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';
    private const CALENDAR_URL = 'https://www.googleapis.com/calendar/v3';
    private const TASKS_URL = 'https://tasks.googleapis.com/tasks/v1';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly PluginConfiguration $configuration,
        private readonly TokenEncryptor $encryptor,
    ) {
    }

    public function getAuthorizationUrl(string $redirectUri, string $state): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id' => $this->configuration->getClientId(),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'include_granted_scopes' => 'true',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    /**
     * Exchanges the authorization code and stores the tokens on the account.
     */
    public function authorize(GoogleCalendarAccount $account, string $code, string $redirectUri): void
    {
        $token = $this->tokenRequest([
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);

        if (empty($token['refresh_token'])) {
            throw new GoogleApiException('Google did not return a refresh token, please remove the app access in your Google account and connect again.');
        }

        $account->setRefreshToken($this->encryptor->encrypt($token['refresh_token']));
        $this->storeAccessToken($account, $token);

        try {
            $info = $this->request($account, 'GET', self::USERINFO_URL);
            $account->setGoogleEmail($info['email'] ?? null);
        } catch (GoogleApiException) {
            // the e-mail is only informational
        }
    }

    public function revoke(GoogleCalendarAccount $account): void
    {
        $token = $this->encryptor->decrypt($account->getRefreshToken());
        if ($token === null || $token === '') {
            return;
        }

        try {
            $this->httpClient->request('POST', self::REVOKE_URL, ['body' => ['token' => $token]])->getStatusCode();
        } catch (ExceptionInterface) {
            // disconnecting locally is what matters
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listCalendars(GoogleCalendarAccount $account): array
    {
        return $this->paginate($account, self::CALENDAR_URL . '/users/me/calendarList', ['minAccessRole' => 'reader']);
    }

    /**
     * Returns single (expanded recurring) events that overlap the given period.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listEvents(GoogleCalendarAccount $account, \DateTimeInterface $from, \DateTimeInterface $until): array
    {
        return $this->paginate($account, self::CALENDAR_URL . '/calendars/' . rawurlencode($account->getCalendarId()) . '/events', [
            'timeMin' => $from->format(\DateTimeInterface::RFC3339),
            'timeMax' => $until->format(\DateTimeInterface::RFC3339),
            'singleEvents' => 'true',
            'orderBy' => 'startTime',
            'maxResults' => 250,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listTaskLists(GoogleCalendarAccount $account): array
    {
        return $this->paginate($account, self::TASKS_URL . '/users/@me/lists', ['maxResults' => 100]);
    }

    /**
     * Returns the tasks completed in the given period.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listCompletedTasks(GoogleCalendarAccount $account, string $taskListId, \DateTimeInterface $from, \DateTimeInterface $until): array
    {
        return $this->paginate($account, self::TASKS_URL . '/lists/' . rawurlencode($taskListId) . '/tasks', [
            'completedMin' => $from->format(\DateTimeInterface::RFC3339),
            'completedMax' => $until->format(\DateTimeInterface::RFC3339),
            'showCompleted' => 'true',
            'showHidden' => 'true',
            'maxResults' => 100,
        ]);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<int, array<string, mixed>>
     */
    private function paginate(GoogleCalendarAccount $account, string $url, array $query): array
    {
        $items = [];
        $pageToken = null;

        do {
            if ($pageToken !== null) {
                $query['pageToken'] = $pageToken;
            }
            $page = $this->request($account, 'GET', $url, $query);
            foreach ($page['items'] ?? [] as $item) {
                $items[] = $item;
            }
            $pageToken = $page['nextPageToken'] ?? null;
        } while ($pageToken !== null);

        return $items;
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function request(GoogleCalendarAccount $account, string $method, string $url, array $query = []): array
    {
        $accessToken = $this->getAccessToken($account);

        try {
            $response = $this->httpClient->request($method, $url, [
                'query' => $query,
                'auth_bearer' => $accessToken,
                'timeout' => 30,
            ]);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (ExceptionInterface $ex) {
            throw new GoogleApiException('Google API request failed: ' . $ex->getMessage(), false, $ex);
        }

        if ($status >= 400) {
            $message = $data['error']['message'] ?? ($data['error_description'] ?? ('HTTP ' . $status));

            throw new GoogleApiException('Google API error: ' . $message, $status === 401);
        }

        return $data;
    }

    private function getAccessToken(GoogleCalendarAccount $account): string
    {
        $expires = $account->getTokenExpiresAt();
        $token = $this->encryptor->decrypt($account->getAccessToken());

        if ($token !== null && $token !== '' && $expires !== null && $expires > new \DateTimeImmutable('+60 seconds')) {
            return $token;
        }

        $refreshToken = $this->encryptor->decrypt($account->getRefreshToken());
        if ($refreshToken === null || $refreshToken === '') {
            throw new GoogleApiException('The Google account is not connected.', true);
        }

        $token = $this->tokenRequest([
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);
        $this->storeAccessToken($account, $token);

        return $token['access_token'];
    }

    /**
     * @param array<string, mixed> $token
     */
    private function storeAccessToken(GoogleCalendarAccount $account, array $token): void
    {
        $account->setAccessToken($this->encryptor->encrypt($token['access_token']));
        $account->setTokenExpiresAt(new \DateTimeImmutable('+' . ((int) ($token['expires_in'] ?? 3600)) . ' seconds'));
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    private function tokenRequest(array $params): array
    {
        if (!$this->configuration->isConfigured()) {
            throw new GoogleApiException('The Google OAuth client is not configured, see the plugin settings in the system configuration.');
        }

        $params['client_id'] = $this->configuration->getClientId();
        $params['client_secret'] = $this->configuration->getClientSecret();

        try {
            $response = $this->httpClient->request('POST', self::TOKEN_URL, ['body' => $params, 'timeout' => 30]);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (ExceptionInterface $ex) {
            throw new GoogleApiException('Google token request failed: ' . $ex->getMessage(), false, $ex);
        }

        if ($status >= 400 || empty($data['access_token'])) {
            $error = $data['error'] ?? 'unknown_error';
            $message = $data['error_description'] ?? $error;

            throw new GoogleApiException('Google authorization failed: ' . $message, $error === 'invalid_grant');
        }

        return $data;
    }
}
