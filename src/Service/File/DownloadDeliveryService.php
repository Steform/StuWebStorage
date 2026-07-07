<?php

declare(strict_types=1);

namespace App\Service\File;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * @brief Build long-lived download responses for prepared plaintext files.
 * @author Stephane H.
 * @date 2026-07-07
 */
final class DownloadDeliveryService
{
    public function __construct(
        private readonly bool $trustXSendfileTypeHeader = false,
    ) {
    }

    /**
     * @brief Build an attachment response for a prepared plaintext file.
     * @param string $plainPath Absolute readable plaintext path.
     * @param string $fileName Suggested download filename.
     * @param string $mime MIME type for Content-Type.
     * @return BinaryFileResponse
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function buildFileResponse(string $plainPath, string $fileName, string $mime): BinaryFileResponse
    {
        @set_time_limit(0);
        @ignore_user_abort(true);

        $response = new BinaryFileResponse($plainPath);
        $response->headers->set('Content-Type', $mime);
        $response->headers->set('Accept-Ranges', 'bytes');
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $fileName,
            'download.bin',
        );

        if ($this->trustXSendfileTypeHeader) {
            $response->trustXSendfileTypeHeader();
        }

        return $response;
    }
}
