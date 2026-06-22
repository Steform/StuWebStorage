<?php

namespace App\Service\Profile;

use App\Entity\ProfileEmailChangeRequest;
use App\Entity\User;
use App\Repository\ProfileEmailChangeRequestRepository;
use App\Repository\UserRepository;
use App\Service\Admin\TrustedDeviceAdminService;
use App\Service\Auth\TotpChallengeService;
use App\Service\Notification\TotpEmailNotificationService;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Service ProfileUpdateService.
 */
class ProfileUpdateService
{
    /**
     * @brief Build profile update service.
     * @param EntityManagerInterface $entityManager Doctrine entity manager.
     * @param UserRepository $userRepository User repository.
     * @param ProfileEmailChangeRequestRepository $emailChangeRequestRepository Email change request repository.
     * @param TotpChallengeService $totpChallengeService TOTP challenge service.
     * @param TotpEmailNotificationService $totpEmailNotificationService TOTP email sender.
     * @param UserPasswordHasherInterface $passwordHasher Password hasher.
     * @param TrustedDeviceAdminService $trustedDeviceAdminService Trusted device admin service.
     * @return void
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly ProfileEmailChangeRequestRepository $emailChangeRequestRepository,
        private readonly TotpChallengeService $totpChallengeService,
        private readonly TotpEmailNotificationService $totpEmailNotificationService,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly TrustedDeviceAdminService $trustedDeviceAdminService
    ) {
    }

    /**
     * @brief Update user pseudonym from profile.
     * @param User $user Current authenticated user.
     * @param string $pseudonym New pseudonym value.
     * @return string|null Translation key on error or null.
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function updatePseudonym(User $user, string $pseudonym): ?string
    {
        $normalizedPseudonym = trim($pseudonym);
        if ($normalizedPseudonym === '') {
            return 'profile.error.pseudonym_required';
        }

        if (mb_strlen($normalizedPseudonym) > 100) {
            return 'profile.error.pseudonym_too_long';
        }

        $user->setPseudonym($normalizedPseudonym);
        $this->entityManager->flush();

        return null;
    }

    /**
     * @brief Request email change and send TOTP to new email.
     * @param User $user Current authenticated user.
     * @param string $newEmail Requested new email.
     * @return string|null Translation key on error or null.
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function requestEmailChange(User $user, string $newEmail): ?string
    {
        $normalizedEmail = strtolower(trim($newEmail));
        if ($normalizedEmail === '' || !filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
            return 'profile.error.email_invalid';
        }

        if ($normalizedEmail === $user->getEmail()) {
            return 'profile.error.email_same_as_current';
        }

        $existingUser = $this->userRepository->findOneBy(['email' => $normalizedEmail]);
        if ($existingUser instanceof User) {
            return 'profile.error.email_already_used';
        }

        $request = new ProfileEmailChangeRequest(
            (int) $user->getId(),
            $normalizedEmail,
            (new DateTimeImmutable())->add(new DateInterval('PT10M')),
            new DateTimeImmutable()
        );
        $this->entityManager->persist($request);
        $this->entityManager->flush();
        $requestId = (int) $request->getId();

        $code = (string) random_int(100000, 999999);
        $identity = $this->buildEmailChangeIdentity($requestId, $normalizedEmail);
        $this->totpChallengeService->createLoginChallenge($identity, $code);
        $this->totpEmailNotificationService->sendTotpCode($normalizedEmail, $code);

        return null;
    }

    /**
     * @brief Confirm pending email change with TOTP code.
     * @param User $user Current authenticated user.
     * @param string $totpCode Provided TOTP code.
     * @return string|null Translation key on error or null.
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function confirmEmailChange(User $user, string $totpCode): ?string
    {
        $request = $this->emailChangeRequestRepository->findLatestActiveByUserId((int) $user->getId(), new DateTimeImmutable());
        if (!$request instanceof ProfileEmailChangeRequest) {
            return 'profile.error.email_change_not_found';
        }

        $identity = $this->buildEmailChangeIdentity((int) $request->getId(), $request->getNewEmail());
        if (!$this->totpChallengeService->validateLoginChallenge($identity, trim($totpCode))) {
            return 'profile.error.totp_invalid';
        }

        $existingUser = $this->userRepository->findOneBy(['email' => $request->getNewEmail()]);
        if ($existingUser instanceof User) {
            return 'profile.error.email_already_used';
        }

        $user->setEmail($request->getNewEmail());
        $request->consume();
        $this->entityManager->flush();

        return null;
    }

    /**
     * @brief Send password change TOTP to current email.
     * @param User $user Current authenticated user.
     * @return void
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function requestPasswordChangeTotp(User $user): void
    {
        $code = (string) random_int(100000, 999999);
        $identity = $this->buildPasswordChangeIdentity((int) $user->getId(), $user->getSessionVersion());
        $this->totpChallengeService->createLoginChallenge($identity, $code);
        $this->totpEmailNotificationService->sendTotpCode($user->getEmail(), $code);
    }

    /**
     * @brief Change password with current password and TOTP validation.
     * @param User $user Current authenticated user.
     * @param string $currentPassword Current password.
     * @param string $newPassword New password.
     * @param string $totpCode TOTP code sent for password change.
     * @return string|null Translation key on error or null.
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function confirmPasswordChange(User $user, string $currentPassword, string $newPassword, string $totpCode): ?string
    {
        $normalizedNewPassword = trim($newPassword);
        if ($normalizedNewPassword === '') {
            return 'profile.error.password_required';
        }

        if (!$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
            return 'profile.error.current_password_invalid';
        }

        $identity = $this->buildPasswordChangeIdentity((int) $user->getId(), $user->getSessionVersion());
        if (!$this->totpChallengeService->validateLoginChallenge($identity, trim($totpCode))) {
            return 'profile.error.totp_invalid';
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $normalizedNewPassword));
        $user->bumpSessionVersion();
        $this->trustedDeviceAdminService->revokeAll((int) $user->getId());
        $this->entityManager->flush();

        return null;
    }

    /**
     * @brief Build identity key for email change challenges.
     * @param int $requestId Email change request identifier.
     * @param string $newEmail Requested email.
     * @return string
     * @date 2026-04-23
     * @author Stephane H.
     */
    private function buildEmailChangeIdentity(int $requestId, string $newEmail): string
    {
        return sprintf('profile-email-change:%d:%s', $requestId, strtolower(trim($newEmail)));
    }

    /**
     * @brief Build identity key for password change challenges.
     * @param int $userId User identifier.
     * @param int $sessionVersion Session version value.
     * @return string
     * @date 2026-04-23
     * @author Stephane H.
     */
    private function buildPasswordChangeIdentity(int $userId, int $sessionVersion): string
    {
        return sprintf('profile-password-change:%d:%d', $userId, $sessionVersion);
    }
}
