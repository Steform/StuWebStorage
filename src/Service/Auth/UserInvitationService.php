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
     * @param string $defaultLocale Default locale.
     * @param string $fallbackLocale Fallback locale.
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
        private readonly string $defaultLocale = 'en',
        private readonly string $fallbackLocale = 'fr'
    ) {
    }

    /**
     * @brief Invite user and dispatch activation link.
     * @param string $email Invited user email.
     * @param string $pseudonym Invited pseudonym.
     * @param int $inviterUserId Inviter identifier.
     * @param string|null $requestedLocale Requested invitation locale.
     * @return string Activation URL.
     * @date 2026-06-17
     * @author Stephane H.
     */
    public function inviteUser(string $email, string $pseudonym, int $inviterUserId, ?string $requestedLocale = null): string
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

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $now = new DateTimeImmutable();
        $expiresAt = $now->add(new DateInterval(sprintf('PT%dS', max(1, $this->tokenTtlSeconds))));

        // Any new invitation revokes previous active tokens for the same user.
        $this->revokePendingInvitationsForUser((int) $user->getId(), $now);

        $invitation = new UserInvitationToken(
            $user->getId(),
            $normalizedEmail,
            $tokenHash,
            $inviterUserId,
            $now,
            $expiresAt
        );
        $this->entityManager->persist($invitation);
        $this->entityManager->flush();

        $activationUrl = $this->urlGenerator->generate('invite_activate', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);
        $resolvedLocale = $this->resolveInvitationLocale($requestedLocale);
        try {
            $this->invitationEmailNotificationService->sendInvitation($normalizedEmail, $activationUrl, $resolvedLocale);
        } catch (\Throwable) {
            throw new \RuntimeException('invitation.email_send_failed');
        }

        return $activationUrl;
    }

    /**
     * @brief Activate invited account from plain token.
     * @param string $token Plain invitation token.
     * @param string $newPassword New user password.
     * @return bool
     * @date 2026-06-17
     * @author Stephane H.
     */
    public function activateInvitation(string $token, string $newPassword): bool
    {
        $normalizedToken = trim($token);
        $password = trim($newPassword);
        if ($normalizedToken === '' || $password === '') {
            return false;
        }

        $tokenHash = hash('sha256', $normalizedToken);
        $invitation = $this->invitationTokenRepository->findActiveByTokenHash($tokenHash, new DateTimeImmutable());
        if (!$invitation instanceof UserInvitationToken) {
            return false;
        }

        /** @var User|null $user */
        $user = $this->entityManager->getRepository(User::class)->find($invitation->getUserId());
        if (!$user instanceof User) {
            return false;
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $invitation->consume(new DateTimeImmutable());
        $this->entityManager->flush();

        return true;
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
     * @brief Resolve invitation locale with strict supported fallback.
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
        if (in_array($this->defaultLocale, $this->supportedLocales, true)) {
            return $this->defaultLocale;
        }
        if (in_array($this->fallbackLocale, $this->supportedLocales, true)) {
            return $this->fallbackLocale;
        }

        return $this->supportedLocales[0] ?? 'en';
    }
}
