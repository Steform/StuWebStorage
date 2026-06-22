<?php

namespace App\Controller;

use App\Entity\SharedFile;
use App\Repository\FolderRepository;
use App\Repository\SharedFileRepository;
use App\Service\Share\FolderTreeService;
use App\Service\Share\PublicLandingAccessService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Anonymous landing page for public folder ZIP download (email TOTP flow).
 */
class PublicFolderLandingController extends AbstractController
{
    public function __construct(
        private readonly FolderRepository $folderRepository,
        private readonly FolderTreeService $folderTreeService,
        private readonly SharedFileRepository $sharedFileRepository,
        private readonly PublicLandingAccessService $publicLandingAccessService,
    ) {
    }

    /**
     * @brief Render public folder landing (metadata + email verification for ZIP download).
     * @param Request $request Current HTTP request (locale).
     * @param string $publicToken Folder public token (hex).
     * @return Response
     * @date 2026-05-02
     * @author Stephane H.
     */
    #[Route(
        path: '/p/folder/{publicToken}',
        name: 'folder_public_landing',
        priority: 10,
        methods: ['GET'],
        requirements: ['publicToken' => '[a-f0-9]{32,128}'],
    )]
    public function show(Request $request, string $publicToken): Response
    {
        $folder = $this->folderRepository->findOneByPublicFolderToken($publicToken);
        $folder = $this->publicLandingAccessService->requireAccessiblePublicFolder($folder);

        $ownerId = $folder->getOwnerUserId();
        $publicFileCount = 0;
        foreach ($this->folderTreeService->collectSubtreeFolders($ownerId, $folder) as $subFolder) {
            $rows = $this->sharedFileRepository->findBy([
                'ownerUserId' => $ownerId,
                'folder' => $subFolder,
            ]);
            foreach ($rows as $row) {
                if ($row instanceof SharedFile && $row->isPublicShareActive()) {
                    ++$publicFileCount;
                }
            }
        }

        $sharePasswordPrefill = (string) $request->query->get('share_password', '');

        return $this->render('public_folder/landing.html.twig', [
            'currentLocale' => $request->getLocale(),
            'folder' => $folder,
            'publicToken' => $publicToken,
            'publicFileCount' => $publicFileCount,
            'needsSharePassword' => $folder->isPublicPasswordGateActive(),
            'sharePasswordPrefill' => $sharePasswordPrefill !== '' ? rawurldecode($sharePasswordPrefill) : '',
        ]);
    }
}
