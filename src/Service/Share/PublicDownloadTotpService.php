<?php

namespace App\Service\Share;

use App\Entity\PublicDownloadChallenge;
use App\Repository\PublicDownloadChallengeRepository;
use App\Service\Notification\TotpEmailNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use DateInterval;
use DateTimeImmutable;
use RuntimeException;

/**
 * Service PublicDownloadTotpService.
 */
class PublicDownloadTotpService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PublicDownloadChallengeRepository $publicDownloadChallengeRepository,
        private readonly TotpEmailNotificationService $totpEmailNotificationService,
        private readonly int $challengeTtlSeconds = 600,
        private readonly int $resendCooldownSeconds = 60,
        private readonly int $maxResendCount = 3
    )
    {
    }

    /**
     * @brief Create a public download challenge.
     * @param string $publicToken Public file token.
     * @param string $email Target email.
     * @return PublicDownloadChallenge
     * @date 2026-04-22
     * @author Stephane H.
     */
    public function createChallenge(string $publicToken, string $email): PublicDownloadChallenge
    {
        $normalizedEmail = strtolower(trim($email));
        if ($normalizedEmail === '') {
            throw new RuntimeException('download.challenge.invalid_email');
        }

        $now = new DateTimeImmutable();
        $latestChallenge = $this->publicDownloadChallengeRepository->findLatestByTokenAndEmail($publicToken, $normalizedEmail);
        if ($latestChallenge instanceof PublicDownloadChallenge) {
            if (!$latestChallenge->canResend($now, $this->resendCooldownSeconds, $this->maxResendCount)) {
                throw new RuntimeException('download.challenge.cooldown');
            }
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = $now->add(new DateInterval('PT'.$this->challengeTtlSeconds.'S'));

        if ($latestChallenge instanceof PublicDownloadChallenge && !$latestChallenge->isVerified()) {
            $latestChallenge->resend($code, $expiresAt, $now);
            $this->totpEmailNotificationService->sendTotpCode($normalizedEmail, $code);
            $this->entityManager->flush();

            return $latestChallenge;
        }

        $challenge = new PublicDownloadChallenge($publicToken, $normalizedEmail, $code, $expiresAt);
        $this->entityManager->persist($challenge);
        $this->entityManager->flush();
        $this->totpEmailNotificationService->sendTotpCode($normalizedEmail, $code);

        return $challenge;
    }

    /**
     * @brief Validate challenge code.
     * @param PublicDownloadChallenge $challenge Challenge entity.
     * @param string $inputCode Input code.
     * @return bool
     * @date 2026-04-22
     * @author Stephane H.
     */
    public function verifyChallenge(PublicDownloadChallenge $challenge, string $inputCode): bool
    {
        $verified = $challenge->verify($inputCode, new DateTimeImmutable());
        $this->entityManager->flush();

        return $verified;
    }

    /**
     * @brief Validate TOTP without completing verification (share-password gate).
     * @param PublicDownloadChallenge $challenge Challenge entity.
     * @param string $inputCode User-entered code.
     * @return bool True when TOTP matches.
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function verifyTotpCodeOnly(PublicDownloadChallenge $challenge, string $inputCode): bool
    {
        $ok = $challenge->verifyTotpCodeOnly($inputCode, new DateTimeImmutable());
        $this->entityManager->flush();

        return $ok;
    }

    /**
     * @brief Finalize challenge after successful share-password step.
     * @param PublicDownloadChallenge $challenge Challenge entity.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function markChallengeVerified(PublicDownloadChallenge $challenge): void
    {
        $challenge->markEmailChallengeVerified();
        $this->entityManager->flush();
    }

    /**
     * @brief Find persisted challenge by identifier.
     * @param int $challengeId Challenge identifier.
     * @return PublicDownloadChallenge|null
     * @date 2026-04-26
     * @author Stephane H.
     */
    public function findChallengeById(int $challengeId): ?PublicDownloadChallenge
    {
        if ($challengeId <= 0) {
            return null;
        }

        return $this->publicDownloadChallengeRepository->findOneById($challengeId);
    }
}
