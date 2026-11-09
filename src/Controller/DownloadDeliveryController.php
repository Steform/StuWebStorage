<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\DownloadArchive;
use App\Entity\SharedFile;
use App\Entity\User;
use App\Repository\DownloadArchiveRepository;
use App\Repository\SharedFileRepository;
use App\Repository\UserRepository;
use App\Service\Audit\DownloadAuditService;
use App\Service\Audit\DownloadDiagnosticLogger;
use App\Service\Download\ArchiveDownloadResource;
use App\Service\Download\DownloadGrantService;
use App\Service\Download\EncryptedRangeReader;
use App\Service\Download\FileDownloadResource;
use App\Service\Download\RangeStreamResponder;
use App\Service\Share\FolderShareAuthorizationService;
use App\Service\Share\PublicDownloadTotpService;
use App\Service\Share\PublicLandingAccessService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * @brief Stateless Range delivery endpoints for files and virtual archives.
 * @date 2026-11-02
 * @author Stephane H.
 */
final class DownloadDeliveryController extends AbstractController
{
    /**
     * @brief Construct delivery controller.
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function __construct(
        private readonly DownloadGrantService $downloadGrantService,
        private readonly RangeStreamResponder $rangeStreamResponder,
        private readonly EncryptedRangeReader $encryptedRangeReader,
        private readonly SharedFileRepository $sharedFileRepository,
        private readonly DownloadArchiveRepository $downloadArchiveRepository,
        private readonly FolderShareAuthorizationService $folderShareAuthorizationService,
        private readonly UserRepository $userRepository,
        private readonly PublicDownloadTotpService $publicDownloadTotpService,
        private readonly PublicLandingAccessService $publicLandingAccessService,
        private readonly DownloadDiagnosticLogger $downloadDiagnosticLogger,
        private readonly DownloadAuditService $downloadAuditService,
    ) {
    }

    /**
     * @brief Deliver one shared file by grant (Range capable).
     * @param Request $request HTTP request.
     * @param int $id Shared file id.
     * @return Response
     * @date 2026-11-02
     * @author Stephane H.
     */
    #[Route('/dl/file/{id}', name: 'download_delivery_file', methods: ['GET', 'HEAD'], requirements: ['id' => '\d+'])]
    public function deliverFile(Request $request, int $id): Response
    {
        $raw = $this->downloadGrantService->extractRawFromRequest($request);
        $grant = $this->downloadGrantService->parse(
            $raw,
            null,
            (string) $id,
        );
        if ($grant === null) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }
        if (!\in_array($grant['kind'], [DownloadGrantService::KIND_FILE, DownloadGrantService::KIND_PUBLIC_FILE], true)) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        $sharedFile = $this->sharedFileRepository->find($id);
        if (!$sharedFile instanceof SharedFile) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        if (!$this->authorizeFileGrant($grant, $sharedFile)) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        $path = $sharedFile->getStoragePath();
        if ($path === '' || !is_readable($path)) {
            return new Response('', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $downloadId = $this->downloadDiagnosticLogger->newDownloadId();
        $resource = new FileDownloadResource($sharedFile, $this->encryptedRangeReader);
        $response = $this->rangeStreamResponder->respond($request, $resource, [
            'downloadId' => $downloadId,
            'sharedFileId' => $id,
            'actorType' => $grant['actorType'],
            'bytesTotal' => $sharedFile->getByteSize(),
            'extra' => ['resource_kind' => 'file'],
        ]);

        if (!$request->headers->has('Range') && !$request->isMethod('HEAD') && $response->getStatusCode() < 400) {
            $ip = (string) ($request->getClientIp() ?? '0.0.0.0');
            $actorLabel = $grant['actorType'].':'.$grant['actorId'];
            $this->downloadAuditService->create($actorLabel, $ip, $sharedFile->getPublicToken());
        }

        return $response;
    }

    /**
     * @brief Deliver one virtual archive by grant (Range capable).
     * @param Request $request HTTP request.
     * @param string $token Archive token.
     * @return Response
     * @date 2026-11-02
     * @author Stephane H.
     */
    #[Route('/dl/archive/{token}', name: 'download_delivery_archive', methods: ['GET', 'HEAD'], requirements: ['token' => '[a-f0-9]{32}'])]
    public function deliverArchive(Request $request, string $token): Response
    {
        $raw = $this->downloadGrantService->extractRawFromRequest($request);
        $grant = $this->downloadGrantService->parse($raw, null, $token);
        if ($grant === null) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }
        if (!\in_array($grant['kind'], [DownloadGrantService::KIND_ARCHIVE, DownloadGrantService::KIND_PUBLIC_ARCHIVE], true)) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        $archive = $this->downloadArchiveRepository->findOneByToken($token);
        if (!$archive instanceof DownloadArchive) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }
        if ($archive->getActorType() !== $grant['actorType'] || $archive->getActorId() !== $grant['actorId']) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }
        if ($archive->isExpiredAt() || $archive->getStatus() === DownloadArchive::STATUS_EXPIRED) {
            return new Response('', Response::HTTP_GONE);
        }
        if (!$archive->isReady()) {
            return new Response('', Response::HTTP_CONFLICT);
        }

        try {
            $resource = ArchiveDownloadResource::fromReadyArchive(
                $archive,
                $this->sharedFileRepository,
                $this->encryptedRangeReader,
            );
        } catch (\RuntimeException $e) {
            $this->downloadDiagnosticLogger->log(
                $this->downloadDiagnosticLogger->newDownloadId(),
                'archive_invalidated',
                'error',
                [
                    'errorCode' => $e->getMessage(),
                    'extra' => ['archive_token' => $token],
                ],
            );

            return new Response('', Response::HTTP_GONE);
        }

        // Re-check ACL for authenticated archives (each entry still owned/accessible).
        if ($grant['actorType'] === DownloadGrantService::ACTOR_USER) {
            $user = $this->userRepository->find((int) $grant['actorId']);
            if (!$user instanceof User) {
                return new Response('', Response::HTTP_FORBIDDEN);
            }
            foreach ($archive->getEntriesJson() as $entry) {
                $file = $this->sharedFileRepository->find((int) ($entry['fileId'] ?? 0));
                if (!$file instanceof SharedFile || !$this->canUserDownloadSharedFile($user, $file)) {
                    return new Response('', Response::HTTP_GONE);
                }
            }
        }

        $archive->touchLastAccess();
        $ttl = max(3600, (int) ($archive->getExpiresAt()->getTimestamp() - time()));
        if ($ttl < 86400 * 7) {
            $archive->setExpiresAt((new \DateTimeImmutable())->modify('+7 days'));
        }
        $this->downloadArchiveRepository->save($archive);

        $downloadId = $this->downloadDiagnosticLogger->newDownloadId();

        return $this->rangeStreamResponder->respond($request, $resource, [
            'downloadId' => $downloadId,
            'actorType' => $grant['actorType'],
            'bytesTotal' => $resource->getSize(),
            'extra' => ['resource_kind' => 'archive', 'archive_token' => $token],
        ]);
    }

    /**
     * @brief Authorize a file grant against ACL / public challenge.
     * @param array{kind: string, actorType: string, actorId: string} $grant Parsed grant.
     * @param SharedFile $sharedFile Target file.
     * @return bool
     * @date 2026-11-02
     * @author Stephane H.
     */
    private function authorizeFileGrant(array $grant, SharedFile $sharedFile): bool
    {
        if ($grant['actorType'] === DownloadGrantService::ACTOR_USER) {
            $user = $this->userRepository->find((int) $grant['actorId']);
            if (!$user instanceof User) {
                return false;
            }

            return $this->canUserDownloadSharedFile($user, $sharedFile);
        }

        if ($grant['actorType'] === DownloadGrantService::ACTOR_PUBLIC_CHALLENGE) {
            $challenge = $this->publicDownloadTotpService->findChallengeById((int) $grant['actorId']);
            if ($challenge === null || !$challenge->isVerified()) {
                return false;
            }
            if ($challenge->getPublicToken() !== $sharedFile->getPublicToken()) {
                return false;
            }
            try {
                $this->publicLandingAccessService->requireAccessiblePublicSharedFile($sharedFile);
            } catch (NotFoundHttpException) {
                return false;
            }

            return true;
        }

        return false;
    }

    /**
     * @brief Whether a user can download a given shared file.
     * @param User $user User.
     * @param SharedFile $sharedFile File.
     * @return bool
     * @date 2026-11-02
     * @author Stephane H.
     */
    private function canUserDownloadSharedFile(User $user, SharedFile $sharedFile): bool
    {
        $requesterId = (int) $user->getId();
        if ($this->isGranted('ROLE_ADMIN')) {
            // Stateless grants cannot rely on current security token for admin; use roles on loaded user.
            if (\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
                return true;
            }
        }
        if (\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }
        if ($sharedFile->getOwnerUserId() === $requesterId && \in_array('ROLE_SHARE_SEND', $user->getRoles(), true)) {
            return true;
        }

        return $this->folderShareAuthorizationService->canAccessFileViaFriends($sharedFile, $requesterId);
    }
}
