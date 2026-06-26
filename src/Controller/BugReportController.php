<?php

namespace App\Controller;

use App\Entity\BugReport;
use App\Entity\User;
use App\Repository\BugReportRepository;
use App\Service\BugReport\BugReportScreenshotStorage;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controller BugReportController.
 */
class BugReportController
{
    private const CSRF_SUBMIT = 'bug_report_submit';

    /**
     * @brief Build bug report submit controller.
     * @param BugReportRepository $bugReportRepository Bug report repository.
     * @param BugReportScreenshotStorage $bugReportScreenshotStorage Screenshot storage service.
     * @param Security $security Security helper.
     * @param CsrfTokenManagerInterface $csrfTokenManager CSRF token manager.
     * @param LoggerInterface $bugReportLogger Bug report logger.
     * @param string $appVersion Application version string.
     * @return void
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function __construct(
        private readonly BugReportRepository $bugReportRepository,
        private readonly BugReportScreenshotStorage $bugReportScreenshotStorage,
        private readonly Security $security,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        #[Autowire(service: 'monolog.logger.bug_report')]
        private readonly LoggerInterface $bugReportLogger,
        private readonly string $appVersion = ''
    ) {
    }

    /**
     * @brief Persist bug report submitted from floating actions modal.
     * @param Request $request Current request.
     * @return Response
     * @date 2026-05-06
     * @author Stephane H.
     */
    #[IsGranted('ROLE_USER')]
    #[Route('/bug-report/submit', name: 'bug_report_submit', methods: ['POST'])]
    public function submit(Request $request): Response
    {
        $actorUser = $this->security->getUser();
        if (!$actorUser instanceof User || $actorUser->getId() === null) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        $csrfToken = (string) $request->request->get('_csrf_token', '');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_SUBMIT, $csrfToken))) {
            $this->bugReportLogger->warning('Bug report rejected because of invalid CSRF token.');
            if ($request->hasSession()) {
                $request->getSession()->getFlashBag()->add('danger', 'bug_report.flash.invalid_csrf');
            }

            return $this->backToSource($request);
        }

        $actionDescription = trim((string) $request->request->get('action_description', ''));
        $observedResult = trim((string) $request->request->get('observed_result', ''));
        $expectedResult = trim((string) $request->request->get('expected_result', ''));
        $severity = trim((string) $request->request->get('severity', BugReport::SEVERITY_MINOR));
        $sourcePath = trim((string) $request->request->get('source_path', (string) $request->headers->get('referer', '/')));
        $sourceQuery = trim((string) $request->request->get('source_query', ''));
        $routeName = trim((string) $request->request->get('source_route', ''));
        $locale = trim((string) $request->request->get('source_locale', (string) $request->getLocale()));
        $theme = trim((string) $request->request->get('source_theme', (string) $request->attributes->get('app_theme', 'light')));
        $referrer = trim((string) $request->headers->get('referer', ''));
        $correlationId = trim((string) $request->attributes->get('request_id', (string) $request->headers->get('x-request-id', '')));
        $appVersion = trim($this->appVersion);
        $viewportWidth = (int) $request->request->get('viewport_width', 0);
        $viewportHeight = (int) $request->request->get('viewport_height', 0);
        $timelineJson = trim((string) $request->request->get('action_timeline', ''));
        $timelineData = $this->decodeTimeline($timelineJson);

        if ($actionDescription === '' || $observedResult === '') {
            $this->bugReportLogger->warning('Bug report rejected because required fields are missing.');
            if ($request->hasSession()) {
                $request->getSession()->getFlashBag()->add('danger', 'bug_report.flash.missing_required');
            }

            return $this->backToSource($request);
        }

        $bugReport = new BugReport(
            $actorUser,
            mb_substr($actionDescription, 0, 4000),
            mb_substr($observedResult, 0, 4000),
            $expectedResult !== '' ? mb_substr($expectedResult, 0, 4000) : null,
            $routeName !== '' ? mb_substr($routeName, 0, 255) : null,
            mb_substr($sourcePath !== '' ? $sourcePath : '/', 0, 2048),
            $sourceQuery !== '' ? mb_substr($sourceQuery, 0, 4000) : null,
            mb_substr($locale !== '' ? $locale : 'fr', 0, 10),
            mb_substr($theme !== '' ? $theme : 'light', 0, 10),
            $request->headers->get('user-agent'),
            $viewportWidth > 0 ? $viewportWidth : null,
            $viewportHeight > 0 ? $viewportHeight : null,
            $referrer !== '' ? mb_substr($referrer, 0, 4000) : null,
            $correlationId !== '' ? mb_substr($correlationId, 0, 128) : null,
            $appVersion !== '' ? mb_substr($appVersion, 0, 64) : null,
            $timelineData
        );
        $bugReport->setSeverity($severity);
        $this->bugReportRepository->save($bugReport);

        $screenshotData = trim((string) $request->request->get('screenshot_data', ''));
        if ($screenshotData !== '' && $this->bugReportScreenshotStorage->saveFromBase64($bugReport, $screenshotData)) {
            $this->bugReportRepository->save($bugReport);
        }

        $this->bugReportLogger->info('Bug report created from floating actions modal.', [
            'bug_report_id' => $bugReport->getId(),
            'source_route' => $routeName,
            'source_path' => $sourcePath,
            'severity' => $bugReport->getSeverity(),
        ]);

        if ($request->hasSession()) {
            $request->getSession()->getFlashBag()->add('success', 'bug_report.flash.created');
        }

        return $this->backToSource($request);
    }

    /**
     * @brief Decode and validate timeline JSON payload.
     * @param string $timelineJson Raw timeline JSON payload.
     * @return array<int, mixed>|null
     * @date 2026-05-06
     * @author Stephane H.
     */
    private function decodeTimeline(string $timelineJson): ?array
    {
        if ($timelineJson === '') {
            return null;
        }

        try {
            $decoded = json_decode($timelineJson, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        /** @var array<int, mixed> $timeline */
        $timeline = array_values(array_slice($decoded, -100));

        return $timeline;
    }

    /**
     * @brief Redirect user back to source page after submit.
     * @param Request $request Current request.
     * @return RedirectResponse
     * @date 2026-05-06
     * @author Stephane H.
     */
    private function backToSource(Request $request): RedirectResponse
    {
        $target = trim((string) $request->request->get('source_path', ''));
        $query = trim((string) $request->request->get('source_query', ''));
        if ($target === '' || !str_starts_with($target, '/')) {
            $target = '/';
        }
        if ($query !== '') {
            $target .= '?'.$query;
        }

        return new RedirectResponse($target);
    }
}
