<?php

declare(strict_types=1);

namespace App\Service\Site;

use App\Entity\SiteAccessGateSettings;
use App\Repository\SiteAccessGateSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @brief Resolve and persist site access gate settings.
 */
final class SiteAccessGateService
{
    /**
     * @param SiteAccessGateSettingsRepository $settingsRepository Gate settings repository.
     * @param SiteAccessGateSessionService $sessionService Gate session helper.
     * @param EntityManagerInterface $entityManager Doctrine entity manager.
     */
    public function __construct(
        private readonly SiteAccessGateSettingsRepository $settingsRepository,
        private readonly SiteAccessGateSessionService $sessionService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @brief Load singleton gate settings.
     *
     * @param void No input parameter.
     * @return SiteAccessGateSettings
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function getSettings(): SiteAccessGateSettings
    {
        return $this->settingsRepository->getOrCreateSingleton();
    }

    /**
     * @brief Check whether gate enforcement is active.
     *
     * @param void No input parameter.
     * @return bool
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function isGateEnabled(): bool
    {
        return $this->getSettings()->isEnabled();
    }

    /**
     * @brief Check whether current visitor may browse without seeing the gate.
     *
     * @param bool $adminBypass Whether caller has ROLE_ADMIN.
     * @return bool
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function isBypassGranted(bool $adminBypass): bool
    {
        if ($adminBypass) {
            return true;
        }

        return $this->sessionService->isAccessGranted();
    }

    /**
     * @brief Verify submitted bypass note and grant session access when valid.
     *
     * @param string $submittedNote Visitor submitted note.
     * @return bool True when note matches configured secret.
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function verifyBypassNoteAndGrant(string $submittedNote): bool
    {
        $expected = trim((string) $this->getSettings()->getBypassNote());
        $submitted = trim($submittedNote);
        if ($expected === '' || $submitted === '') {
            return false;
        }

        if (!hash_equals($expected, $submitted)) {
            return false;
        }

        $this->sessionService->grantAccess();

        return true;
    }

    /**
     * @brief Update gate settings from admin dashboard form.
     *
     * @param bool $enabled Gate enabled flag.
     * @param string $gateMessage Visitor message.
     * @param string $bypassNote Secret bypass note.
     * @return string|null Translation key on validation failure or null.
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function updateSettings(bool $enabled, string $gateMessage, string $bypassNote): ?string
    {
        $normalizedMessage = trim($gateMessage);
        $normalizedNote = trim($bypassNote);

        if ($enabled && $normalizedNote === '') {
            return 'storage.access_gate.error.bypass_note_required';
        }

        $settings = $this->getSettings();
        $settings->setEnabled($enabled);
        $settings->setGateMessage($normalizedMessage !== '' ? $normalizedMessage : null);
        $settings->setBypassNote($normalizedNote !== '' ? $normalizedNote : null);
        $this->entityManager->flush();

        return null;
    }
}
