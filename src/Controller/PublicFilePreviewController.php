<?php

namespace App\Controller;

use App\File\PublicImagePreviewSupport;
use App\Repository\SharedFileRepository;
use App\Entity\SharedFile;
use App\Service\File\FileEncryptionService;
use App\Service\Share\PublicLandingAccessService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\Routing\Attribute\Route;

/**
 * @brief Serves a public inline image preview for active public shares (anyone with the link).
 */
class PublicFilePreviewController extends AbstractController
{
    public function __construct(
        private readonly SharedFileRepository $sharedFileRepository,
        private readonly FileEncryptionService $fileEncryptionService,
        private readonly PublicLandingAccessService $publicLandingAccessService,
    ) {
    }

    /**
     * @brief Return decrypted image bytes for inline display (not for TOTP; download still requires e-mail code).
     * @param string $publicToken Opaque public token in the share URL.
     * @return Response
     * @date 2026-05-02
     * @author Stephane H.
     */
    #[Route(
        path: '/p/{publicToken}/preview',
        name: 'file_public_preview',
        methods: ['GET'],
        requirements: ['publicToken' => '[a-f0-9]{32,128}'],
    )]
    public function show(string $publicToken): Response
    {
        $sharedFile = $this->sharedFileRepository->findOneByPublicToken($publicToken);
        $sharedFile = $this->publicLandingAccessService->requireAccessiblePublicSharedFile($sharedFile);
        if (!PublicImagePreviewSupport::isImageExtension($sharedFile->getFileExtension())) {
            throw $this->createNotFoundException();
        }

        try {
            $content = $this->fileEncryptionService->decryptFromStorage($sharedFile->getStoragePath());
        } catch (\RuntimeException) {
            return new Response('', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $mime = $this->detectMimeType($content, $sharedFile);
        $inline = HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_INLINE,
            $sharedFile->getOriginalFileName(),
            'preview.bin'
        );

        return new Response(
            $content,
            Response::HTTP_OK,
            [
                'Content-Type' => $mime,
                'Content-Disposition' => $inline,
                'Content-Length' => (string) strlen($content),
                'Cache-Control' => 'private, max-age=300',
            ]
        );
    }

    /**
     * @brief Best-effort MIME from bytes and file metadata.
     * @param string $content Decrypted file bytes.
     * @param SharedFile $sharedFile Shared file row.
     * @return string
     * @date 2026-04-28
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

        $ext = strtolower($sharedFile->getFileExtension());
        $map = [
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'bmp' => 'image/bmp',
            'ico' => 'image/x-icon',
        ];

        return $map[$ext] ?? 'application/octet-stream';
    }
}
