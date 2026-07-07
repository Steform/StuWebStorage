<?php

namespace App\Tests\Functional\Share;

use App\Controller\PublicDownloadController;
use App\Entity\PublicDownloadChallenge;
use App\Entity\SharedFile;
use App\Repository\SharedFileRepository;
use App\Service\Audit\DownloadAuditService;
use App\Service\Audit\DownloadDiagnosticLogger;
use App\Service\File\DownloadDeliveryService;
use App\Service\File\DownloadPrepareService;
use App\Service\File\FileEncryptionService;
use App\Service\Share\FolderTreeService;
use App\Service\Share\FolderZipService;
use App\Service\Share\PublicDownloadTotpService;
use App\Service\Share\PublicFolderZipService;
use App\Service\Share\PublicLandingAccessService;
use App\Service\Share\PublicSharePasswordCredentialService;
use App\Service\Share\PublicSharePasswordRateLimiterService;
use App\Service\Share\PublicSharePreAuthTokenService;
use DateInterval;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Exercises PublicDownloadController with mocks (no HTTP kernel). Kept under Functional for historical layout; see tests/Unit for comparable isolation style.
 */
class PublicDownloadTotpTest extends TestCase
{
    /**
     * @brief Real PublicFolderZipService with mocked collaborators (class is final; cannot be doubled).
     * @return PublicFolderZipService
     * @date 2026-05-02
     * @author Stephane H.
     */
    private function makePublicFolderZipService(): PublicFolderZipService
    {
        return new PublicFolderZipService(
            $this->createMock(FolderTreeService::class),
            $this->createMock(SharedFileRepository::class),
            $this->createMock(FolderZipService::class),
            104857600,
            500,
            120
        );
    }

    /**
     * @brief Build controller with in-memory cache for one-time download tickets.
     * @return PublicDownloadController
     * @date 2026-04-28
     * @author Stephane H.
     */
    private function makeController(
        PublicDownloadTotpService $totp,
        DownloadAuditService $audit,
        SharedFileRepository $repo,
        FileEncryptionService $files,
    ): PublicDownloadController {
        return new PublicDownloadController(
            $totp,
            $audit,
            $repo,
            $this->createMock(\App\Repository\FolderRepository::class),
            $files,
            $this->makePublicFolderZipService(),
            new PublicLandingAccessService(),
            new PublicSharePreAuthTokenService('phpunit-preauth-secret', 900),
            new PublicSharePasswordCredentialService($this->createMock(UserPasswordHasherInterface::class)),
            new PublicSharePasswordRateLimiterService(new ArrayAdapter()),
            new ArrayAdapter(),
            $this->createMock(TranslatorInterface::class),
            $this->createMock(DownloadDiagnosticLogger::class),
            new DownloadPrepareService($files, $this->createMock(DownloadDiagnosticLogger::class), sys_get_temp_dir(), 134217728, 86400, 3, 209715200),
            new DownloadDeliveryService(false),
            3145728,
            900,
            209715200,
        );
    }

    /**
     * @brief Ensure public download is blocked without valid TOTP.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-22
     * @author Stephane H.
     */
    public function testDownloadDeniedWithoutValidTotp(): void
    {
        $totpService = $this->createMock(PublicDownloadTotpService::class);
        $challenge = new PublicDownloadChallenge(
            'token-1',
            'friend@example.com',
            '123456',
            (new DateTimeImmutable())->add(new DateInterval('PT10M'))
        );
        $totpService->method('createChallenge')->willReturn($challenge);
        $totpService->method('verifyChallenge')->willReturn(false);
        $totpService->method('findChallengeById')->willReturn($challenge);
        $sharedFileRepository = $this->createMock(SharedFileRepository::class);
        $sharedFileRepository->method('findOneByPublicToken')->willReturn(new SharedFile(1, __FILE__, 'public', 'token-1'));
        $downloadAuditService = $this->createMock(DownloadAuditService::class);
        $downloadAuditService->expects(self::once())->method('createDenied');

        $controller = $this->makeController(
            $totpService,
            $downloadAuditService,
            $sharedFileRepository,
            $this->createMock(FileEncryptionService::class)
        );

        $challengeResponse = $controller->createChallenge(new Request(content: json_encode([
            'publicToken' => 'token-1',
            'email' => 'friend@example.com',
        ], JSON_THROW_ON_ERROR)));
        self::assertSame(201, $challengeResponse->getStatusCode());

        $request = new Request(content: json_encode([
            'challengeId' => 1,
            'inputCode' => '000000',
        ], JSON_THROW_ON_ERROR));

        $response = $controller->verifyChallenge($request);

        self::assertSame(401, $response->getStatusCode());
    }

    /**
     * @brief Ensure payload validation returns bad request.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-24
     * @author Stephane H.
     */
    public function testDownloadRejectsInvalidPayload(): void
    {
        $controller = $this->makeController(
            $this->createMock(PublicDownloadTotpService::class),
            $this->createMock(DownloadAuditService::class),
            $this->createMock(SharedFileRepository::class),
            $this->createMock(FileEncryptionService::class)
        );

        $response = $controller->createChallenge(new Request(content: '{}'));
        self::assertSame(400, $response->getStatusCode());
    }

    /**
     * @brief Ensure challenge creation is throttled on cooldown.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-26
     * @author Stephane H.
     */
    public function testChallengeCooldownReturnsTooManyRequests(): void
    {
        $totpService = $this->createMock(PublicDownloadTotpService::class);
        $totpService->method('createChallenge')->willThrowException(new \RuntimeException('download.challenge.cooldown'));
        $sharedFileRepository = $this->createMock(SharedFileRepository::class);
        $sharedFileRepository->method('findOneByPublicToken')->willReturn(new SharedFile(1, __FILE__, 'public', 'token-1'));

        $controller = $this->makeController(
            $totpService,
            $this->createMock(DownloadAuditService::class),
            $sharedFileRepository,
            $this->createMock(FileEncryptionService::class)
        );

        $response = $controller->createChallenge(new Request(content: json_encode([
            'publicToken' => 'token-1',
            'email' => 'friend@example.com',
        ], JSON_THROW_ON_ERROR)));

        self::assertSame(429, $response->getStatusCode());
    }

    /**
     * @brief Ensure valid challenge flow authorizes, returns download key and payload.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function testValidChallengeAuthorizesDownload(): void
    {
        $totpService = $this->createMock(PublicDownloadTotpService::class);
        $challenge = new PublicDownloadChallenge(
            'token-1',
            'friend@example.com',
            '123456',
            (new DateTimeImmutable())->add(new DateInterval('PT10M'))
        );
        $idProp = new ReflectionProperty($challenge, 'id');
        $idProp->setAccessible(true);
        $idProp->setValue($challenge, 1);
        $totpService->method('findChallengeById')->willReturn($challenge);
        $totpService->method('verifyChallenge')->willReturn(true);
        $sharedFileRepository = $this->createMock(SharedFileRepository::class);
        $sharedFileRepository->method('findOneByPublicToken')->willReturn(new SharedFile(1, __FILE__, 'public', 'token-1'));
        $fileEncryptionService = $this->createMock(FileEncryptionService::class);
        $fileEncryptionService->method('decryptFromStorage')->willReturn('decrypted-content');
        $downloadAuditService = $this->createMock(DownloadAuditService::class);
        $downloadAuditService->expects(self::once())->method('create');

        $controller = $this->makeController(
            $totpService,
            $downloadAuditService,
            $sharedFileRepository,
            $fileEncryptionService
        );

        $response = $controller->verifyChallenge(new Request(content: json_encode([
            'challengeId' => 1,
            'inputCode' => '123456',
        ], JSON_THROW_ON_ERROR)));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('downloadKey', $data);
        self::assertArrayHasKey('fileName', $data);
        self::assertArrayHasKey('contentBase64', $data);
    }
}
