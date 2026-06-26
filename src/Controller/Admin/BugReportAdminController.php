<?php

namespace App\Controller\Admin;

use App\Entity\BugReport;
use App\Entity\User;
use App\Repository\BugReportRepository;
use App\Service\BugReport\BugReportScreenshotStorage;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;

/**
 * Controller BugReportAdminController.
 */
#[IsGranted('ROLE_ADMIN')]
class BugReportAdminController
{
    private const CSRF_STATUS = 'admin_bug_report_status';
    private const CSRF_ARCHIVE = 'admin_bug_report_archive';
    private const CSRF_UNARCHIVE = 'admin_bug_report_unarchive';

    /**
     * @brief Build admin bug report controller.
     * @param BugReportRepository $bugReportRepository Bug report repository.
     * @param BugReportScreenshotStorage $bugReportScreenshotStorage Screenshot storage service.
     * @param Security $security Security helper.
     * @param CsrfTokenManagerInterface $csrfTokenManager CSRF token manager.
     * @return void
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function __construct(
        private readonly BugReportRepository $bugReportRepository,
        private readonly BugReportScreenshotStorage $bugReportScreenshotStorage,
        private readonly Security $security,
        private readonly CsrfTokenManagerInterface $csrfTokenManager
    ) {
    }

    /**
     * @brief Render bug report admin listing.
     * @param Environment $twig Twig environment.
     * @param Request $request Current request.
     * @return Response
     * @date 2026-05-06
     * @author Stephane H.
     */
    #[Route('/admin/bug-reports', name: 'admin_bug_reports_index', methods: ['GET'])]
    public function index(Environment $twig, Request $request): Response
    {
        $status = trim((string) $request->query->get('status', ''));
        $severity = trim((string) $request->query->get('severity', ''));
        $routeName = trim((string) $request->query->get('route_name', ''));
        $fromDate = $this->parseDateFilter((string) $request->query->get('from_date', ''), false);
        $toDate = $this->parseDateFilter((string) $request->query->get('to_date', ''), true);
        $includeArchived = (string) $request->query->get('archived', '') === '1';

        $reports = $this->bugReportRepository->findForAdminList($status, $severity, $routeName, $fromDate, $toDate, $includeArchived);

        return new Response($twig->render('admin/bug_reports/index.html.twig', [
            'reports' => $reports,
            'statusFilter' => $status,
            'severityFilter' => $severity,
            'routeFilter' => $routeName,
            'fromDateFilter' => (string) $request->query->get('from_date', ''),
            'toDateFilter' => (string) $request->query->get('to_date', ''),
            'includeArchived' => $includeArchived,
            'csrfStatusToken' => $this->csrfTokenManager->getToken(self::CSRF_STATUS)->getValue(),
            'csrfArchiveToken' => $this->csrfTokenManager->getToken(self::CSRF_ARCHIVE)->getValue(),
            'csrfUnarchiveToken' => $this->csrfTokenManager->getToken(self::CSRF_UNARCHIVE)->getValue(),
        ]));
    }

    /**
     * @brief Render bug report admin detail page.
     * @param Environment $twig Twig environment.
     * @param int $id Bug report identifier.
     * @return Response
     * @date 2026-05-06
     * @author Stephane H.
     */
    #[Route('/admin/bug-reports/{id}', name: 'admin_bug_reports_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Environment $twig, int $id): Response
    {
        $report = $this->bugReportRepository->find($id);
        if (!$report instanceof BugReport) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        return new Response($twig->render('admin/bug_reports/show.html.twig', [
            'report' => $report,
            'csrfStatusToken' => $this->csrfTokenManager->getToken(self::CSRF_STATUS)->getValue(),
            'csrfArchiveToken' => $this->csrfTokenManager->getToken(self::CSRF_ARCHIVE)->getValue(),
            'csrfUnarchiveToken' => $this->csrfTokenManager->getToken(self::CSRF_UNARCHIVE)->getValue(),
        ]));
    }

    /**
     * @brief Stream bug report screenshot for admin review.
     * @param int $id Bug report identifier.
     * @return Response
     * @date 2026-06-26
     * @author Stephane H.
     */
    #[Route('/admin/bug-reports/{id}/screenshot', name: 'admin_bug_reports_screenshot', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function screenshot(int $id): Response
    {
        $report = $this->bugReportRepository->find($id);
        if (!$report instanceof BugReport) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $absolutePath = $this->bugReportScreenshotStorage->getAbsolutePath($report);
        if ($absolutePath === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $response = new BinaryFileResponse($absolutePath);
        $mime = $report->getScreenshotMime();
        if (is_string($mime) && $mime !== '') {
            $response->headers->set('Content-Type', $mime);
        }
        $response->setContentDisposition('inline', basename($absolutePath));

        return $response;
    }

    /**
     * @brief Update bug report workflow status.
     * @param Request $request Current request.
     * @param int $id Bug report identifier.
     * @return Response
     * @date 2026-05-06
     * @author Stephane H.
     */
    #[Route('/admin/bug-reports/{id}/status', name: 'admin_bug_reports_status', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function updateStatus(Request $request, int $id): Response
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_STATUS, (string) $request->request->get('_csrf_token', '')))) {
            return $this->redirectBack($request, '/admin/bug-reports');
        }

        $report = $this->bugReportRepository->find($id);
        $actor = $this->security->getUser();
        if (!$report instanceof BugReport || !$actor instanceof User) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $targetStatus = trim((string) $request->request->get('status', ''));
        if ($targetStatus === BugReport::STATUS_IN_PROGRESS) {
            $report->markInProgress();
        } elseif ($targetStatus === BugReport::STATUS_RESOLVED) {
            $report->markResolved($actor);
        } elseif ($targetStatus === BugReport::STATUS_REOPENED) {
            $report->reopen();
        }

        $this->bugReportRepository->save($report);

        return $this->redirectBack($request, '/admin/bug-reports/'.$id);
    }

    /**
     * @brief Archive bug report ticket.
     * @param Request $request Current request.
     * @param int $id Bug report identifier.
     * @return Response
     * @date 2026-05-06
     * @author Stephane H.
     */
    #[Route('/admin/bug-reports/{id}/archive', name: 'admin_bug_reports_archive', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function archive(Request $request, int $id): Response
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_ARCHIVE, (string) $request->request->get('_csrf_token', '')))) {
            return $this->redirectBack($request, '/admin/bug-reports');
        }

        $report = $this->bugReportRepository->find($id);
        $actor = $this->security->getUser();
        if (!$report instanceof BugReport || !$actor instanceof User) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $report->archive($actor, trim((string) $request->request->get('archive_reason', '')));
        $this->bugReportRepository->save($report);

        return $this->redirectBack($request, '/admin/bug-reports/'.$id);
    }

    /**
     * @brief Remove archive flag from ticket.
     * @param Request $request Current request.
     * @param int $id Bug report identifier.
     * @return Response
     * @date 2026-05-06
     * @author Stephane H.
     */
    #[Route('/admin/bug-reports/{id}/unarchive', name: 'admin_bug_reports_unarchive', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function unarchive(Request $request, int $id): Response
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_UNARCHIVE, (string) $request->request->get('_csrf_token', '')))) {
            return $this->redirectBack($request, '/admin/bug-reports');
        }

        $report = $this->bugReportRepository->find($id);
        if (!$report instanceof BugReport) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $report->unarchive();
        $this->bugReportRepository->save($report);

        return $this->redirectBack($request, '/admin/bug-reports/'.$id);
    }

    /**
     * @brief Redirect to referer fallback URL.
     * @param Request $request Current request.
     * @param string $fallbackPath Fallback path.
     * @return RedirectResponse
     * @date 2026-05-06
     * @author Stephane H.
     */
    private function redirectBack(Request $request, string $fallbackPath): RedirectResponse
    {
        $referer = (string) $request->headers->get('referer', '');

        return new RedirectResponse($referer !== '' ? $referer : $fallbackPath);
    }

    /**
     * @brief Parse date filter value from query string.
     * @param string $dateValue Date filter value.
     * @param bool $endOfDay Whether end-of-day normalization is required.
     * @return \DateTimeImmutable|null
     * @date 2026-05-06
     * @author Stephane H.
     */
    private function parseDateFilter(string $dateValue, bool $endOfDay): ?\DateTimeImmutable
    {
        $value = trim($dateValue);
        if ($value === '') {
            return null;
        }

        try {
            $parsed = new \DateTimeImmutable($value);
            if (\preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
                return $endOfDay ? $parsed->setTime(23, 59, 59) : $parsed->setTime(0, 0, 0);
            }

            return $parsed;
        } catch (\Exception) {
            return null;
        }
    }
}
