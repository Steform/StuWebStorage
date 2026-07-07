<?php

namespace App\Controller;

use App\File\PublicImagePreviewSupport;
use App\Repository\SharedFileRepository;
use App\Service\Share\PublicLandingAccessService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Controller for anonymous landing page behind the copyable "public file" link (Sprint 22+).
 */
class PublicFileLandingController extends AbstractController
{
    /**
     * @brief Wire repository for public token lookup.
     * @param SharedFileRepository $sharedFileRepository Shared file aggregate repository.
     * @return void
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function __construct(
        private readonly SharedFileRepository $sharedFileRepository,
        private readonly PublicLandingAccessService $publicLandingAccessService,
        private readonly int $downloadManagerUiThresholdBytes = 209715200,
    ) {
    }

    /**
     * @brief Render the public download landing page (metadata, preview after verification, email TOTP flow).
     * @param Request $request Current HTTP request (locale).
     * @param string $publicToken Opaque public token (hex, 32+ chars) identifying the shared file.
     * @return Response
     * @date 2026-05-02
     * @author Stephane H.
     */
    #[Route(
        path: '/p/{publicToken}',
        name: 'file_public_landing',
        methods: ['GET'],
        requirements: ['publicToken' => '[a-f0-9]{32,128}'],
    )]
    public function show(Request $request, string $publicToken): Response
    {
        $sharedFile = $this->sharedFileRepository->findOneByPublicToken($publicToken);
        $sharedFile = $this->publicLandingAccessService->requireAccessiblePublicSharedFile($sharedFile);

        $isImageType = PublicImagePreviewSupport::isImageExtension($sharedFile->getFileExtension());
        $sharePasswordPrefill = (string) $request->query->get('share_password', '');

        return $this->render('public_file/landing.html.twig', [
            'currentLocale' => $request->getLocale(),
            'sharedFile' => $sharedFile,
            'isImageType' => $isImageType,
            'publicToken' => $publicToken,
            'needsSharePassword' => $sharedFile->isPublicPasswordGateActive(),
            'sharePasswordPrefill' => $sharePasswordPrefill !== '' ? rawurldecode($sharePasswordPrefill) : '',
            'downloadManagerUiThresholdBytes' => $this->downloadManagerUiThresholdBytes,
        ]);
    }
}
