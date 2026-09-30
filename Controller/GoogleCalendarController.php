<?php

/*
 * This file is part of the Kimai Google Calendar plugin.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\GoogleCalendarBundle\Controller;

use App\Controller\AbstractController;
use App\Utils\PageSetup;
use KimaiPlugin\GoogleCalendarBundle\Entity\GoogleCalendarAccount;
use KimaiPlugin\GoogleCalendarBundle\Form\GoogleCalendarSettingsForm;
use KimaiPlugin\GoogleCalendarBundle\Repository\GoogleCalendarAccountRepository;
use KimaiPlugin\GoogleCalendarBundle\Repository\GoogleCalendarLinkRepository;
use KimaiPlugin\GoogleCalendarBundle\Service\CalendarImportService;
use KimaiPlugin\GoogleCalendarBundle\Service\GoogleApiClient;
use KimaiPlugin\GoogleCalendarBundle\Service\GoogleApiException;
use KimaiPlugin\GoogleCalendarBundle\Service\PluginConfiguration;
use KimaiPlugin\GoogleCalendarBundle\Service\TargetResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('create_own_timesheet')]
final class GoogleCalendarController extends AbstractController
{
    private const SESSION_STATE = 'google_calendar_oauth_state';
    private const SESSION_LOCALE = 'google_calendar_oauth_locale';
    private const MAX_PERIOD_DAYS = 62;

    public function __construct(
        private readonly GoogleCalendarAccountRepository $accountRepository,
        private readonly GoogleCalendarLinkRepository $linkRepository,
        private readonly GoogleApiClient $client,
        private readonly CalendarImportService $importService,
        private readonly TargetResolver $resolver,
        private readonly PluginConfiguration $configuration,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/{_locale}/google-calendar', name: 'google_calendar', methods: ['GET', 'POST'])]
    public function indexAction(Request $request): Response
    {
        $account = $this->accountRepository->getOrCreate($this->getUser());
        $form = null;
        // listing the calendars disconnects the account if Google revoked the access
        $calendars = $account->isConnected() ? $this->getCalendarChoices($account) : [];

        if ($account->isConnected()) {
            $form = $this->createForm(GoogleCalendarSettingsForm::class, $account, [
                'calendars' => $calendars,
                'action' => $this->generateUrl('google_calendar'),
            ]);
            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {
                $this->accountRepository->save($account);
                $this->flashSuccess('action.update.success');

                return $this->redirectToRoute('google_calendar');
            }
        }

        return $this->render('@GoogleCalendar/index.html.twig', [
            'page_setup' => new PageSetup('Google Calendar'),
            'account' => $account,
            'form' => $form?->createView(),
            'configured' => $this->configuration->isConfigured(),
            'redirect_uri' => $this->getRedirectUri(),
            'links' => $account->getId() !== null ? $this->linkRepository->findLatest($account) : [],
            'period' => $this->getPeriod($request),
        ]);
    }

    #[Route(path: '/{_locale}/google-calendar/connect', name: 'google_calendar_connect', methods: ['GET'])]
    public function connectAction(Request $request): Response
    {
        if (!$this->configuration->isConfigured()) {
            $this->flashError('gcal.not_configured');

            return $this->redirectToRoute('google_calendar');
        }

        $state = bin2hex(random_bytes(16));
        $request->getSession()->set(self::SESSION_STATE, $state);
        $request->getSession()->set(self::SESSION_LOCALE, $request->getLocale());

        return $this->redirect($this->client->getAuthorizationUrl($this->getRedirectUri(), $state));
    }

    /**
     * Has no locale in the path, as Google requires a fixed redirect URI.
     */
    #[Route(path: '/google-calendar/oauth/callback', name: 'google_calendar_callback', methods: ['GET'])]
    public function callbackAction(Request $request): Response
    {
        $session = $request->getSession();
        $expectedState = $session->get(self::SESSION_STATE);
        $locale = $session->get(self::SESSION_LOCALE, $request->getDefaultLocale());
        $session->remove(self::SESSION_STATE);
        $session->remove(self::SESSION_LOCALE);

        // flash messages with parameters are translated immediately, but this route has no locale
        $request->setLocale($locale);
        if ($this->translator instanceof LocaleAwareInterface) {
            $this->translator->setLocale($locale);
        }

        $state = (string) $request->query->get('state');
        $code = (string) $request->query->get('code');

        if ($expectedState === null || !hash_equals($expectedState, $state)) {
            $this->flashError('gcal.connect_failed', $this->translator->trans('gcal.error_invalid_state', [], 'flashmessages'));
        } elseif ($code === '') {
            // e.g. "access_denied" when the user cancels the consent screen
            $this->flashError('gcal.connect_failed', $this->translator->trans('gcal.error_not_authorized', [
                '%error%' => (string) $request->query->get('error', '-'),
            ], 'flashmessages'));
        } else {
            $account = $this->accountRepository->getOrCreate($this->getUser());
            try {
                $this->client->authorize($account, $code, $this->getRedirectUri());
                $this->accountRepository->save($account);
                $this->flashSuccess('gcal.connected');
            } catch (GoogleApiException $ex) {
                $this->flashError('gcal.connect_failed', $this->describe($ex));
            }
        }

        return $this->redirectToRoute('google_calendar', ['_locale' => $locale]);
    }

    #[Route(path: '/{_locale}/google-calendar/disconnect', name: 'google_calendar_disconnect', methods: ['POST'])]
    public function disconnectAction(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('google_calendar_disconnect', (string) $request->request->get('_token'))) {
            $this->flashError('action.csrf.error');

            return $this->redirectToRoute('google_calendar');
        }

        $account = $this->accountRepository->findByUser($this->getUser());
        if ($account !== null) {
            $this->client->revoke($account);
            $account->disconnect();
            $this->accountRepository->save($account);
        }
        $this->flashSuccess('gcal.disconnected');

        return $this->redirectToRoute('google_calendar');
    }

    #[Route(path: '/{_locale}/google-calendar/review', name: 'google_calendar_review', methods: ['GET', 'POST'])]
    public function reviewAction(Request $request): Response
    {
        $account = $this->accountRepository->findByUser($this->getUser());
        if ($account === null || !$account->isConnected()) {
            return $this->redirectToRoute('google_calendar');
        }

        $period = $this->getPeriod($request);
        if ($period['error'] !== null) {
            $this->flashError('gcal.invalid_period', $period['error']);

            return $this->redirectToRoute('google_calendar');
        }

        $this->resolver->load($this->getUser());

        try {
            $items = $this->importService->loadItems($account, $period['from'], $period['until']);
        } catch (GoogleApiException $ex) {
            $this->flashError('gcal.connect_failed', $this->describe($ex));

            return $this->redirectToRoute('google_calendar');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('google_calendar_review', (string) $request->request->get('_token'))) {
                $this->flashError('action.csrf.error');

                return $this->redirectToRoute('google_calendar_review', $period['query']);
            }

            $rows = $request->request->all('rows');
            $this->importService->applyInput($account, $items, $rows);
            $created = $this->importService->register($account, $items);

            $failed = array_filter($items, fn ($item) => $item->error !== null);
            if (\count($failed) === 0) {
                if ($created > 0) {
                    $this->flashSuccess('gcal.registered');
                }

                return $this->redirectToRoute('google_calendar_review', $period['query']);
            }

            $this->flashWarning('gcal.registered_with_errors');
        }

        return $this->render('@GoogleCalendar/review.html.twig', [
            'page_setup' => new PageSetup('Google Calendar'),
            'items' => $items,
            'period' => $period,
            'projects' => $this->resolver->getProjects(),
            'activities' => $this->resolver->getActivities(),
        ]);
    }

    /**
     * The import period from the query string, by default the current week until today.
     *
     * @return array{from: \DateTime, until: \DateTime, to: \DateTime, query: array<string, string>, error: string|null}
     */
    private function getPeriod(Request $request): array
    {
        $timezone = new \DateTimeZone($this->getUser()->getTimezone());
        $today = new \DateTime('today', $timezone);
        $from = \DateTime::createFromFormat('!Y-m-d', (string) $request->query->get('from'), $timezone) ?: (clone $today)->modify('monday this week');
        $to = \DateTime::createFromFormat('!Y-m-d', (string) $request->query->get('to'), $timezone) ?: clone $today;

        $error = null;
        if ($to < $from) {
            $error = $this->translator->trans('gcal.error_period_reversed', [], 'flashmessages');
        } elseif ($from->diff($to)->days >= self::MAX_PERIOD_DAYS) {
            $error = $this->translator->trans('gcal.error_period_too_long', ['%days%' => (string) self::MAX_PERIOD_DAYS], 'flashmessages');
        }

        return [
            'from' => $from,
            'until' => (clone $to)->modify('+1 day'),
            'to' => $to,
            'query' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
            'error' => $error,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function getCalendarChoices(GoogleCalendarAccount $account): array
    {
        $choices = [];

        try {
            foreach ($this->client->listCalendars($account) as $calendar) {
                $label = $calendar['summaryOverride'] ?? $calendar['summary'] ?? $calendar['id'];
                if ($calendar['primary'] ?? false) {
                    $choices[$this->translator->trans('gcal.primary_calendar', ['%name%' => $label])] = 'primary';
                } else {
                    $choices[$label] = $calendar['id'];
                }
            }
        } catch (GoogleApiException $ex) {
            if ($ex->isAuthorizationLost()) {
                $account->disconnect();
            }
            $this->flashError('gcal.connect_failed', $this->describe($ex));
        } finally {
            // the access token might have been refreshed (or the account disconnected)
            $this->accountRepository->save($account);
        }

        if (!\in_array('primary', $choices, true)) {
            $choices[$this->translator->trans('gcal.primary_calendar_fallback')] = 'primary';
        }
        if (!\in_array($account->getCalendarId(), $choices, true)) {
            $choices[$account->getCalendarId()] = $account->getCalendarId();
        }

        return $choices;
    }

    /**
     * The user facing, translated reason of a Google failure.
     */
    private function describe(GoogleApiException $ex): string
    {
        if ($ex->getTranslationKey() === null) {
            return $ex->getMessage();
        }

        return $this->translator->trans($ex->getTranslationKey(), $ex->getTranslationParameters(), 'flashmessages');
    }

    private function getRedirectUri(): string
    {
        return $this->generateUrl('google_calendar_callback', [], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
