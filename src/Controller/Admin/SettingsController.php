<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Site\SiteAccessGateService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @brief Admin dashboard for platform-wide configuration.
 */
#[IsGranted('ROLE_ADMIN')]
final class SettingsController extends AbstractController
{
    private const CSRF_ACCESS_GATE = 'storage_access_gate_admin';

    /**
     * @param SiteAccessGateService $siteAccessGateService Gate settings service.
     * @param CsrfTokenManagerInterface $csrfTokenManager CSRF token manager.
     * @param int $defaultQuotaBytes Platform default storage quota in bytes.
     */
    public function __construct(
        private readonly SiteAccessGateService $siteAccessGateService,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly int $defaultQuotaBytes,
    ) {
    }

    /**
     * @brief Render platform configuration dashboard.
     *
     * @return Response
     * @date 2026-06-22
     * @author Stephane H.
     */
    #[Route('/admin/settings', name: 'admin_settings_index', methods: ['GET'])]
    public function index(): Response
    {
        $accessGateSettings = $this->siteAccessGateService->getSettings();

        return $this->render('admin/settings/index.html.twig', [
            'accessGateSettings' => $accessGateSettings,
            'defaultQuotaBytes' => $this->defaultQuotaBytes,
            'accessGateCsrfToken' => self::CSRF_ACCESS_GATE,
        ]);
    }

    /**
     * @brief Persist site access gate settings from admin dashboard.
     *
     * @param Request $request Current HTTP request.
     * @return Response
     * @date 2026-06-22
     * @author Stephane H.
     */
    #[Route('/admin/settings/access-gate', name: 'admin_settings_access_gate_update', methods: ['POST'])]
    public function updateAccessGate(Request $request): Response
    {
        $tokenValue = $request->request->getString('_csrf_token');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_ACCESS_GATE, $tokenValue))) {
            $this->addFlash('danger', 'storage.access_gate.error.csrf_invalid');

            return $this->redirectToRoute('admin_settings_index');
        }

        $errorKey = $this->siteAccessGateService->updateSettings(
            $request->request->getBoolean('access_gate_enabled'),
            $request->request->getString('access_gate_message'),
            $request->request->getString('access_gate_bypass_note'),
        );

        if ($errorKey !== null) {
            $this->addFlash('danger', $errorKey);
        } else {
            $this->addFlash('success', 'storage.access_gate.success.settings_updated');
        }

        return $this->redirectToRoute('admin_settings_index');
    }
}
