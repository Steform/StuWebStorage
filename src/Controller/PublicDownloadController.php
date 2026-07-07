<?php

namespace App\Controller;

use App\Entity\Folder;
use App\Entity\SharedFile;
use App\Repository\FolderRepository;
use App\Repository\SharedFileRepository;
use App\Service\Audit\DownloadAuditService;
use App\Service\Audit\DownloadDiagnosticLogger;
use App\Service\File\EncryptedStreamDeliveryService;
use App\Service\File\FileEncryptionService;
use App\Service\Share\PublicDownloadTotpService;
use App\Service\Share\PublicFolderZipService;
use App\Service\Share\PublicLandingAccessService;
use App\Service\Share\PublicSharePasswordCredentialService;
use App\Service\Share\PublicSharePasswordRateLimiterService;
use App\Service\Share\PublicSharePreAuthTokenService;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Controller PublicDownloadController.
 */
class PublicDownloadController
{
    private const TICKET_CACHE_PREFIX = 'pub_dl_tkt.';

    private const RESOURCE_KIND_FILE = 'file';

    private const RESOURCE_KIND_FOLDER = 'folder';

    public function __construct(
        private readonly PublicDownloadTotpService $publicDownloadTotpService,
        private readonly DownloadAuditService $downloadAuditService,
        private readonly SharedFileRepository $sharedFileRepository,
        private readonly FolderRepository $folderRepository,
        private readonly FileEncryptionService $fileEncryptionService,
        private readonly PublicFolderZipService $publicFolderZipService,
        private readonly PublicLandingAccessService $publicLandingAccessService,
        private readonly PublicSharePreAuthTokenService $publicSharePreAuthTokenService,
        private readonly PublicSharePasswordCredentialService $publicSharePasswordCredentialService,
        private readonly PublicSharePasswordRateLimiterService $publicSharePasswordRateLimiterService,
        private readonly CacheItemPoolInterface $publicDownloadTicketCache,
        private readonly TranslatorInterface $translator,
        private readonly DownloadDiagnosticLogger $downloadDiagnosticLogger,
        private readonly EncryptedStreamDeliveryService $encryptedStreamDeliveryService,
        private readonly int $publicDownloadInlineMaxBytes = 3145728,
        private readonly int $publicDownloadTicketTtlSeconds = 7200,
        private readonly int $downloadDirectMaxBytes = 209715200,
        private readonly int $downloadManagerUiThresholdBytes = 209715200,
    ) {
    }

    /**
     * @brief Start public download email challenge for a shared file or public folder landing token (inactive channel → 404 JSON).
     * @param Request $request JSON request.
     * @return JsonResponse
     * @date 2026-05-02
     * @author Stephane H.
     */
    #[Route('/download/public/challenge', name: 'download_public_challenge', methods: ['POST'])]
    public function createChallenge(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            $payload = $request->request->all();
        }
        $publicToken = (string) ($payload['publicToken'] ?? '');
        $email = (string) ($payload['email'] ?? '');

        if ($publicToken === '' || $email === '') {
            return new JsonResponse(['status' => 'error', 'message' => 'download.challenge.invalid_payload'], 400);
        }

        $sharedFile = $this->sharedFileRepository->findOneByPublicToken($publicToken);
        if ($sharedFile instanceof SharedFile) {
            if (!$sharedFile->isPublicShareActive()) {
                return new JsonResponse(['status' => 'error', 'message' => 'download.challenge.resource_not_found'], 404);
            }
        } else {
            $folder = $this->folderRepository->findOneByPublicFolderToken($publicToken);
            if (!$folder instanceof Folder || !$folder->isPublicShareEffectivelyActive()) {
                return new JsonResponse(['status' => 'error', 'message' => 'download.challenge.resource_not_found'], 404);
            }
        }

        try {
            $challenge = $this->publicDownloadTotpService->createChallenge($publicToken, $email);
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() === 'download.challenge.cooldown') {
                return new JsonResponse(['status' => 'error', 'message' => 'download.challenge.cooldown'], 429);
            }

            return new JsonResponse(['status' => 'error', 'message' => 'download.challenge.invalid_payload'], 400);
        }

        return new JsonResponse([
            'status' => 'ok',
            'message' => 'download.challenge.sent',
            'challengeId' => $challenge->getId(),
            'expiresAt' => $challenge->getExpiresAt()->format(DATE_ATOM),
        ], 201);
    }

    /**
     * @brief Verify TOTP challenge, issue a one-time download key, and optionally inline small payloads for files (expired file channel → 404 JSON).
     * @param Request $request JSON request.
     * @return JsonResponse
     * @date 2026-05-02
     * @author Stephane H.
     */
    #[Route('/download/public/verify', name: 'download_public_verify', methods: ['POST'])]
    public function verifyChallenge(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            $payload = $request->request->all();
        }

        $challengeId = (int) ($payload['challengeId'] ?? 0);
        $inputCode = (string) ($payload['inputCode'] ?? '');
        $formatCode = (string) ($payload['formatCode'] ?? '');
        if ($challengeId <= 0 || $inputCode === '') {
            return new JsonResponse(['status' => 'error', 'message' => 'download.verify.invalid_payload'], 400);
        }

        $challenge = $this->publicDownloadTotpService->findChallengeById($challengeId);
        if ($challenge === null) {
            return new JsonResponse(['status' => 'error', 'message' => 'download.verify.challenge_not_found'], 404);
        }

        $token = $challenge->getPublicToken();
        $sharedFile = $this->sharedFileRepository->findOneByPublicToken($token);
        $folder = null;
        if ($sharedFile instanceof SharedFile) {
            if (!$sharedFile->isPublicShareActive()) {
                return new JsonResponse(['status' => 'error', 'message' => 'download.challenge.resource_not_found'], 404);
            }
        } else {
            $folder = $this->folderRepository->findOneByPublicFolderToken($token);
            if (!$folder instanceof Folder || !$folder->isPublicShareEffectivelyActive()) {
                return new JsonResponse(['status' => 'error', 'message' => 'download.challenge.resource_not_found'], 404);
            }
        }

        $ip = (string) ($request->getClientIp() ?? '0.0.0.0');
        $resourceToken = $formatCode !== '' ? $token.':'.$formatCode : $token;

        if ($this->isPasswordGateActive($sharedFile, $folder)) {
            $totpOk = $this->publicDownloadTotpService->verifyTotpCodeOnly($challenge, $inputCode);
            if (!$totpOk) {
                $this->downloadAuditService->createDenied($challenge->getEmail(), $ip, $resourceToken);

                return new JsonResponse(['status' => 'error', 'message' => 'download.challenge.totp_required'], 401);
            }

            $cid = (int) ($challenge->getId() ?? 0);
            $preAuth = $this->publicSharePreAuthTokenService->mint($cid, $token);

            return new JsonResponse([
                'status' => 'ok',
                'message' => 'download.totp_ok_password_required',
                'passwordRequired' => true,
                'preAuthToken' => $preAuth,
            ], 200);
        }

        $verified = $this->publicDownloadTotpService->verifyChallenge($challenge, $inputCode);
        if (!$verified) {
            $this->downloadAuditService->createDenied($challenge->getEmail(), $ip, $resourceToken);

            return new JsonResponse(['status' => 'error', 'message' => 'download.challenge.totp_required'], 401);
        }

        if ($sharedFile instanceof SharedFile) {
            return $this->issueAuthorizedFileDownloadJson($sharedFile, $challenge, $challengeId, $token, $resourceToken, $ip);
        }

        if (!$folder instanceof Folder) {
            $this->downloadAuditService->createDenied($challenge->getEmail(), $ip, $resourceToken);

            return new JsonResponse(['status' => 'error', 'message' => 'download.challenge.resource_not_found'], 404);
        }

        return $this->issueAuthorizedFolderDownloadJson($folder, $challenge, $challengeId, $token, $ip);
    }

    /**
     * @brief Complete download authorization after share-password check (pre-auth from verify step).
     * @param Request $request JSON body with preAuthToken and sharePassword.
     * @return JsonResponse
     * @date 2026-05-04
     * @author Stephane H.
     */
    #[Route('/download/public/verify-share-password', name: 'download_public_verify_share_password', methods: ['POST'])]
    public function verifySharePassword(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            $payload = $request->request->all();
        }

        $preAuthToken = (string) ($payload['preAuthToken'] ?? '');
        $sharePassword = (string) ($payload['sharePassword'] ?? '');
        $formatCode = (string) ($payload['formatCode'] ?? '');

        if ($preAuthToken === '' || $sharePassword === '') {
            return new JsonResponse(['status' => 'error', 'message' => 'download.verify.invalid_payload'], 400);
        }

        $parsed = $this->publicSharePreAuthTokenService->verify($preAuthToken);
        if ($parsed === null) {
            return new JsonResponse(['status' => 'error', 'message' => 'download.share_password.preauth_invalid'], 400);
        }

        $challenge = $this->publicDownloadTotpService->findChallengeById($parsed['challengeId']);
        if ($challenge === null || $challenge->getPublicToken() !== $parsed['publicToken']) {
            return new JsonResponse(['status' => 'error', 'message' => 'download.verify.challenge_not_found'], 404);
        }

        if ($challenge->isVerified()) {
            return new JsonResponse(['status' => 'error', 'message' => 'download.share_password.preauth_invalid'], 400);
        }

        $token = $challenge->getPublicToken();
        $sharedFile = $this->sharedFileRepository->findOneByPublicToken($token);
        $folder = null;
        if ($sharedFile instanceof SharedFile) {
            if (!$sharedFile->isPublicShareActive() || !$sharedFile->isPublicPasswordGateActive()) {
                return new JsonResponse(['status' => 'error', 'message' => 'download.challenge.resource_not_found'], 404);
            }
        } else {
            $folder = $this->folderRepository->findOneByPublicFolderToken($token);
            if (!$folder instanceof Folder || !$folder->isPublicPasswordGateActive()) {
                return new JsonResponse(['status' => 'error', 'message' => 'download.challenge.resource_not_found'], 404);
            }
        }

        $ip = (string) ($request->getClientIp() ?? '0.0.0.0');
        if ($this->publicSharePasswordRateLimiterService->isLocked($token, $ip)) {
            return new JsonResponse(['status' => 'error', 'message' => 'download.share_password.rate_limited'], 429);
        }

        $hash = $this->resolvePasswordHash($sharedFile, $folder);
        if ($hash === null || $hash === '' || !$this->publicSharePasswordCredentialService->verify($sharePassword, $hash)) {
            $this->publicSharePasswordRateLimiterService->recordFailure($token, $ip);

            return new JsonResponse(['status' => 'error', 'message' => 'download.share_password.invalid'], 401);
        }

        $this->publicSharePasswordRateLimiterService->clearFailures($token, $ip);
        $this->publicDownloadTotpService->markChallengeVerified($challenge);

        $challengeId = (int) ($parsed['challengeId'] ?? 0);
        $resourceToken = $formatCode !== '' ? $token.':'.$formatCode : $token;

        if ($sharedFile instanceof SharedFile) {
            return $this->issueAuthorizedFileDownloadJson($sharedFile, $challenge, $challengeId, $token, $resourceToken, $ip);
        }

        if (!$folder instanceof Folder) {
            return new JsonResponse(['status' => 'error', 'message' => 'download.challenge.resource_not_found'], 404);
        }

        return $this->issueAuthorizedFolderDownloadJson($folder, $challenge, $challengeId, $token, $ip);
    }

    /**
     * @brief Whether anonymous download requires share-password after email TOTP.
     * @param SharedFile|null $sharedFile Resolved file or null.
     * @param Folder|null $folder Resolved folder or null.
     * @return bool
     * @date 2026-05-04
     * @author Stephane H.
     */
    private function isPasswordGateActive(?SharedFile $sharedFile, ?Folder $folder): bool
    {
        if ($sharedFile instanceof SharedFile) {
            return $sharedFile->isPublicPasswordGateActive();
        }
        if ($folder instanceof Folder) {
            return $folder->isPublicPasswordGateActive();
        }

        return false;
    }

    /**
     * @brief Read stored password hash for rate-limited verification.
     * @param SharedFile|null $sharedFile File aggregate.
     * @param Folder|null $folder Folder aggregate.
     * @return string|null
     * @date 2026-05-04
     * @author Stephane H.
     */
    private function resolvePasswordHash(?SharedFile $sharedFile, ?Folder $folder): ?string
    {
        if ($sharedFile instanceof SharedFile) {
            return $sharedFile->getPublicPasswordHash();
        }
        if ($folder instanceof Folder) {
            return $folder->getPublicPasswordHash();
        }

        return null;
    }

    /**
     * @brief Issue JSON payload and ticket after full authorization for a file.
     * @param SharedFile $sharedFile Shared file.
     * @param \App\Entity\PublicDownloadChallenge $challenge Verified challenge.
     * @param int $challengeId Fallback challenge id from request.
     * @param string $token Public token.
     * @param string $resourceToken Audit token (may include format suffix).
     * @param string $ip Client IP.
     * @return JsonResponse
     * @date 2026-05-04
     * @author Stephane H.
     */
    private function issueAuthorizedFileDownloadJson(
        SharedFile $sharedFile,
        \App\Entity\PublicDownloadChallenge $challenge,
        int $challengeId,
        string $token,
        string $resourceToken,
        string $ip,
    ): JsonResponse {
        if (!$sharedFile->isPublicShareActive()) {
            $this->downloadAuditService->createDenied($challenge->getEmail(), $ip, $resourceToken);

            return new JsonResponse(['status' => 'error', 'message' => 'download.challenge.resource_not_found'], 404);
        }
        $storagePath = $sharedFile->getStoragePath();
        if ($storagePath === '' || !is_readable($storagePath)) {
            $this->downloadAuditService->createDenied($challenge->getEmail(), $ip, $resourceToken);

            return new JsonResponse(['status' => 'error', 'message' => 'download.resource.unreadable'], 500);
        }

        $this->downloadAuditService->create($challenge->getEmail(), $ip, $resourceToken);

        $downloadKey = bin2hex(random_bytes(32));
        $cacheKey = $this->ticketCacheKey($downloadKey);
        $item = $this->publicDownloadTicketCache->getItem($cacheKey);
        $storageChallengeId = $challenge->getId() !== null && $challenge->getId() > 0
            ? (int) $challenge->getId() : $challengeId;
        $item->set([
            'challengeId' => $storageChallengeId,
            'publicToken' => $token,
            'resourceKind' => self::RESOURCE_KIND_FILE,
        ]);
        $item->expiresAfter($this->publicDownloadTicketTtlSeconds);
        $this->publicDownloadTicketCache->save($item);

        $byteLen = (int) $sharedFile->getByteSize();
        $useManager = $byteLen > $this->downloadManagerUiThresholdBytes;

        $body = [
            'status' => 'ok',
            'message' => 'download.authorized',
            'resourceKind' => self::RESOURCE_KIND_FILE,
            'resourceToken' => $resourceToken,
            'bytes' => $byteLen,
            'fileName' => $sharedFile->getOriginalFileName(),
            'downloadKey' => $downloadKey,
            'prepareRequired' => false,
            'useManager' => $useManager,
            'downloadUrl' => '/download/public/file?key='.rawurlencode($downloadKey),
        ];

        if ($byteLen <= $this->publicDownloadInlineMaxBytes) {
            try {
                $decryptedContent = $this->fileEncryptionService->decryptFromStorage($storagePath);
            } catch (\RuntimeException) {
                $this->downloadAuditService->createDenied($challenge->getEmail(), $ip, $resourceToken);

                return new JsonResponse(['status' => 'error', 'message' => 'download.resource.unreadable'], 500);
            }
            $body['mimeType'] = $this->detectMimeType($decryptedContent, $sharedFile);
            $body['contentBase64'] = base64_encode($decryptedContent);
        } else {
            $body['inlinePayloadOmitted'] = true;
            $body['mimeType'] = $this->detectMimeTypeFromExtension($sharedFile);
        }

        return new JsonResponse($body, 200);
    }

    /**
     * @brief Issue JSON payload and ticket after full authorization for a folder ZIP.
     * @param Folder $folder Public folder.
     * @param \App\Entity\PublicDownloadChallenge $challenge Verified challenge.
     * @param int $challengeId Fallback challenge id.
     * @param string $token Public token.
     * @param string $ip Client IP.
     * @return JsonResponse
     * @date 2026-05-04
     * @author Stephane H.
     */
    private function issueAuthorizedFolderDownloadJson(
        Folder $folder,
        \App\Entity\PublicDownloadChallenge $challenge,
        int $challengeId,
        string $token,
        string $ip,
    ): JsonResponse {
        $folderAuditToken = 'folder_zip:'.$token;
        $this->downloadAuditService->create($challenge->getEmail(), $ip, $folderAuditToken);

        $downloadKey = bin2hex(random_bytes(32));
        $cacheKey = $this->ticketCacheKey($downloadKey);
        $item = $this->publicDownloadTicketCache->getItem($cacheKey);
        $storageChallengeId = $challenge->getId() !== null && $challenge->getId() > 0
            ? (int) $challenge->getId() : $challengeId;
        $item->set([
            'challengeId' => $storageChallengeId,
            'publicToken' => $token,
            'resourceKind' => self::RESOURCE_KIND_FOLDER,
        ]);
        $item->expiresAfter($this->publicDownloadTicketTtlSeconds);
        $this->publicDownloadTicketCache->save($item);

        $zipLabel = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $folder->getName()) ?: 'folder';
        $zipLabel .= '.zip';

        return new JsonResponse([
            'status' => 'ok',
            'message' => 'download.authorized',
            'resourceKind' => self::RESOURCE_KIND_FOLDER,
            'resourceToken' => $folderAuditToken,
            'fileName' => $zipLabel,
            'mimeType' => 'application/zip',
            'downloadKey' => $downloadKey,
            'inlinePayloadOmitted' => true,
        ], 200);
    }

    /**
     * @brief Download decrypted bytes once using a one-time key issued by verify (inactive public file → 404).
     * @param Request $request Request carrying query `key`.
     * @return Response
     * @date 2026-05-02
     * @author Stephane H.
     */
    #[Route('/download/public/file', name: 'download_public_file', methods: ['GET', 'HEAD'])]
    public function downloadFile(Request $request): Response
    {
        $key = (string) $request->query->get('key', '');
        if ($key === '' || strlen($key) < 32) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }
        $cacheKey = $this->ticketCacheKey($key);
        $item = $this->publicDownloadTicketCache->getItem($cacheKey);
        if (!$item->isHit()) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }
        $data = $item->get();
        if (!is_array($data)) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }
        $claimChallengeId = (int) ($data['challengeId'] ?? 0);
        $publicToken = (string) ($data['publicToken'] ?? '');
        $resourceKind = (string) ($data['resourceKind'] ?? self::RESOURCE_KIND_FILE);
        if ($claimChallengeId < 1 || $publicToken === '') {
            return new Response('', Response::HTTP_NOT_FOUND);
        }
        if ($resourceKind === self::RESOURCE_KIND_FOLDER) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }
        $challenge = $this->publicDownloadTotpService->findChallengeById($claimChallengeId);
        if ($challenge === null || $challenge->getPublicToken() !== $publicToken || !$challenge->isVerified()) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }
        $sharedFile = $this->sharedFileRepository->findOneByPublicToken($publicToken);
        try {
            $sharedFile = $this->publicLandingAccessService->requireAccessiblePublicSharedFile($sharedFile);
        } catch (NotFoundHttpException) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }
        $ip = (string) ($request->getClientIp() ?? '0.0.0.0');
        $resourceToken = $publicToken;
        $storagePath = $sharedFile->getStoragePath();
        if ($storagePath === '' || !is_readable($storagePath)) {
            $this->downloadAuditService->createDenied($challenge->getEmail(), $ip, $resourceToken);

            return new Response('', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $downloadId = $this->downloadDiagnosticLogger->newDownloadId();
        $byteSize = (int) $sharedFile->getByteSize();
        $hasRange = $request->headers->has('Range');
        $this->downloadDiagnosticLogger->log($downloadId, $hasRange ? 'stream_range' : 'stream_start', 'ok', [
            'sharedFileId' => (int) ($sharedFile->getId() ?? 0),
            'actorType' => 'public',
            'bytesTotal' => $byteSize,
        ]);

        $mime = $this->detectMimeTypeFromExtension($sharedFile);
        $response = $this->encryptedStreamDeliveryService->buildEncryptedStreamResponse(
            $request,
            $storagePath,
            $byteSize,
            $mime,
            EncryptedStreamDeliveryService::DISPOSITION_ATTACHMENT,
            $sharedFile->getOriginalFileName(),
            true,
            true,
        );

        if ($response->getStatusCode() >= 400) {
            $this->downloadDiagnosticLogger->log($downloadId, 'stream_end', 'error', [
                'sharedFileId' => (int) ($sharedFile->getId() ?? 0),
                'actorType' => 'public',
                'httpStatus' => $response->getStatusCode(),
            ]);

            return $response;
        }

        if (!$hasRange && !$request->isMethod('HEAD')) {
            $this->downloadAuditService->create($challenge->getEmail(), $ip, $resourceToken);
        }

        $this->downloadDiagnosticLogger->log($downloadId, 'stream_end', 'ok', [
            'sharedFileId' => (int) ($sharedFile->getId() ?? 0),
            'actorType' => 'public',
            'httpStatus' => $response->getStatusCode(),
            'bytesTotal' => $byteSize,
            'bytesSent' => (int) ($response->headers->get('Content-Length') ?? $byteSize),
        ]);

        return $response;
    }

    /**
     * @brief Stream a one-time folder ZIP using a key issued by verify.
     * @param Request $request Request carrying query `key`.
     * @return Response
     * @date 2026-05-02
     * @author Stephane H.
     */
    #[Route('/download/public/folder', name: 'download_public_folder', methods: ['GET'])]
    public function downloadFolder(Request $request): Response
    {
        $locale = $request->getLocale();
        $key = (string) $request->query->get('key', '');
        if ($key === '' || strlen($key) < 32) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }
        $cacheKey = $this->ticketCacheKey($key);
        $item = $this->publicDownloadTicketCache->getItem($cacheKey);
        if (!$item->isHit()) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }
        $data = $item->get();
        if (!is_array($data)) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }
        $this->publicDownloadTicketCache->deleteItem($cacheKey);
        $claimChallengeId = (int) ($data['challengeId'] ?? 0);
        $publicToken = (string) ($data['publicToken'] ?? '');
        $resourceKind = (string) ($data['resourceKind'] ?? '');
        if ($claimChallengeId < 1 || $publicToken === '' || $resourceKind !== self::RESOURCE_KIND_FOLDER) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }
        $challenge = $this->publicDownloadTotpService->findChallengeById($claimChallengeId);
        if ($challenge === null || $challenge->getPublicToken() !== $publicToken || !$challenge->isVerified()) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }
        $folder = $this->folderRepository->findOneByPublicFolderToken($publicToken);
        try {
            $folder = $this->publicLandingAccessService->requireAccessiblePublicFolder($folder);
        } catch (NotFoundHttpException) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        try {
            $built = $this->publicFolderZipService->buildPublicSubtreeZip($folder->getOwnerUserId(), $folder);
        } catch (\RuntimeException $exception) {
            $messageKey = $exception->getMessage();
            $text = $this->translator->trans($messageKey, [], 'messages', $locale);
            $status = Response::HTTP_BAD_REQUEST;
            if ($messageKey === 'download.public_folder.zip_limit_bytes' || $messageKey === 'download.public_folder.zip_limit_files') {
                $status = Response::HTTP_REQUEST_ENTITY_TOO_LARGE;
            }

            return new Response($text, $status, [
                'Content-Type' => 'text/plain; charset=utf-8',
            ]);
        }

        $zipPath = $built['zip_path'];
        $zipName = $built['zip_name'];

        return new StreamedResponse(function () use ($zipPath): void {
            $handle = fopen($zipPath, 'rb');
            if ($handle !== false) {
                fpassthru($handle);
                fclose($handle);
            }
            if (is_file($zipPath)) {
                @unlink($zipPath);
            }
        }, Response::HTTP_OK, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $zipName,
                'folder.zip'
            ),
        ]);
    }

    /**
     * @brief PSR-6 cache key for a download ticket.
     * @param string $downloadKey Random hex token.
     * @return string
     * @date 2026-05-02
     * @author Stephane H.
     */
    private function ticketCacheKey(string $downloadKey): string
    {
        return self::TICKET_CACHE_PREFIX.hash('sha256', $downloadKey);
    }

    /**
     * @brief MIME guess from extension only (large-file safe).
     * @param SharedFile $sharedFile Shared file aggregate.
     * @return string
     * @date 2026-05-03
     * @author Stephane H.
     */
    private function detectMimeTypeFromExtension(SharedFile $sharedFile): string
    {
        $ext = strtolower($sharedFile->getFileExtension());
        $map = [
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'txt' => 'text/plain; charset=utf-8',
        ];

        return $map[$ext] ?? 'application/octet-stream';
    }

    /**
     * @brief Best-effort MIME from decrypted bytes and file metadata.
     * @param string $content Decrypted file bytes.
     * @param SharedFile $sharedFile Shared file aggregate.
     * @return string
     * @date 2026-05-02
     * @author Stephane H.
     */
    private function detectMimeType(string $content, SharedFile $sharedFile): string
    {
        if ($content !== '' && class_exists(\finfo::class)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $detected = $finfo->buffer($content);
            if (is_string($detected) && $detected !== '') {
                return $detected;
            }
        }

        return $this->detectMimeTypeFromExtension($sharedFile);
    }
}
