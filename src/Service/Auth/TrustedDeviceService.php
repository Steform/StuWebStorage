<?php

namespace App\Service\Auth;

use App\Entity\TrustedDevice;
use App\Repository\TrustedDeviceRepository;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Service TrustedDeviceService.
 */
class TrustedDeviceService
{
    /**
     * @brief Build trusted device service.
     * @param TrustedDeviceRepository $trustedDeviceRepository Trusted device repository.
     * @param EntityManagerInterface $entityManager Doctrine entity manager.
     * @return void
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function __construct(
        private readonly TrustedDeviceRepository $trustedDeviceRepository,
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    /**
     * @brief Compute trusted device expiration date.
     * @param DateTimeImmutable $fromDate Trust start date.
     * @return DateTimeImmutable
     * @date 2026-04-22
     * @author Stephane H.
     */
    public function computeExpiration(DateTimeImmutable $fromDate): DateTimeImmutable
    {
        return $fromDate->add(new DateInterval('P6M'));
    }

    /**
     * @brief Check if trust window is still valid.
     * @param DateTimeImmutable $trustedUntil Trust expiration.
     * @param DateTimeImmutable $now Current date.
     * @return bool
     * @date 2026-04-22
     * @author Stephane H.
     */
    public function isStillTrusted(DateTimeImmutable $trustedUntil, DateTimeImmutable $now): bool
    {
        return $trustedUntil >= $now;
    }

    /**
     * @brief Check if current request device is trusted for user.
     * @param int $userId User identifier.
     * @param Request $request Current HTTP request.
     * @return bool
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function isTrustedDevice(int $userId, Request $request): bool
    {
        $fingerprint = $this->computeFingerprint($request);
        $trustedDevice = $this->trustedDeviceRepository->findActiveByUserAndFingerprint($userId, $fingerprint, new DateTimeImmutable());

        return $trustedDevice instanceof TrustedDevice;
    }

    /**
     * @brief Mark current request device as trusted for user.
     * @param int $userId User identifier.
     * @param Request $request Current HTTP request.
     * @return void
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function trustDevice(int $userId, Request $request): void
    {
        $now = new DateTimeImmutable();
        $trustedUntil = $this->computeExpiration($now);
        $fingerprint = $this->computeFingerprint($request);
        $existing = $this->trustedDeviceRepository->findByUserAndFingerprint($userId, $fingerprint);

        if ($existing instanceof TrustedDevice) {
            $existing->renew($trustedUntil);
            $this->entityManager->flush();

            return;
        }

        $trustedDevice = new TrustedDevice($userId, $fingerprint, $trustedUntil);
        $this->entityManager->persist($trustedDevice);
        $this->entityManager->flush();
    }

    /**
     * @brief Compute stable request fingerprint for trust checks.
     * @param Request $request Current HTTP request.
     * @return string
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function computeFingerprint(Request $request): string
    {
        $userAgent = (string) $request->headers->get('User-Agent', '');
        $acceptLanguage = (string) $request->headers->get('Accept-Language', '');
        $clientIp = (string) $request->getClientIp();

        return hash('sha256', implode('|', [$userAgent, $acceptLanguage, $clientIp]));
    }
}
