<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\File\FilesUiPreferenceService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controller FilesUiPreferenceController.
 */
class FilesUiPreferenceController extends AbstractController
{
    private const CSRF_FILES_UI_PREFERENCES = 'files_ui_preferences';

    /**
     * @brief Build files UI preference controller.
     * @param FilesUiPreferenceService $preferenceService Files UI preference service.
     * @param CsrfTokenManagerInterface $csrfTokenManager CSRF token manager.
     * @param LoggerInterface $logger Application logger.
     * @return void
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function __construct(
        private readonly FilesUiPreferenceService $preferenceService,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @brief Return files UI preferences for current user/device.
     * @param Request $request HTTP request with deviceId query parameter.
     * @return JsonResponse
     * @date 2026-05-03
     * @author Stephane H.
     */
    #[Route('/api/ui-preferences/files', name: 'files_ui_preferences_get', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getFilesPreferences(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $deviceId = (string) $request->query->get('deviceId', '');
        $normalizedDeviceId = $this->preferenceService->normalizeDeviceId($deviceId);
        if ($normalizedDeviceId === '') {
            return new JsonResponse([
                'status' => 'ok',
                'source' => 'defaults',
                'preferences' => $this->preferenceService->getDefaultPreferences(),
            ]);
        }

        $result = $this->preferenceService->getFilesPreferences($user, $normalizedDeviceId);

        return new JsonResponse([
            'status' => 'ok',
            'source' => $result['source'],
            'preferences' => $result['preferences'],
        ]);
    }

    /**
     * @brief Save files UI preferences for current user/device.
     * @param Request $request HTTP request with JSON payload.
     * @return JsonResponse
     * @date 2026-05-03
     * @author Stephane H.
     */
    #[Route('/api/ui-preferences/files', name: 'files_ui_preferences_save', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function saveFilesPreferences(Request $request): JsonResponse
    {
        $csrfHeader = (string) $request->headers->get('X-CSRF-Token', '');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_FILES_UI_PREFERENCES, $csrfHeader))) {
            return new JsonResponse(['status' => 'error', 'message' => 'files.flash.csrf_invalid'], 403);
        }

        /** @var User $user */
        $user = $this->getUser();
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['status' => 'error', 'message' => 'files.preferences.invalid_payload'], 400);
        }

        $deviceId = is_string($payload['deviceId'] ?? null) ? (string) $payload['deviceId'] : '';
        $normalizedDeviceId = $this->preferenceService->normalizeDeviceId($deviceId);
        if ($normalizedDeviceId === '') {
            return new JsonResponse(['status' => 'error', 'message' => 'files.preferences.invalid_device_id'], 400);
        }

        $preferences = $payload['preferences'] ?? [];
        if (!is_array($preferences)) {
            $preferences = [];
        }

        try {
            $result = $this->preferenceService->saveFilesPreferences($user, $normalizedDeviceId, $preferences);

            return new JsonResponse([
                'status' => 'ok',
                'persisted' => $result['persisted'],
                'preferences' => $result['preferences'],
            ]);
        } catch (\Throwable $exception) {
            $this->logger->warning('Failed to save files UI preferences.', [
                'userId' => $user->getId(),
                'deviceId' => $normalizedDeviceId,
                'error' => $exception->getMessage(),
            ]);

            return new JsonResponse(['status' => 'error', 'message' => 'files.preferences.save_failed'], 500);
        }
    }
}
