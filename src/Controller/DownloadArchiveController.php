<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\DownloadArchive;
use App\Entity\Folder;
use App\Entity\SharedFile;
use App\Entity\User;
use App\Repository\DownloadArchiveRepository;
use App\Repository\FolderRepository;
use App\Repository\SharedFileRepository;
use App\Service\Download\DownloadArchiveService;
use App\Service\Download\DownloadGrantService;
use App\Service\File\FilesQueryScopeResolver;
use App\Service\Share\FolderShareAuthorizationService;
use App\Service\Share\FolderTreeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @brief Authenticated API for creating and preparing virtual ZIP archives.
 * @date 2026-11-02
 * @author Stephane H.
 */
final class DownloadArchiveController extends AbstractController
{
    public const CSRF_ARCHIVE = 'files_download_archive';

    /**
     * @brief Construct archive API controller.
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function __construct(
        private readonly DownloadArchiveService $downloadArchiveService,
        private readonly DownloadArchiveRepository $downloadArchiveRepository,
        private readonly DownloadGrantService $downloadGrantService,
        private readonly SharedFileRepository $sharedFileRepository,
        private readonly FolderRepository $folderRepository,
        private readonly FolderTreeService $folderTreeService,
        private readonly FolderShareAuthorizationService $folderShareAuthorizationService,
        private readonly FilesQueryScopeResolver $filesQueryScopeResolver,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    /**
     * @brief Create a virtual archive from the current selection.
     * @param Request $request HTTP request.
     * @return JsonResponse
     * @date 2026-11-02
     * @author Stephane H.
     */
    #[Route('/files/archives', name: 'files_archives_create', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function create(Request $request): JsonResponse
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_ARCHIVE, (string) $request->request->get('_csrf_token', '')))) {
            return new JsonResponse(['status' => 'error', 'message' => 'files.flash.csrf_invalid'], 403);
        }

        /** @var User $user */
        $user = $this->getUser();
        $fileIds = $this->parseIdList($request->request->all()['ids'] ?? []);
        $folderIds = $this->parseIdList($request->request->all()['folder_ids'] ?? []);
        if ($fileIds === [] && $folderIds === []) {
            return new JsonResponse(['status' => 'error', 'message' => 'files.flash.bulk_no_ids'], 400);
        }

        $effectiveOwnerId = (int) $user->getId();
        if ($this->isGranted('ROLE_ADMIN')) {
            $subject = (int) $request->request->get('subject_user', 0);
            if ($subject > 0) {
                $effectiveOwnerId = $subject;
            } elseif ($folderIds !== []) {
                $owners = [];
                foreach ($folderIds as $folderId) {
                    $folder = $this->folderRepository->find($folderId);
                    if ($folder instanceof Folder) {
                        $owners[(int) $folder->getOwnerUserId()] = true;
                    }
                }
                if (count($owners) === 1) {
                    $effectiveOwnerId = (int) array_key_first($owners);
                }
            }
        }

        try {
            $archive = $this->downloadArchiveService->createFromSelection(
                $user,
                fn (User $actor, SharedFile $file): bool => $this->canUserDownloadSharedFile($actor, $file),
                $fileIds,
                $folderIds,
                $effectiveOwnerId,
            );
        } catch (\RuntimeException $e) {
            $status = match ($e->getMessage()) {
                'download.archive_too_large', 'download.archive_too_many_entries' => 413,
                'download.archive_empty' => 400,
                default => 400,
            };

            return new JsonResponse(['status' => 'error', 'message' => $e->getMessage()], $status);
        }

        $payload = $this->downloadArchiveService->toPublicArray($archive);
        $payload['status'] = 'ok';
        $payload['archiveStatus'] = $archive->getStatus();
        if ($archive->isReady()) {
            $payload['download_grant'] = $this->mintArchiveGrant($user, $archive);
            $payload['download_url'] = $this->generateUrl('download_delivery_archive', ['token' => $archive->getToken()]);
        }

        return new JsonResponse($payload, 201);
    }

    /**
     * @brief List recent archives for the current user.
     * @return JsonResponse
     * @date 2026-11-02
     * @author Stephane H.
     */
    #[Route('/files/archives', name: 'files_archives_list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function list(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $rows = $this->downloadArchiveRepository->findRecentForActor(
            DownloadGrantService::ACTOR_USER,
            (string) $user->getId(),
        );
        $items = [];
        foreach ($rows as $archive) {
            $item = $this->downloadArchiveService->toPublicArray($archive);
            if ($archive->isReady()) {
                $item['download_url'] = $this->generateUrl('download_delivery_archive', ['token' => $archive->getToken()]);
            }
            $items[] = $item;
        }

        return new JsonResponse(['status' => 'ok', 'items' => $items]);
    }

    /**
     * @brief Read one archive status.
     * @param string $token Archive token.
     * @return JsonResponse
     * @date 2026-11-02
     * @author Stephane H.
     */
    #[Route('/files/archives/{token}', name: 'files_archives_show', methods: ['GET'], requirements: ['token' => '[a-f0-9]{32}'])]
    #[IsGranted('ROLE_USER')]
    public function show(string $token): JsonResponse
    {
        $archive = $this->requireOwnedArchive($token);
        $payload = $this->downloadArchiveService->toPublicArray($archive);
        $payload['status'] = 'ok';
        $payload['archiveStatus'] = $archive->getStatus();
        if ($archive->isReady()) {
            /** @var User $user */
            $user = $this->getUser();
            $payload['download_grant'] = $this->mintArchiveGrant($user, $archive);
            $payload['download_url'] = $this->generateUrl('download_delivery_archive', ['token' => $archive->getToken()]);
        }

        return new JsonResponse($payload);
    }

    /**
     * @brief Advance archive preparation by one tick.
     * @param Request $request HTTP request.
     * @param string $token Archive token.
     * @return JsonResponse
     * @date 2026-11-02
     * @author Stephane H.
     */
    #[Route('/files/archives/{token}/tick', name: 'files_archives_tick', methods: ['POST'], requirements: ['token' => '[a-f0-9]{32}'])]
    #[IsGranted('ROLE_USER')]
    public function tick(Request $request, string $token): JsonResponse
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_ARCHIVE, (string) $request->request->get('_csrf_token', '')))) {
            return new JsonResponse(['status' => 'error', 'message' => 'files.flash.csrf_invalid'], 403);
        }
        $archive = $this->requireOwnedArchive($token);
        $progress = $this->downloadArchiveService->tickPrepare($archive);
        $payload = $this->downloadArchiveService->toPublicArray($archive);
        $payload['status'] = 'ok';
        $payload['archiveStatus'] = $progress['status'];
        if ($archive->isReady()) {
            /** @var User $user */
            $user = $this->getUser();
            $payload['download_grant'] = $this->mintArchiveGrant($user, $archive);
            $payload['download_url'] = $this->generateUrl('download_delivery_archive', ['token' => $archive->getToken()]);
        }

        return new JsonResponse($payload);
    }

    /**
     * @brief Issue a download grant for a ready archive.
     * @param Request $request HTTP request.
     * @param string $token Archive token.
     * @return JsonResponse
     * @date 2026-11-02
     * @author Stephane H.
     */
    #[Route('/files/archives/{token}/grant', name: 'files_archives_grant', methods: ['POST'], requirements: ['token' => '[a-f0-9]{32}'])]
    #[IsGranted('ROLE_USER')]
    public function grant(Request $request, string $token): JsonResponse
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_ARCHIVE, (string) $request->request->get('_csrf_token', '')))) {
            return new JsonResponse(['status' => 'error', 'message' => 'files.flash.csrf_invalid'], 403);
        }
        $archive = $this->requireOwnedArchive($token);
        if (!$archive->isReady()) {
            return new JsonResponse(['status' => 'error', 'message' => 'download.archive_not_ready'], 409);
        }
        /** @var User $user */
        $user = $this->getUser();

        return new JsonResponse([
            'status' => 'ok',
            'download_grant' => $this->mintArchiveGrant($user, $archive),
            'download_url' => $this->generateUrl('download_delivery_archive', ['token' => $archive->getToken()]),
            'expires_in' => $this->downloadGrantService->getTtlSeconds(),
            'file_name' => $archive->getFileName(),
            'bytes' => $archive->getTotalBytes(),
        ]);
    }

    /**
     * @brief Delete one archive manifest.
     * @param Request $request HTTP request.
     * @param string $token Archive token.
     * @return JsonResponse
     * @date 2026-11-02
     * @author Stephane H.
     */
    #[Route('/files/archives/{token}', name: 'files_archives_delete', methods: ['DELETE'], requirements: ['token' => '[a-f0-9]{32}'])]
    #[IsGranted('ROLE_USER')]
    public function delete(Request $request, string $token): JsonResponse
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_ARCHIVE, (string) $request->request->get('_csrf_token', '')))) {
            return new JsonResponse(['status' => 'error', 'message' => 'files.flash.csrf_invalid'], 403);
        }
        $archive = $this->requireOwnedArchive($token);
        $this->downloadArchiveRepository->remove($archive);

        return new JsonResponse(['status' => 'ok']);
    }

    /**
     * @brief Create archive for one owned folder subtree.
     * @param Request $request HTTP request.
     * @param int $id Folder id.
     * @return JsonResponse
     * @date 2026-11-02
     * @author Stephane H.
     */
    #[Route('/files/folders/{id}/archives', name: 'files_folder_archives_create', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_SHARE_SEND')]
    public function createFolderArchive(Request $request, int $id): JsonResponse
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_ARCHIVE, (string) $request->request->get('_csrf_token', '')))) {
            return new JsonResponse(['status' => 'error', 'message' => 'files.flash.csrf_invalid'], 403);
        }
        /** @var User $user */
        $user = $this->getUser();
        $ownerId = (int) $user->getId();
        $folder = $this->folderTreeService->resolveCurrentFolder($ownerId, $id);
        if (!$folder instanceof Folder) {
            return new JsonResponse(['status' => 'error', 'message' => 'files.flash.not_found'], 404);
        }

        try {
            $archive = $this->downloadArchiveService->createFromSelection(
                $user,
                fn (User $actor, SharedFile $file): bool => $this->canUserDownloadSharedFile($actor, $file),
                [],
                [$id],
                $ownerId,
                DownloadArchive::KIND_FOLDER,
            );
        } catch (\RuntimeException $e) {
            return new JsonResponse(['status' => 'error', 'message' => $e->getMessage()], 400);
        }

        $payload = $this->downloadArchiveService->toPublicArray($archive);
        $payload['status'] = 'ok';
        $payload['archiveStatus'] = $archive->getStatus();
        if ($archive->isReady()) {
            $payload['download_grant'] = $this->mintArchiveGrant($user, $archive);
            $payload['download_url'] = $this->generateUrl('download_delivery_archive', ['token' => $archive->getToken()]);
        }

        return new JsonResponse($payload, 201);
    }

    /**
     * @brief Require archive owned by current user.
     * @param string $token Archive token.
     * @return DownloadArchive
     * @date 2026-11-02
     * @author Stephane H.
     */
    private function requireOwnedArchive(string $token): DownloadArchive
    {
        /** @var User $user */
        $user = $this->getUser();
        $archive = $this->downloadArchiveRepository->findOneByToken($token);
        if (!$archive instanceof DownloadArchive) {
            throw $this->createNotFoundException();
        }
        if ($archive->getActorType() !== DownloadGrantService::ACTOR_USER || $archive->getActorId() !== (string) $user->getId()) {
            throw $this->createNotFoundException();
        }

        return $archive;
    }

    /**
     * @brief Mint archive grant for actor.
     * @param User $user User.
     * @param DownloadArchive $archive Archive.
     * @return string
     * @date 2026-11-02
     * @author Stephane H.
     */
    private function mintArchiveGrant(User $user, DownloadArchive $archive): string
    {
        return $this->downloadGrantService->mint(
            DownloadGrantService::KIND_ARCHIVE,
            $archive->getToken(),
            DownloadGrantService::ACTOR_USER,
            (string) $user->getId(),
            (string) ($archive->getEtag() ?? ''),
        );
    }

    /**
     * @brief Parse a list of positive integer ids.
     * @param mixed $raw Raw request value.
     * @return list<int>
     * @date 2026-11-02
     * @author Stephane H.
     */
    private function parseIdList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $token) {
            $id = (int) $token;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
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
            return true;
        }
        if ($sharedFile->getOwnerUserId() === $requesterId && $this->isGranted('ROLE_SHARE_SEND')) {
            return true;
        }

        return $this->folderShareAuthorizationService->canAccessFileViaFriends($sharedFile, $requesterId);
    }
}
