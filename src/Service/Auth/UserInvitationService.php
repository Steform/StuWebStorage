<?php

namespace App\Service\Auth;

use App\Entity\User;
use App\Entity\UserInvitationToken;
use App\Repository\UserInvitationTokenRepository;
use App\Service\Notification\InvitationEmailNotificationService;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Service UserInvitationService.
 */
class UserInvitationService
{
    /**
     * @brief Build user invitation service.
     * @param EntityManagerInterface $entityManager Doctrine entity manager.
     * @param UserInvitationTokenRepository $invitationTokenRepository Invitation token repository.
     * @param UserPasswordHasherInterface $passwordHasher User password hasher.
     * @param UrlGeneratorInterface $urlGenerator Router URL generator.
     * @param InvitationEmailNotificationService $invitationEmailNotificationService Invitation email sender.
     * @param int $tokenTtlSeconds Invitation token lifetime.
     * @param array<int, string> $supportedLocales Supported locale list.
     * @return void
     * @date 2026-06-17
     * @author Stephane H.
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserInvitationTokenRepository $invitationTokenRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly InvitationEmailNotificationService $invitationEmailNotificationService,
        private readonly int $tokenTtlSeconds = 86400,
        private readonly array $supportedLocales = ['fr', 'en', 'de', 'lt', 'no'],
    ) {
    }

    /**
     * @brief Invite user and dispatch activation link.
     * @param string $email Invited user email.
     * @param string $pseudonym Invited pseudonym.
     * @param int $inviterUserId Inviter identifier.
     * @param string|null $requestedLocale Requested invitation locale.
     * @return array{userId: int, activationUrl: string}
     * @date 2026-06-17
     * @author Stephane H.
     */
    public function inviteUser(string $email, string $pseudonym, int $inviterUserId, ?string $requestedLocale = null): array
    {
        $normalizedEmail = strtolower(trim($email));
        $normalizedPseudonym = trim($pseudonym);

        /** @var User|null $existing */
        $existing = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $normalizedEmail]);
        if ($existing instanceof User) {
            throw new \RuntimeException('invitation.user_already_exists');
        }

        $user = new User();
        $user->setEmail($normalizedEmail);
        $user->setPseudonym($normalizedPseudonym !== '' ? $normalizedPseudonym : $normalizedEmail);
        $user->setRoles(['ROLE_USER']);
        $user->setTotpEnabled(true);
        $user->setPassword($this->passwordHasher->hashPassword($user, bin2hex(random_bytes(32))));
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        if ($user->getId() === null) {
            throw new \RuntimeException('invitation.user_persist_failed');
        }

        $resolvedLocale = $this->resolveInvitationLocale($requestedLocale);
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $now = new DateTimeImmutable();
        $expiresAt = $now->add(new DateInterval(sprintf('PT%dS', max(1, $this->tokenTtlSeconds))));

        $this->revokePendingInvitationsForUser((int) $user->getId(), $now);

        $invitation = new UserInvitationToken(
            $user->getId(),
            $normalizedEmail,
            $tokenHash,
            $inviterUserId,
            $now,
            $expiresAt,
            $resolvedLocale
        );
        $this->entityManager->persist($invitation);
        $this->entityManager->flush();

        $activationUrl = $this->buildActivationUrl($token, $resolvedLocale);
        try {
            $this->invitationEmailNotificationService->sendInvitation($normalizedEmail, $activationUrl, $resolvedLocale);
        } catch (\Throwable) {
            throw new \RuntimeException('invitation.email_send_failed');
        }

        return [
            'userId' => (int) $user->getId(),
            'activationUrl' => $activationUrl,
        ];
    }

    /**
     * @brief Check whether user still has a pending invitation.
     * @param int $userId Invited user identifier.
     * @return bool
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function hasPendingInvitation(int $userId): bool
    {
        return $this->invitationTokenRepository->hasPendingInvitationForUser($userId);
    }

    /**
     * @brief Return locale of latest pending invitation for one user.
     * @param int $userId Invited user identifier.
     * @return string|null
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function resolvePendingInvitationLocale(int $userId): ?string
    {
        $locale = $this->invitationTokenRepository->findLatestPendingLocaleForUser($userId);
        if ($locale === null) {
            return null;
        }

        return $this->resolveInvitationLocale($locale);
    }

    /**
     * @brief Return user identifiers with pending invitation among candidates.
     * @param list<int> $userIds Candidate user identifiers.
     * @return list<int>
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function findUserIdsWithPendingInvitation(array $userIds): array
    {
        return $this->invitationTokenRepository->findUserIdsWithPendingInvitation($userIds);
    }

    /**
     * @brief Resend invitation email for a user who has not activated yet.
     * @param int $userId Invited user identifier.
     * @param int $inviterUserId Inviter identifier.
     * @param string|null $requestedLocale Requested invitation locale.
     * @return string Activation URL.
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function resendInvitation(int $userId, int $inviterUserId, ?string $requestedLocale = null): string
    {
        /** @var User|null $user */
        $user = $this->entityManager->getRepository(User::class)->find($userId);
        if (!$user instanceof User) {
            throw new \RuntimeException('invitation.user_not_found');
        }

        if (!$this->invitationTokenRepository->hasPendingInvitationForUser($userId)) {
            if ($this->invitationTokenRepository->countByUserId($userId) > 0) {
                throw new \RuntimeException('invitation.already_activated');
            }

            throw new \RuntimeException('invitation.not_invited');
        }

        $resolvedLocale = $this->resolveInvitationLocale($requestedLocale);
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $now = new DateTimeImmutable();
        $expiresAt = $now->add(new DateInterval(sprintf('PT%dS', max(1, $this->tokenTtlSeconds))));

        $this->revokePendingInvitationsForUser($userId, $now);

        $invitation = new UserInvitationToken(
            $userId,
            strtolower(trim($user->getEmail())),
            $tokenHash,
            $inviterUserId,
            $now,
            $expiresAt,
            $resolvedLocale
        );
        $this->entityManager->persist($invitation);
        $this->entityManager->flush();

        $activationUrl = $this->buildActivationUrl($token, $resolvedLocale);
        try {
            $this->invitationEmailNotificationService->sendInvitation($user->getEmail(), $activationUrl, $resolvedLocale);
        } catch (\Throwable) {
            throw new \RuntimeException('invitation.email_send_failed');
        }

        return $activationUrl;
    }

    /**
     * @brief Resolve activation page locale from token and optional request hint.
     * @param string $plainToken Plain invitation token.
     * @param string|null $requestedLocale Requested locale candidate.
     * @return string
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function resolveActivationLocaleForToken(string $plainToken, ?string $requestedLocale = null): string
    {
        $normalizedToken = trim($plainToken);
        if ($normalizedToken !== '') {
            $invitation = $this->invitationTokenRepository->findByTokenHash(hash('sha256', $normalizedToken));
            if ($invitation instanceof UserInvitationToken && !$invitation->isConsumed()) {
                return $this->resolveInvitationLocale($invitation->getLocale());
            }
        }

        return $this->resolveInvitationLocale($requestedLocale);
    }

    /**
     * @brief Activate invited account from plain token.
     * @param string $token Plain invitation token.
     * @param string $newPassword New user password.
     * @return string|null Invitation locale on success, null on failure.
     * @date 2026-06-17
     * @author Stephane H.
     */
    public function activateInvitation(string $token, string $newPassword): ?string
    {
        $normalizedToken = trim($token);
        $password = trim($newPassword);
        if ($normalizedToken === '' || $password === '') {
            return null;
        }

        $tokenHash = hash('sha256', $normalizedToken);
        $invitation = $this->invitationTokenRepository->findActiveByTokenHash($tokenHash, new DateTimeImmutable());
        if (!$invitation instanceof UserInvitationToken) {
            return null;
        }

        /** @var User|null $user */
        $user = $this->entityManager->getRepository(User::class)->find($invitation->getUserId());
        if (!$user instanceof User) {
            return null;
        }

        $resolvedLocale = $this->resolveInvitationLocale($invitation->getLocale());
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $invitation->consume(new DateTimeImmutable());
        $this->entityManager->flush();

        return $resolvedLocale;
    }

    /**
     * @brief Consume all active invitations for one user.
     * @param int $userId Target user identifier.
     * @param DateTimeImmutable $consumedAt Consumption datetime.
     * @return void
     * @date 2026-04-28
     * @author Stephane H.
     */
    private function revokePendingInvitationsForUser(int $userId, DateTimeImmutable $consumedAt): void
    {
        $this->entityManager->createQueryBuilder()
            ->update(UserInvitationToken::class, 'invitation')
            ->set('invitation.consumedAt', ':consumedAt')
            ->where('invitation.userId = :userId')
            ->andWhere('invitation.consumedAt IS NULL')
            ->setParameter('consumedAt', $consumedAt)
            ->setParameter('userId', $userId)
            ->getQuery()
            ->execute();
    }

    /**
     * @brief Build absolute activation URL with invitation locale query parameter.
     * @param string $token Plain invitation token.
     * @param string $locale Invitation locale code.
     * @return string
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function buildActivationUrl(string $token, string $locale): string
    {
        $resolvedLocale = $this->resolveInvitationLocale($locale);
        $baseUrl = $this->urlGenerator->generate('invite_activate', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);

        return $baseUrl.(str_contains($baseUrl, '?') ? '&' : '?').'lang='.rawurlencode($resolvedLocale);
    }

    /**
     * @brief Resolve invitation locale with English fallback.
     * @param string|null $requestedLocale Requested locale candidate.
     * @return string
     * @date 2026-04-28
     * @author Stephane H.
     */
    private function resolveInvitationLocale(?string $requestedLocale): string
    {
        $normalizedLocale = strtolower(trim((string) $requestedLocale));
        if (in_array($normalizedLocale, $this->supportedLocales, true)) {
            return $normalizedLocale;
        }

        return 'en';
    }
}
