<?php

namespace App\Service\Auth;

use App\Entity\LoginTotpChallenge;
use App\Entity\User;
use App\Repository\LoginTotpChallengeRepository;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Service TotpChallengeService.
 */
class TotpChallengeService
{
    /**
     * @brief Build TOTP challenge service.
     * @param LoginTotpChallengeRepository $challengeRepository Challenge repository.
     * @param EntityManagerInterface $entityManager Doctrine entity manager.
     * @param int $defaultTtlSeconds Default challenge lifetime in seconds.
     * @param int $resendCooldownSeconds Minimum delay between resend attempts in seconds.
     * @param int $maxResendCount Maximum number of resend attempts per challenge.
     * @return void
     * @date 2026-06-15
     * @author Stephane H.
     */
    public function __construct(
        private readonly LoginTotpChallengeRepository $challengeRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly int $defaultTtlSeconds = 300,
        private readonly int $resendCooldownSeconds = 60,
        private readonly int $maxResendCount = 3
    ) {
    }

    /**
     * @brief Create a persistent login challenge.
     * @param string $identity Login identity.
     * @param string $plainCode Plain challenge code.
     * @param int|null $ttlSeconds Optional challenge lifetime in seconds.
     * @return LoginTotpChallenge
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function createLoginChallenge(string $identity, string $plainCode, ?int $ttlSeconds = null): LoginTotpChallenge
    {
        $normalizedIdentity = strtolower(trim($identity));
        $code = trim($plainCode);
        if ($normalizedIdentity === '' || !$this->isNumericTotpCode($code)) {
            throw new InvalidArgumentException('Invalid TOTP challenge payload.');
        }
        $ttl = max(1, $ttlSeconds ?? $this->defaultTtlSeconds);
        $now = new DateTimeImmutable();
        $expiresAt = $now->add(new DateInterval(sprintf('PT%dS', $ttl)));
        $challenge = new LoginTotpChallenge(
            $normalizedIdentity,
            password_hash($code, PASSWORD_DEFAULT),
            $expiresAt,
            $now
        );

        $this->entityManager->persist($challenge);
        $this->entityManager->flush();

        return $challenge;
    }

    /**
     * @brief Validate persistent login challenge for identity.
     * @param string $identity Login identity.
     * @param string $inputCode User provided code.
     * @return bool
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function validateLoginChallenge(string $identity, string $inputCode): bool
    {
        $normalizedIdentity = strtolower(trim($identity));
        $code = trim($inputCode);
        if ($normalizedIdentity === '' || !$this->isNumericTotpCode($code)) {
            return false;
        }

        $now = new DateTimeImmutable();
        $challenge = $this->challengeRepository->findLatestActiveByIdentity($normalizedIdentity, $now);
        if (!$challenge instanceof LoginTotpChallenge) {
            return false;
        }

        $isCodeValid = password_verify($code, $challenge->getCodeHash());
        if (!$isCodeValid) {
            return false;
        }

        $challenge->consume($now);
        /** @var User|null $user */
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $normalizedIdentity]);
        if ($user instanceof User && in_array('ROLE_ADMIN', $user->getRoles(), true) && !$user->isSetupConfirmed()) {
            $user->setSetupConfirmed(true);
        }
        $this->entityManager->flush();

        return true;
    }

    /**
     * @brief Resolve resend availability for the current pending login challenge.
     * @param string $identity Login identity.
     * @return array{canResend: bool, retryAfterSeconds: int, rateLimited: bool}
     * @date 2026-06-15
     * @author Stephane H.
     */
    public function getResendState(string $identity): array
    {
        $normalizedIdentity = strtolower(trim($identity));
        if ($normalizedIdentity === '') {
            return [
                'canResend' => false,
                'retryAfterSeconds' => 0,
                'rateLimited' => false,
            ];
        }

        $now = new DateTimeImmutable();
        $challenge = $this->challengeRepository->findLatestPendingByIdentity($normalizedIdentity);
        if (!$challenge instanceof LoginTotpChallenge || $challenge->isExpired($now)) {
            return [
                'canResend' => true,
                'retryAfterSeconds' => 0,
                'rateLimited' => false,
            ];
        }

        if ($challenge->getResendCount() >= $this->maxResendCount) {
            return [
                'canResend' => false,
                'retryAfterSeconds' => 0,
                'rateLimited' => true,
            ];
        }

        $retryAfterSeconds = $challenge->getRetryAfterSeconds($now, $this->resendCooldownSeconds);

        return [
            'canResend' => $retryAfterSeconds === 0,
            'retryAfterSeconds' => $retryAfterSeconds,
            'rateLimited' => false,
        ];
    }

    /**
     * @brief Resend login TOTP challenge by email.
     * @param string $identity Login identity.
     * @return string Plain challenge code to deliver by email.
     * @date 2026-06-15
     * @author Stephane H.
     */
    public function resendLoginChallenge(string $identity): string
    {
        $normalizedIdentity = strtolower(trim($identity));
        if ($normalizedIdentity === '') {
            throw new InvalidArgumentException('Invalid TOTP challenge payload.');
        }

        $now = new DateTimeImmutable();
        $challenge = $this->challengeRepository->findLatestPendingByIdentity($normalizedIdentity);
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = $now->add(new DateInterval(sprintf('PT%dS', max(1, $this->defaultTtlSeconds))));

        if (!$challenge instanceof LoginTotpChallenge || $challenge->isExpired($now)) {
            $this->createLoginChallenge($normalizedIdentity, $code);

            return $code;
        }

        if ($challenge->getResendCount() >= $this->maxResendCount) {
            throw new RuntimeException('auth.totp.challenge.rate_limited');
        }

        if (!$challenge->canResend($now, $this->resendCooldownSeconds, $this->maxResendCount)) {
            throw new RuntimeException('auth.totp.challenge.cooldown');
        }

        $challenge->resend(password_hash($code, PASSWORD_DEFAULT), $expiresAt, $now);
        $this->entityManager->flush();

        return $code;
    }

    /**
     * @brief Get remaining cooldown seconds for a pending challenge.
     * @param string $identity Login identity.
     * @return int
     * @date 2026-06-15
     * @author Stephane H.
     */
    public function getRetryAfterSeconds(string $identity): int
    {
        return $this->getResendState($identity)['retryAfterSeconds'];
    }

    /**
     * @brief Get configured resend cooldown in seconds.
     * @param void No input parameter.
     * @return int
     * @date 2026-06-15
     * @author Stephane H.
     */
    public function getResendCooldownSeconds(): int
    {
        return $this->resendCooldownSeconds;
    }

    /**
     * @brief Validate TOTP code with direct expected value.
     * @param string $inputCode User provided code.
     * @param string $expectedCode Expected server code.
     * @return bool
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function validate(string $inputCode, string $expectedCode): bool
    {
        return hash_equals($expectedCode, $inputCode);
    }

    /**
     * @brief Check if code matches expected numeric TOTP format.
     * @param string $code Raw code value.
     * @return bool
     * @date 2026-04-26
     * @author Stephane H.
     */
    private function isNumericTotpCode(string $code): bool
    {
        return preg_match('/^\d{6}$/', $code) === 1;
    }
}
