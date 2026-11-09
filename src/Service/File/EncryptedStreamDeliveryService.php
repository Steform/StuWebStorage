<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Service\Http\HttpByteRange;
use App\Service\Http\InvalidByteRangeException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @brief Build encrypted v2 stream responses with optional HTTP Range support.
 * @author Stephane H.
 * @date 2026-07-07
 */
final class EncryptedStreamDeliveryService
{
    public const DISPOSITION_INLINE = 'inline';

    public const DISPOSITION_ATTACHMENT = 'attachment';

    public function __construct(
        private readonly FileEncryptionService $fileEncryptionService,
        private readonly ?V2SegmentIndexService $segmentIndexService = null,
    ) {
    }

    /**
     * @brief Build a streamed decrypt response for encrypted storage.
     * @param Request $request HTTP request (Range / HEAD).
     * @param string $storagePath Readable encrypted storage path.
     * @param int $byteSize Plaintext byte size.
     * @param string $mime Content-Type value.
     * @param string $disposition inline or attachment.
     * @param string $fileName Suggested filename.
     * @param bool $rangeEnabled Whether to parse Range and emit 206.
     * @param bool $supportHead Whether HEAD returns headers without body.
     * @return Response
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function buildEncryptedStreamResponse(
        Request $request,
        string $storagePath,
        int $byteSize,
        string $mime,
        string $disposition,
        string $fileName,
        bool $rangeEnabled = true,
        bool $supportHead = true,
    ): Response {
        if ($storagePath === '' || !is_readable($storagePath)) {
            return new Response('', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        if (!$this->fileEncryptionService->isV2StorageFormat($storagePath)) {
            return $this->buildLegacyFullStreamResponse($request, $storagePath, $byteSize, $mime, $disposition, $fileName, $supportHead);
        }

        $byteRange = null;
        if ($rangeEnabled) {
            try {
                $byteRange = HttpByteRange::tryFromRequest($request, $byteSize);
            } catch (InvalidByteRangeException) {
                return new Response('', Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE, [
                    'Content-Range' => 'bytes */'.$byteSize,
                ]);
            }
        }

        $usePartialContent = $byteRange !== null;
        $statusCode = $usePartialContent ? Response::HTTP_PARTIAL_CONTENT : Response::HTTP_OK;

        if ($supportHead && $request->isMethod('HEAD')) {
            $response = new Response('', $statusCode);
        } elseif ($usePartialContent && $byteRange !== null) {
            $rangeStart = $byteRange->getStart();
            $rangeLength = $byteRange->getLength();
            $response = new StreamedResponse(function () use ($storagePath, $rangeStart, $rangeLength): void {
                @set_time_limit(0);
                @ignore_user_abort(true);
                $this->streamDecryptRange($storagePath, $rangeStart, $rangeLength);
            }, $statusCode);
        } else {
            $response = new StreamedResponse(function () use ($storagePath): void {
                @set_time_limit(0);
                @ignore_user_abort(true);
                $this->fileEncryptionService->streamDecryptStorageToStdout($storagePath);
            }, $statusCode);
        }

        $response->headers->set('Content-Type', $mime);
        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(
                $disposition === self::DISPOSITION_INLINE
                    ? HeaderUtils::DISPOSITION_INLINE
                    : HeaderUtils::DISPOSITION_ATTACHMENT,
                $fileName,
                'download.bin',
            ),
        );

        if ($rangeEnabled) {
            $response->headers->set('Accept-Ranges', 'bytes');
        }

        if ($usePartialContent && $byteRange !== null) {
            $response->headers->set('Content-Range', $byteRange->contentRangeHeader());
            $response->headers->set('Content-Length', (string) $byteRange->getLength());
        } else {
            $response->headers->set('Content-Length', (string) $byteSize);
        }

        return $response;
    }

    /**
     * @brief Stream a plaintext byte range using index when available.
     * @param string $storagePath Encrypted storage path.
     * @param int $plainStart Inclusive plaintext offset.
     * @param int $plainLength Number of plaintext bytes.
     * @return void
     * @date 2026-07-07
     * @author Stephane H.
     */
    private function streamDecryptRange(string $storagePath, int $plainStart, int $plainLength): void
    {
        $out = fopen('php://output', 'wb');
        if ($out === false) {
            throw new \RuntimeException('file.encryption.output_failed');
        }

        try {
            if ($this->segmentIndexService !== null) {
                $index = $this->segmentIndexService->loadIndex($storagePath);
                if ($index !== null) {
                    $this->fileEncryptionService->streamDecryptStorageRangeFromIndex(
                        $storagePath,
                        $index,
                        $plainStart,
                        $plainLength,
                        $out,
                    );

                    return;
                }
            }

            $this->fileEncryptionService->streamDecryptStorageRangeToHandle($storagePath, $plainStart, $plainLength, $out);
        } finally {
            fclose($out);
        }
    }

    /**
     * @brief Legacy v1 storage: full stream only, no Range.
     * @param Request $request HTTP request.
     * @param string $storagePath Encrypted storage path.
     * @param int $byteSize Declared plaintext size.
     * @param string $mime Content-Type.
     * @param string $disposition inline or attachment.
     * @param string $fileName Suggested filename.
     * @param bool $supportHead Whether HEAD is supported.
     * @return Response
     * @date 2026-07-07
     * @author Stephane H.
     */
    private function buildLegacyFullStreamResponse(
        Request $request,
        string $storagePath,
        int $byteSize,
        string $mime,
        string $disposition,
        string $fileName,
        bool $supportHead,
    ): Response {
        if ($supportHead && $request->isMethod('HEAD')) {
            $response = new Response('', Response::HTTP_OK);
        } else {
            $response = new StreamedResponse(function () use ($storagePath): void {
                @set_time_limit(0);
                @ignore_user_abort(true);
                $this->fileEncryptionService->streamDecryptStorageToStdout($storagePath);
            }, Response::HTTP_OK);
        }

        $response->headers->set('Content-Type', $mime);
        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(
                $disposition === self::DISPOSITION_INLINE
                    ? HeaderUtils::DISPOSITION_INLINE
                    : HeaderUtils::DISPOSITION_ATTACHMENT,
                $fileName,
                'download.bin',
            ),
        );
        $response->headers->set('Content-Length', (string) $byteSize);

        return $response;
    }
}
