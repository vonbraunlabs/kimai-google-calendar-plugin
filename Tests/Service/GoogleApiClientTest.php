<?php

/*
 * This file is part of the Kimai Google Calendar plugin.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\GoogleCalendarBundle\Tests\Service;

use KimaiPlugin\GoogleCalendarBundle\Entity\GoogleCalendarAccount;
use KimaiPlugin\GoogleCalendarBundle\Service\GoogleApiClient;
use KimaiPlugin\GoogleCalendarBundle\Service\GoogleApiException;
use KimaiPlugin\GoogleCalendarBundle\Service\PluginConfiguration;
use KimaiPlugin\GoogleCalendarBundle\Service\TokenEncryptor;
use KimaiPlugin\GoogleCalendarBundle\Tests\Fixtures;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @covers \KimaiPlugin\GoogleCalendarBundle\Service\GoogleApiClient
 * @covers \KimaiPlugin\GoogleCalendarBundle\Service\GoogleApiException
 */
class GoogleApiClientTest extends TestCase
{
    /** @var array<int, array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];
    private TokenEncryptor $encryptor;

    protected function setUp(): void
    {
        $this->encryptor = new TokenEncryptor('secret');
    }

    /**
     * @param array<int, MockResponse|\Closure> $responses consumed in order
     */
    private function client(array $responses, bool $configured = true): GoogleApiClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses) {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
            $response = array_shift($responses);

            return $response instanceof \Closure ? $response() : $response;
        });

        $values = $configured ? [PluginConfiguration::CLIENT_ID => 'client-id', PluginConfiguration::CLIENT_SECRET => 'client-secret'] : [];

        return new GoogleApiClient($http, new PluginConfiguration(Fixtures::systemConfiguration($values)), $this->encryptor);
    }

    private function json(array $data, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($data), ['http_code' => $status]);
    }

    private function connectedAccount(): GoogleCalendarAccount
    {
        $account = Fixtures::account(null, false);
        $account->setRefreshToken($this->encryptor->encrypt('refresh'));
        $account->setAccessToken($this->encryptor->encrypt('access'));
        $account->setTokenExpiresAt(new \DateTimeImmutable('+1 hour'));

        return $account;
    }

    public function testAuthorizationUrlRequestsOfflineReadOnlyAccess(): void
    {
        $url = $this->client([])->getAuthorizationUrl('https://kimai/cb', 'state-1');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        self::assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        self::assertSame('client-id', $query['client_id']);
        self::assertSame('https://kimai/cb', $query['redirect_uri']);
        self::assertSame('offline', $query['access_type']);
        self::assertSame('consent', $query['prompt']);
        self::assertSame('state-1', $query['state']);
        self::assertStringContainsString('calendar.readonly', $query['scope']);
        self::assertStringContainsString('tasks.readonly', $query['scope']);
    }

    public function testAuthorizeStoresEncryptedTokensAndEmail(): void
    {
        $account = Fixtures::account(null, false);
        $client = $this->client([
            $this->json(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600]),
            $this->json(['email' => 'me@example.com']),
        ]);

        $client->authorize($account, 'the-code', 'https://kimai/cb');

        self::assertTrue($account->isConnected());
        self::assertSame('new-refresh', $this->encryptor->decrypt($account->getRefreshToken()));
        self::assertSame('new-access', $this->encryptor->decrypt($account->getAccessToken()));
        self::assertSame('me@example.com', $account->getGoogleEmail());
        self::assertGreaterThan(new \DateTimeImmutable('+50 minutes'), $account->getTokenExpiresAt());
        self::assertStringContainsString('code=the-code', $this->requests[0]['options']['body']);
        self::assertStringContainsString('client_secret=client-secret', $this->requests[0]['options']['body']);
        self::assertContains('Authorization: Bearer new-access', $this->requests[1]['options']['headers']);
    }

    public function testAuthorizeIgnoresUserInfoFailure(): void
    {
        $account = Fixtures::account(null, false);
        $client = $this->client([
            $this->json(['access_token' => 'a', 'refresh_token' => 'r']),
            $this->json(['error' => ['message' => 'nope']], 403),
        ]);

        $client->authorize($account, 'code', 'https://kimai/cb');

        self::assertTrue($account->isConnected());
        self::assertNull($account->getGoogleEmail());
    }

    public function testAuthorizeWithoutRefreshTokenFails(): void
    {
        $this->expectException(GoogleApiException::class);
        $this->expectExceptionMessage('refresh token');

        $this->client([$this->json(['access_token' => 'a'])])->authorize(Fixtures::account(null, false), 'code', 'https://kimai/cb');
    }

    public function testTokenRequestNeedsConfiguredClient(): void
    {
        $this->expectException(GoogleApiException::class);
        $this->expectExceptionMessage('not configured');

        $this->client([], false)->authorize(Fixtures::account(null, false), 'code', 'https://kimai/cb');
    }

    public function testRevokedRefreshTokenMeansAuthorizationLost(): void
    {
        $account = $this->connectedAccount();
        $account->setTokenExpiresAt(new \DateTimeImmutable('-1 minute'));
        $client = $this->client([$this->json(['error' => 'invalid_grant', 'error_description' => 'Token has been revoked.'], 400)]);

        try {
            $client->listCalendars($account);
            self::fail('Expected exception');
        } catch (GoogleApiException $ex) {
            self::assertTrue($ex->isAuthorizationLost());
            self::assertStringContainsString('Token has been revoked.', $ex->getMessage());
        }
    }

    public function testExpiredAccessTokenIsRefreshed(): void
    {
        $account = $this->connectedAccount();
        $account->setTokenExpiresAt(new \DateTimeImmutable('-1 minute'));
        $client = $this->client([
            $this->json(['access_token' => 'refreshed', 'expires_in' => 60]),
            $this->json(['items' => [['id' => 'primary']]]),
        ]);

        self::assertSame([['id' => 'primary']], $client->listCalendars($account));
        self::assertStringContainsString('grant_type=refresh_token', $this->requests[0]['options']['body']);
        self::assertStringContainsString('refresh_token=refresh', $this->requests[0]['options']['body']);
        self::assertSame('refreshed', $this->encryptor->decrypt($account->getAccessToken()));
        self::assertContains('Authorization: Bearer refreshed', $this->requests[1]['options']['headers']);
    }

    public function testMissingRefreshTokenMeansAuthorizationLost(): void
    {
        $account = Fixtures::account(null, false);

        try {
            $this->client([])->listCalendars($account);
            self::fail('Expected exception');
        } catch (GoogleApiException $ex) {
            self::assertTrue($ex->isAuthorizationLost());
        }
    }

    public function testEventsArePaginatedAndQueriedForThePeriod(): void
    {
        $client = $this->client([
            $this->json(['items' => [['id' => 'e1']], 'nextPageToken' => 'page-2']),
            $this->json(['items' => [['id' => 'e2']]]),
        ]);
        $account = $this->connectedAccount();
        $account->setCalendarId('team@group.calendar.google.com');

        $events = $client->listEvents($account, new \DateTimeImmutable('2026-09-21T00:00:00-03:00'), new \DateTimeImmutable('2026-09-28T00:00:00-03:00'));

        self::assertSame(['e1', 'e2'], array_column($events, 'id'));
        self::assertStringContainsString('/calendars/team%40group.calendar.google.com/events', $this->requests[0]['url']);
        parse_str(parse_url($this->requests[0]['url'], PHP_URL_QUERY), $query);
        self::assertSame('2026-09-21T00:00:00-03:00', $query['timeMin']);
        self::assertSame('true', $query['singleEvents']);
        self::assertStringContainsString('pageToken=page-2', $this->requests[1]['url']);
    }

    public function testTasksAreQueriedByCompletionDate(): void
    {
        $client = $this->client([
            $this->json(['items' => [['id' => 'list-1']]]),
            $this->json(['items' => [['id' => 't1']]]),
        ]);
        $account = $this->connectedAccount();

        self::assertSame([['id' => 'list-1']], $client->listTaskLists($account));
        $tasks = $client->listCompletedTasks($account, 'list-1', new \DateTimeImmutable('2026-09-21T00:00:00Z'), new \DateTimeImmutable('2026-09-28T00:00:00Z'));

        self::assertSame([['id' => 't1']], $tasks);
        parse_str(parse_url($this->requests[1]['url'], PHP_URL_QUERY), $query);
        self::assertSame('2026-09-21T00:00:00+00:00', $query['completedMin']);
        self::assertSame('2026-09-28T00:00:00+00:00', $query['completedMax']);
        self::assertSame('true', $query['showHidden']);
    }

    public function testApiErrorsAreWrapped(): void
    {
        $client = $this->client([$this->json(['error' => ['message' => 'Not Found']], 404)]);

        try {
            $client->listTaskLists($this->connectedAccount());
            self::fail('Expected exception');
        } catch (GoogleApiException $ex) {
            self::assertFalse($ex->isAuthorizationLost());
            self::assertStringContainsString('Not Found', $ex->getMessage());
        }
    }

    public function testUnauthorizedApiResponseMeansAuthorizationLost(): void
    {
        $client = $this->client([$this->json(['error' => ['message' => 'Invalid Credentials']], 401)]);

        try {
            $client->listCalendars($this->connectedAccount());
            self::fail('Expected exception');
        } catch (GoogleApiException $ex) {
            self::assertTrue($ex->isAuthorizationLost());
        }
    }

    public function testTransportErrorsAreWrapped(): void
    {
        $this->expectException(GoogleApiException::class);
        $this->expectExceptionMessage('request failed');

        $this->client([fn () => throw new TransportException('timeout')])->listCalendars($this->connectedAccount());
    }

    public function testTokenTransportErrorsAreWrapped(): void
    {
        $this->expectException(GoogleApiException::class);
        $this->expectExceptionMessage('token request failed');

        $this->client([fn () => throw new TransportException('timeout')])->authorize(Fixtures::account(null, false), 'code', 'https://kimai/cb');
    }

    public function testRevokeSendsRefreshToken(): void
    {
        $client = $this->client([$this->json([])]);

        $client->revoke($this->connectedAccount());

        self::assertStringContainsString('oauth2.googleapis.com/revoke', $this->requests[0]['url']);
        self::assertStringContainsString('token=refresh', $this->requests[0]['options']['body']);
    }

    public function testRevokeIgnoresFailuresAndMissingToken(): void
    {
        $this->client([])->revoke(Fixtures::account(null, false));
        $this->client([fn () => throw new TransportException('down')])->revoke($this->connectedAccount());

        self::assertCount(1, $this->requests);
    }
}
