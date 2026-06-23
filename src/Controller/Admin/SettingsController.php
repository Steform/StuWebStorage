<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Admin\ServerDiskSpaceService;
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
    private const CSRF_MAINTENANCE = 'storage_maintenance_admin';

    private const CSRF_ANTIBOT = 'storage_antibot_admin';

    /**
     * @param SiteAccessGateService $siteAccessGateService Platform settings service.
     * @param ServerDiskSpaceService $serverDiskSpaceService Server filesystem probe service.
     * @param CsrfTokenManagerInterface $csrfTokenManager CSRF token manager.
     * @param int $defaultQuotaBytes Platform default storage quota in bytes.
     */
    public function __construct(
        private readonly SiteAccessGateService $siteAccessGateService,
        private readonly ServerDiskSpaceService $serverDiskSpaceService,
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
            'serverDiskSpace' => $this->serverDiskSpaceService->buildSnapshot(),
            'maintenanceCsrfToken' => self::CSRF_MAINTENANCE,
            'antibotCsrfToken' => self::CSRF_ANTIBOT,
        ]);
    }

    /**
     * @brief Persist maintenance mode settings from admin dashboard.
     *
     * @param Request $request Current HTTP request.
     * @return Response
     * @date 2026-06-23
     * @author Stephane H.
     */
    #[Route('/admin/settings/maintenance', name: 'admin_settings_maintenance_update', methods: ['POST'])]
    public function updateMaintenance(Request $request): Response
    {
        $tokenValue = $request->request->getString('_csrf_token');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_MAINTENANCE, $tokenValue))) {
            $this->addFlash('danger', 'storage.home.access.flash.invalid_csrf');

            return $this->redirectToRoute('admin_settings_index');
        }

        $this->siteAccessGateService->updateMaintenanceSettings(
            $request->request->getBoolean('maintenance_mode_enabled'),
            $request->request->getString('maintenance_message'),
        );

        $this->addFlash('success', 'maintenance.success.settings_updated');

        return $this->redirectToRoute('admin_settings_index');
    }

    /**
     * @brief Persist antibot threshold from admin dashboard.
     *
     * @param Request $request Current HTTP request.
     * @return Response
     * @date 2026-06-23
     * @author Stephane H.
     */
    #[Route('/admin/settings/antibot', name: 'admin_settings_antibot_update', methods: ['POST'])]
    public function updateAntibot(Request $request): Response
    {
        $tokenValue = $request->request->getString('_csrf_token');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_ANTIBOT, $tokenValue))) {
            $this->addFlash('danger', 'storage.home.access.flash.invalid_csrf');

            return $this->redirectToRoute('admin_settings_index');
        }

        $this->siteAccessGateService->updateAntibotSettings($request->request->getInt('antibot_threshold', 50));

        $this->addFlash('success', 'admin.settings.antibot.success_updated');

        return $this->redirectToRoute('admin_settings_index');
    }
}
