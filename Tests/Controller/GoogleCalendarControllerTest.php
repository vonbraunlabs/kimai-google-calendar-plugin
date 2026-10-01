<?php

/*
 * This file is part of the Kimai Google Calendar plugin.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\GoogleCalendarBundle\Tests\Controller;

use App\Entity\User;
use KimaiPlugin\GoogleCalendarBundle\Controller\GoogleCalendarController;
use KimaiPlugin\GoogleCalendarBundle\Entity\GoogleCalendarAccount;
use KimaiPlugin\GoogleCalendarBundle\Repository\GoogleCalendarAccountRepository;
use KimaiPlugin\GoogleCalendarBundle\Repository\GoogleCalendarLinkRepository;
use KimaiPlugin\GoogleCalendarBundle\Service\CalendarImportService;
use KimaiPlugin\GoogleCalendarBundle\Service\GoogleApiClient;
use KimaiPlugin\GoogleCalendarBundle\Service\GoogleApiException;
use KimaiPlugin\GoogleCalendarBundle\Service\ImportItem;
use KimaiPlugin\GoogleCalendarBundle\Service\PluginConfiguration;
use KimaiPlugin\GoogleCalendarBundle\Service\TargetResolver;
use KimaiPlugin\GoogleCalendarBundle\Tests\Fixtures;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;

/**
 * @covers \KimaiPlugin\GoogleCalendarBundle\Controller\GoogleCalendarController
 */
class GoogleCalendarControllerTest extends TestCase
{
    private User $user;
    private ?GoogleCalendarAccount $account = null;
    private GoogleApiClient&MockObject $client;
    private CalendarImportService&MockObject $importService;
    private GoogleCalendarAccountRepository&MockObject $accountRepository;
    private FormInterface&MockObject $form;
    private Session $session;
    private Translator $translator;
    private bool $csrfValid = true;
    private bool $configured = true;
    /** @var array{0: string, 1: array<string, mixed>}|null */
    private ?array $rendered = null;
    /** @var array<string, mixed>|null */
    private ?array $formOptions = null;

    protected function setUp(): void
    {
        $this->user = Fixtures::user();
        $this->client = $this->createMock(GoogleApiClient::class);
        $this->importService = $this->createMock(CalendarImportService::class);
        $this->accountRepository = $this->createMock(GoogleCalendarAccountRepository::class);
        $this->accountRepository->method('findByUser')->willReturnCallback(fn () => $this->account);
        $this->accountRepository->method('getOrCreate')->willReturnCallback(fn (User $user) => $this->account ??= new GoogleCalendarAccount($user));
        $this->form = $this->createMock(FormInterface::class);
        $this->form->method('createView')->willReturn(new FormView());
        $this->session = new Session(new MockArraySessionStorage());
        $this->translator = new Translator('en');
    }

    private function controller(Request $request): GoogleCalendarController
    {
        $request->setSession($this->session);
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken($this->user, 'secured_area', ['ROLE_USER']));

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(fn (string $route, array $parameters = [], int $type = UrlGeneratorInterface::ABSOLUTE_PATH) => ($type === UrlGeneratorInterface::ABSOLUTE_URL ? 'https://kimai.test' : '') . '/' . $route . ($parameters ? '?' . http_build_query($parameters) : ''));

        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturnCallback(function (string $view, array $parameters) {
            $this->rendered = [$view, $parameters];

            return 'rendered ' . $view;
        });

        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(fn () => $this->csrfValid);

        $formFactory = $this->createMock(FormFactoryInterface::class);
        $formFactory->method('create')->willReturnCallback(function (string $type, mixed $data, array $options) {
            $this->formOptions = $options;

            return $this->form;
        });

        $container = new Container();
        $container->set('request_stack', $requestStack);
        $container->set('security.token_storage', $tokenStorage);
        $container->set('router', $router);
        $container->set('twig', $twig);
        $container->set('security.csrf.token_manager', $csrf);
        $container->set('form.factory', $formFactory);
        $container->set('translator', $this->translator);

        $values = $this->configured ? [PluginConfiguration::CLIENT_ID => 'id', PluginConfiguration::CLIENT_SECRET => 'secret'] : [];

        $controller = new GoogleCalendarController(
            $this->accountRepository,
            $this->createMock(GoogleCalendarLinkRepository::class),
            $this->client,
            $this->importService,
            $this->createMock(TargetResolver::class),
            new PluginConfiguration(Fixtures::systemConfiguration($values)),
            $this->translator,
        );
        $controller->setContainer($container);

        return $controller;
    }

    private function flashes(string $type): array
    {
        return $this->session->getFlashBag()->get($type);
    }

    /**
     * Without translations the translator returns the keys; this adds some, to check parameters.
     *
     * @param array<string, string> $messages
     */
    private function translations(array $messages, string $domain = 'flashmessages'): void
    {
        $this->translator->addLoader('array', new ArrayLoader());
        $this->translator->addResource('array', $messages, 'en', $domain);
    }

    private const REASONS = [
        'gcal.connect_failed' => 'failed: %reason%',
        'gcal.invalid_period' => 'invalid: %reason%',
        'gcal.error_invalid_state' => 'invalid state',
        'gcal.error_not_authorized' => 'not authorized (%error%)',
        'gcal.error_authorization' => 'refused: %message%',
        'gcal.error_period_reversed' => 'reversed',
        'gcal.error_period_too_long' => 'max %days% days',
    ];

    private function assertRedirect(Response $response, string $url): void
    {
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame($url, $response->getTargetUrl());
    }

    public function testIndexWithoutConnection(): void
    {
        $this->configured = false;
        $response = $this->controller(new Request())->indexAction(new Request());

        self::assertSame('rendered @GoogleCalendar/index.html.twig', $response->getContent());
        [, $parameters] = $this->rendered;
        self::assertNull($parameters['form']);
        self::assertFalse($parameters['configured']);
        self::assertSame('https://kimai.test/google_calendar_callback', $parameters['redirect_uri']);
        self::assertSame([], $parameters['links']);
        self::assertSame('monday', strtolower($parameters['period']['from']->format('l')));
    }

    public function testIndexShowsSettingsWithCalendars(): void
    {
        $this->account = Fixtures::account($this->user);
        $this->account->setCalendarId('old@group');
        $this->translations(['gcal.primary_calendar' => '%name% (primary)'], 'messages');
        $this->client->method('listCalendars')->willReturn([
            ['id' => 'me@gmail.com', 'summary' => 'Me', 'primary' => true],
            ['id' => 'team@group', 'summary' => 'Team', 'summaryOverride' => 'My team'],
        ]);

        $response = $this->controller(new Request())->indexAction(new Request());

        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(FormView::class, $this->rendered[1]['form']);
        self::assertTrue($this->rendered[1]['configured']);
        self::assertSame([
            'Me (primary)' => 'primary',
            'My team' => 'team@group',
            'old@group' => 'old@group',
        ], $this->formOptions['calendars'], 'the current calendar stays selectable even if it is not listed anymore');
    }

    public function testIndexOffersThePrimaryCalendarEvenIfNotListed(): void
    {
        $this->account = Fixtures::account($this->user);
        $this->translations(['gcal.primary_calendar_fallback' => 'Primary calendar'], 'messages');
        $this->client->method('listCalendars')->willReturn([['id' => 'team@group', 'summary' => 'Team']]);

        $this->controller(new Request())->indexAction(new Request());

        self::assertSame(['Team' => 'team@group', 'Primary calendar' => 'primary'], $this->formOptions['calendars']);
    }

    public function testIndexDisconnectsWhenGoogleRevokedTheAccess(): void
    {
        $this->account = Fixtures::account($this->user);
        $this->client->method('listCalendars')->willThrowException(new GoogleApiException('revoked', true));
        $this->accountRepository->expects(self::once())->method('save')->with($this->account);

        $response = $this->controller(new Request())->indexAction(new Request());

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($this->account->isConnected());
        self::assertNull($this->rendered[1]['form'], 'no settings form for a disconnected account');
        self::assertCount(1, $this->flashes('error'));
    }

    public function testIndexKeepsARefreshedTokenWhenListingFails(): void
    {
        $this->account = Fixtures::account($this->user);
        $this->client->method('listCalendars')->willThrowException(new GoogleApiException('rate limit'));
        $this->accountRepository->expects(self::once())->method('save')->with($this->account);

        $this->controller(new Request())->indexAction(new Request());

        self::assertTrue($this->account->isConnected());
    }

    public function testIndexSavesSubmittedSettings(): void
    {
        $this->account = Fixtures::account($this->user);
        $this->client->method('listCalendars')->willThrowException(new GoogleApiException('offline'));
        $this->form->method('isSubmitted')->willReturn(true);
        $this->form->method('isValid')->willReturn(true);
        $this->accountRepository->expects(self::atLeastOnce())->method('save')->with($this->account);

        $response = $this->controller(new Request())->indexAction(new Request());

        $this->assertRedirect($response, '/google_calendar');
        self::assertSame(['action.update.success'], $this->flashes('success'));
        self::assertCount(1, $this->flashes('error'), 'calendar list failure is reported');
    }

    public function testConnectNeedsConfiguration(): void
    {
        $this->configured = false;
        $response = $this->controller(new Request())->connectAction(new Request());

        $this->assertRedirect($response, '/google_calendar');
        self::assertSame(['gcal.not_configured'], $this->flashes('error'));
    }

    public function testConnectRedirectsToGoogleWithState(): void
    {
        $request = new Request();
        $request->setLocale('pt_BR');
        $this->client->method('getAuthorizationUrl')->willReturnCallback(fn (string $uri, string $state) => 'https://accounts.google.com/?state=' . $state . '&redirect_uri=' . $uri);

        $response = $this->controller($request)->connectAction($request);

        $state = $this->session->get('google_calendar_oauth_state');
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $state);
        self::assertSame('pt_BR', $this->session->get('google_calendar_oauth_locale'));
        $this->assertRedirect($response, 'https://accounts.google.com/?state=' . $state . '&redirect_uri=https://kimai.test/google_calendar_callback');
    }

    public function testCallbackRejectsInvalidState(): void
    {
        $this->session->set('google_calendar_oauth_state', 'expected');
        $request = new Request(['state' => 'forged', 'code' => 'c']);
        $this->client->expects(self::never())->method('authorize');

        $this->translations(self::REASONS);

        $response = $this->controller($request)->callbackAction($request);

        $this->assertRedirect($response, '/google_calendar?_locale=en');
        self::assertSame(['failed: invalid state'], $this->flashes('error'));
        self::assertNull($this->session->get('google_calendar_oauth_state'), 'state is single use');
    }

    public function testCallbackWithoutCodeReportsGoogleError(): void
    {
        $this->session->set('google_calendar_oauth_state', 'expected');
        $request = new Request(['state' => 'expected', 'error' => 'access_denied']);
        $this->translations(self::REASONS);

        $this->controller($request)->callbackAction($request);

        self::assertSame(['failed: not authorized (access_denied)'], $this->flashes('error'));
    }

    public function testCallbackConnectsTheAccountInTheOriginalLocale(): void
    {
        $this->session->set('google_calendar_oauth_state', 'expected');
        $this->session->set('google_calendar_oauth_locale', 'pt_BR');
        $request = new Request(['state' => 'expected', 'code' => 'the-code']);
        $this->client->expects(self::once())->method('authorize')->with(self::isInstanceOf(GoogleCalendarAccount::class), 'the-code', 'https://kimai.test/google_calendar_callback');
        $this->accountRepository->expects(self::once())->method('save');

        $response = $this->controller($request)->callbackAction($request);

        $this->assertRedirect($response, '/google_calendar?_locale=pt_BR');
        self::assertSame(['gcal.connected'], $this->flashes('success'));
        self::assertSame('pt_BR', $this->translator->getLocale());
    }

    public function testCallbackReportsAuthorizationFailure(): void
    {
        $this->session->set('google_calendar_oauth_state', 'expected');
        $request = new Request(['state' => 'expected', 'code' => 'the-code']);
        $this->client->method('authorize')->willThrowException(new GoogleApiException('Google authorization failed: bad client', translationKey: 'gcal.error_authorization', translationParameters: ['%message%' => 'bad client']));
        $this->accountRepository->expects(self::never())->method('save');
        $this->translations(self::REASONS);

        $this->controller($request)->callbackAction($request);

        self::assertSame(['failed: refused: bad client'], $this->flashes('error'), 'the translated reason, not the English log message');
    }

    public function testCallbackShowsTheMessageOfUntranslatedFailures(): void
    {
        $this->session->set('google_calendar_oauth_state', 'expected');
        $request = new Request(['state' => 'expected', 'code' => 'the-code']);
        $this->client->method('authorize')->willThrowException(new GoogleApiException('invalid_client'));
        $this->translations(self::REASONS);

        $this->controller($request)->callbackAction($request);

        self::assertSame(['failed: invalid_client'], $this->flashes('error'));
    }

    public function testDisconnect(): void
    {
        $this->account = Fixtures::account($this->user);
        $this->client->expects(self::once())->method('revoke')->with($this->account);
        $this->accountRepository->expects(self::once())->method('save')->with($this->account);

        $response = $this->controller(new Request())->disconnectAction(new Request([], ['_token' => 'ok']));

        $this->assertRedirect($response, '/google_calendar');
        self::assertFalse($this->account->isConnected());
        self::assertSame(['gcal.disconnected'], $this->flashes('success'));
    }

    public function testDisconnectNeedsCsrfToken(): void
    {
        $this->account = Fixtures::account($this->user);
        $this->csrfValid = false;

        $this->controller(new Request())->disconnectAction(new Request());

        self::assertTrue($this->account->isConnected());
        self::assertSame(['action.csrf.error'], $this->flashes('error'));
    }

    public function testReviewNeedsConnection(): void
    {
        $this->assertRedirect($this->controller(new Request())->reviewAction(new Request()), '/google_calendar');
    }

    /**
     * @return iterable<string, array{0: array<string, string>, 1: string}>
     */
    public static function invalidPeriods(): iterable
    {
        yield 'end before start' => [['from' => '2026-09-28', 'to' => '2026-09-21'], 'invalid: reversed'];
        yield 'too long' => [['from' => '2026-01-01', 'to' => '2026-09-21'], 'invalid: max 62 days'];
    }

    /**
     * @dataProvider invalidPeriods
     */
    public function testReviewRejectsInvalidPeriods(array $query, string $flash): void
    {
        $this->account = Fixtures::account($this->user);
        $this->importService->expects(self::never())->method('loadItems');
        $this->translations(self::REASONS);

        $response = $this->controller(new Request())->reviewAction(new Request($query));

        $this->assertRedirect($response, '/google_calendar');
        self::assertSame([$flash], $this->flashes('error'));
    }

    public function testReviewReportsGoogleErrors(): void
    {
        $this->account = Fixtures::account($this->user);
        $this->importService->method('loadItems')->willThrowException(new GoogleApiException('revoked', true));

        $response = $this->controller(new Request())->reviewAction(new Request(['from' => '2026-09-21', 'to' => '2026-09-27']));

        $this->assertRedirect($response, '/google_calendar');
        self::assertCount(1, $this->flashes('error'));
    }

    public function testReviewShowsTheItemsOfThePeriod(): void
    {
        $this->account = Fixtures::account($this->user);
        $item = new ImportItem('event', 'e1', 'Title', '', new \DateTime(), new \DateTime(), false);
        $this->importService->expects(self::once())->method('loadItems')
            ->with($this->account, self::callback(fn (\DateTime $from) => $from->format('Y-m-d H:i') === '2026-09-21 00:00'), self::callback(fn (\DateTime $until) => $until->format('Y-m-d H:i') === '2026-09-28 00:00'))
            ->willReturn([$item]);

        $this->controller(new Request())->reviewAction(new Request(['from' => '2026-09-21', 'to' => '2026-09-27']));

        [$view, $parameters] = $this->rendered;
        self::assertSame('@GoogleCalendar/review.html.twig', $view);
        self::assertSame([$item], $parameters['items']);
        self::assertSame(['from' => '2026-09-21', 'to' => '2026-09-27'], $parameters['period']['query']);
    }

    public function testReviewPostRegistersAndRedirects(): void
    {
        $this->account = Fixtures::account($this->user);
        $this->importService->method('loadItems')->willReturn([]);
        $this->importService->expects(self::once())->method('applyInput')->with($this->account, [], ['event:e1' => ['selected' => '1']]);
        $this->importService->method('register')->willReturn(1);
        $request = new Request(['from' => '2026-09-21', 'to' => '2026-09-27'], ['_token' => 'ok', 'rows' => ['event:e1' => ['selected' => '1']]]);
        $request->setMethod('POST');

        $response = $this->controller($request)->reviewAction($request);

        $this->assertRedirect($response, '/google_calendar_review?from=2026-09-21&to=2026-09-27');
        self::assertSame(['gcal.registered'], $this->flashes('success'));
    }

    public function testReviewPostWithErrorsRendersTheTableAgain(): void
    {
        $this->account = Fixtures::account($this->user);
        $item = new ImportItem('event', 'e1', 'Title', '', new \DateTime(), new \DateTime(), false);
        $this->importService->method('loadItems')->willReturn([$item]);
        $this->importService->method('register')->willReturnCallback(function (GoogleCalendarAccount $account, array $items) {
            $items[0]->error = 'Overlapping record';

            return 0;
        });
        $request = new Request([], ['_token' => 'ok', 'rows' => []]);
        $request->setMethod('POST');

        $response = $this->controller($request)->reviewAction($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['gcal.registered_with_errors'], $this->flashes('warning'));
        self::assertSame('@GoogleCalendar/review.html.twig', $this->rendered[0]);
    }

    public function testReviewPostNeedsCsrfToken(): void
    {
        $this->account = Fixtures::account($this->user);
        $this->csrfValid = false;
        $this->importService->method('loadItems')->willReturn([]);
        $this->importService->expects(self::never())->method('register');
        $request = new Request(['from' => '2026-09-21', 'to' => '2026-09-27']);
        $request->setMethod('POST');

        $response = $this->controller($request)->reviewAction($request);

        $this->assertRedirect($response, '/google_calendar_review?from=2026-09-21&to=2026-09-27');
        self::assertSame(['action.csrf.error'], $this->flashes('error'));
    }
}
