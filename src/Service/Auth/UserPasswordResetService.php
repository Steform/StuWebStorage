<?php

namespace App\Service\Auth;

use App\Entity\PasswordResetRequest;
use App\Entity\User;
use App\Repository\PasswordResetRequestRepository;
use App\Service\Admin\TrustedDeviceAdminService;
use App\Service\Notification\PasswordResetEmailNotificationService;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Service UserPasswordResetService.
 */
class UserPasswordResetService
{
    /**
     * @brief Build user password reset service.
     * @param EntityManagerInterface $entityManager Doctrine entity manager.
     * @param PasswordResetRequestRepository $passwordResetRequestRepository Password reset repository.
     * @param UserPasswordHasherInterface $passwordHasher User password hasher.
     * @param UrlGeneratorInterface $urlGenerator Router URL generator.
     * @param PasswordResetEmailNotificationService $passwordResetEmailNotificationService Reset email sender.
     * @param TrustedDeviceAdminService $trustedDeviceAdminService Trusted device admin service.
     * @param int $tokenTtlSeconds Reset token lifetime.
     * @param array<int, string> $supportedLocales Supported locale list.
     * @return void
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PasswordResetRequestRepository $passwordResetRequestRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly PasswordResetEmailNotificationService $passwordResetEmailNotificationService,
        private readonly TrustedDeviceAdminService $trustedDeviceAdminService,
        private readonly int $tokenTtlSeconds = 3600,
        private readonly array $supportedLocales = ['fr', 'en', 'de', 'lt', 'no'],
    ) {
    }

    /**
     * @brief Request password reset email for one account.
     * @param string $email Account email.
     * @param string|null $requestedLocale Preferred email locale.
     * @return bool True when an email was sent.
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function requestPasswordReset(string $email, ?string $requestedLocale = null): bool
    {
        $normalizedEmail = strtolower(trim($email));
        if ($normalizedEmail === '') {
            return false;
        }

        /** @var User|null $user */
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $normalizedEmail]);
        if (!$user instanceof User || !$user->isActive() || $user->getId() === null) {
            return false;
        }

        $resolvedLocale = $this->resolveLocale($requestedLocale);
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $now = new DateTimeImmutable();
        $expiresAt = $now->add(new DateInterval(sprintf('PT%dS', max(1, $this->tokenTtlSeconds))));

        $this->revokePendingRequestsForUser((int) $user->getId());

        $resetRequest = new PasswordResetRequest(
            (int) $user->getId(),
            $tokenHash,
            $expiresAt,
            $resolvedLocale
        );
        $this->entityManager->persist($resetRequest);
        $this->entityManager->flush();

        $resetUrl = $this->buildResetUrl($token, $resolvedLocale);
        try {
            $this->passwordResetEmailNotificationService->sendPasswordReset($normalizedEmail, $resetUrl, $resolvedLocale);
        } catch (\Throwable) {
            throw new \RuntimeException('password_reset.email_send_failed');
        }

        return true;
    }

    /**
     * @brief Resolve reset page locale from token and optional request hint.
     * @param string $plainToken Plain reset token.
     * @param string|null $requestedLocale Requested locale candidate.
     * @return string
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function resolveResetLocaleForToken(string $plainToken, ?string $requestedLocale = null): string
    {
        $normalizedToken = trim($plainToken);
        if ($normalizedToken !== '') {
            $resetRequest = $this->passwordResetRequestRepository->findByTokenHash(hash('sha256', $normalizedToken));
            if ($resetRequest instanceof PasswordResetRequest && !$resetRequest->isConsumed()) {
                return $this->resolveLocale($resetRequest->getLocale());
            }
        }

        return $this->resolveLocale($requestedLocale);
    }

    /**
     * @brief Complete password reset from plain token.
     * @param string $token Plain reset token.
     * @param string $newPassword New password.
     * @return string|null Reset locale on success, null on failure.
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function completePasswordReset(string $token, string $newPassword): ?string
    {
        $normalizedToken = trim($token);
        $password = trim($newPassword);
        if ($normalizedToken === '' || $password === '') {
            return null;
        }

        $resetRequest = $this->passwordResetRequestRepository->findActiveByTokenHash(
            hash('sha256', $normalizedToken),
            new DateTimeImmutable()
        );
        if (!$resetRequest instanceof PasswordResetRequest) {
            return null;
        }

        /** @var User|null $user */
        $user = $this->entityManager->getRepository(User::class)->find($resetRequest->getUserId());
        if (!$user instanceof User || !$user->isActive()) {
            return null;
        }

        $resolvedLocale = $this->resolveLocale($resetRequest->getLocale());
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $user->setPasswordResetRequired(false);
        $user->bumpSessionVersion();
        $resetRequest->consume();
        $this->trustedDeviceAdminService->revokeAll((int) $user->getId());
        $this->entityManager->flush();

        return $resolvedLocale;
    }

    /**
     * @brief Revoke unconsumed reset requests for one user.
     * @param int $userId User identifier.
     * @return void
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function revokePendingRequestsForUser(int $userId): void
    {
        $this->entityManager->createQueryBuilder()
            ->update(PasswordResetRequest::class, 'resetRequest')
            ->set('resetRequest.consumed', ':consumed')
            ->where('resetRequest.userId = :userId')
            ->andWhere('resetRequest.consumed = false')
            ->setParameter('consumed', true)
            ->setParameter('userId', $userId)
            ->getQuery()
            ->execute();
    }

    /**
     * @brief Build absolute reset URL with locale query parameter.
     * @param string $token Plain reset token.
     * @param string $locale Reset locale code.
     * @return string
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function buildResetUrl(string $token, string $locale): string
    {
        $resolvedLocale = $this->resolveLocale($locale);
        $baseUrl = $this->urlGenerator->generate('app_password_reset', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);

        return $baseUrl.(str_contains($baseUrl, '?') ? '&' : '?').'lang='.rawurlencode($resolvedLocale);
    }

    /**
     * @brief Resolve supported locale with English fallback.
     * @param string|null $requestedLocale Requested locale candidate.
     * @return string
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function resolveLocale(?string $requestedLocale): string
    {
        $normalizedLocale = strtolower(trim((string) $requestedLocale));
        if (in_array($normalizedLocale, $this->supportedLocales, true)) {
            return $normalizedLocale;
        }

        return 'en';
    }
}
