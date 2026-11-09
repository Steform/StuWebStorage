<?php

declare(strict_types=1);

namespace App\Service\Download;

use App\Service\Audit\DownloadDiagnosticLogger;
use App\Service\Http\HttpByteRange;
use App\Service\Http\InvalidByteRangeException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @brief Build RFC 9110 Range responses for any DownloadResourceInterface.
 * @date 2026-11-02
 * @author Stephane H.
 */
final class RangeStreamResponder
{
    /**
     * @brief Construct responder.
     * @param DownloadDiagnosticLogger $downloadDiagnosticLogger Diagnostic logger.
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function __construct(
        private readonly DownloadDiagnosticLogger $downloadDiagnosticLogger,
    ) {
    }

    /**
     * @brief Build HEAD/GET response for a downloadable resource.
     * @param Request $request HTTP request.
     * @param DownloadResourceInterface $resource Resource to serve.
     * @param array<string, mixed> $diagContext Extra diagnostic context.
     * @return Response
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function respond(Request $request, DownloadResourceInterface $resource, array $diagContext = []): Response
    {
        $downloadId = is_string($diagContext['downloadId'] ?? null)
            ? (string) $diagContext['downloadId']
            : $this->downloadDiagnosticLogger->newDownloadId();

        $size = $resource->getSize();
        $etag = $resource->getStrongEtag();
        $lastModified = $resource->getLastModified();
        $supportsRanges = $resource->supportsRanges() && $size > 0;

        if ($this->ifNoneMatchSatisfied($request, $etag)) {
            $response = new Response('', Response::HTTP_NOT_MODIFIED);
            $this->applyCommonHeaders($response, $resource, $etag, $lastModified, $supportsRanges, false);

            return $response;
        }

        if ($this->ifMatchFailed($request, $etag)) {
            return new Response('', Response::HTTP_PRECONDITION_FAILED);
        }

        $byteRange = null;
        $rangeHeader = $request->headers->get('Range');
        $ifRange = $request->headers->get('If-Range');
        $honorRange = $supportsRanges && $rangeHeader !== null && $rangeHeader !== '';

        if ($honorRange && $ifRange !== null && $ifRange !== '' && !$this->ifRangeMatches($ifRange, $etag, $lastModified)) {
            $honorRange = false;
        }

        if ($honorRange) {
            try {
                $byteRange = HttpByteRange::parseHeader($rangeHeader, $size);
            } catch (InvalidByteRangeException) {
                $this->downloadDiagnosticLogger->log($downloadId, 'range_invalid', 'error', array_merge($diagContext, [
                    'httpStatus' => Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE,
                    'errorCode' => 'range_invalid',
                    'bytesTotal' => $size,
                    'extra' => ['range' => substr((string) $rangeHeader, 0, 128)],
                ]));

                return new Response('', Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE, [
                    'Content-Range' => 'bytes */'.$size,
                    'Accept-Ranges' => 'bytes',
                    'ETag' => $etag,
                ]);
            }
        }

        $isPartial = $byteRange !== null;
        $status = $isPartial ? Response::HTTP_PARTIAL_CONTENT : Response::HTTP_OK;
        $start = $isPartial ? $byteRange->getStart() : 0;
        $length = $isPartial ? $byteRange->getLength() : $size;

        $this->downloadDiagnosticLogger->log(
            $downloadId,
            $isPartial ? 'stream_range' : 'stream_start',
            'ok',
            array_merge($diagContext, [
                'bytesTotal' => $size,
                'httpStatus' => $status,
                'extra' => [
                    'range_start' => $start,
                    'range_end' => $start + max(0, $length) - 1,
                    'is_resume' => $start > 0 ? 1 : 0,
                ],
            ]),
        );

        if ($request->isMethod('HEAD')) {
            $response = new Response('', $status);
            $this->applyCommonHeaders($response, $resource, $etag, $lastModified, $supportsRanges, true);
            if ($isPartial && $byteRange !== null) {
                $response->headers->set('Content-Range', $byteRange->contentRangeHeader());
                $response->headers->set('Content-Length', (string) $byteRange->getLength());
            } else {
                $response->headers->set('Content-Length', (string) $size);
            }

            return $response;
        }

        $startedAt = microtime(true);
        $bytesSent = 0;
        $aborted = false;

        $response = new StreamedResponse(function () use (
            $resource,
            $start,
            $length,
            $downloadId,
            $diagContext,
            $size,
            $status,
            $startedAt,
            &$bytesSent,
            &$aborted,
        ): void {
            @set_time_limit(0);
            @ignore_user_abort(false);
            if (function_exists('session_write_close') && session_status() === PHP_SESSION_ACTIVE) {
                @session_write_close();
            }
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }

            try {
                if ($length < 1) {
                    return;
                }
                $resource->readRange($start, $length, function (string $chunk) use (&$bytesSent, &$aborted): void {
                    if ($aborted || $chunk === '') {
                        return;
                    }
                    echo $chunk;
                    $bytesSent += strlen($chunk);
                    if (function_exists('flush')) {
                        @flush();
                    }
                    if (connection_aborted()) {
                        $aborted = true;
                    }
                });
            } catch (\Throwable $e) {
                $this->downloadDiagnosticLogger->log($downloadId, 'stream_end', 'error', array_merge($diagContext, [
                    'bytesTotal' => $size,
                    'bytesSent' => $bytesSent,
                    'httpStatus' => $status,
                    'errorCode' => 'stream_failed',
                    'errorMessage' => substr($e->getMessage(), 0, 400),
                    'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
                ]));

                return;
            }

            $phase = $aborted ? 'stream_abort' : 'stream_end';
            $this->downloadDiagnosticLogger->log($downloadId, $phase, $aborted ? 'error' : 'ok', array_merge($diagContext, [
                'bytesTotal' => $size,
                'bytesSent' => $bytesSent,
                'httpStatus' => $status,
                'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
                'extra' => [
                    'range_start' => $start,
                    'client_aborted' => $aborted ? 1 : 0,
                    'is_resume' => $start > 0 ? 1 : 0,
                ],
            ]));
        }, $status);

        $this->applyCommonHeaders($response, $resource, $etag, $lastModified, $supportsRanges, true);
        if ($isPartial && $byteRange !== null) {
            $response->headers->set('Content-Range', $byteRange->contentRangeHeader());
            $response->headers->set('Content-Length', (string) $byteRange->getLength());
        } else {
            $response->headers->set('Content-Length', (string) $size);
        }

        return $response;
    }

    /**
     * @brief Apply shared response headers.
     * @param Response $response Response object.
     * @param DownloadResourceInterface $resource Resource.
     * @param string $etag Strong ETag.
     * @param \DateTimeImmutable $lastModified Last-Modified.
     * @param bool $supportsRanges Whether Accept-Ranges: bytes.
     * @param bool $withDisposition Whether to set Content-Disposition.
     * @return void
     * @date 2026-11-02
     * @author Stephane H.
     */
    private function applyCommonHeaders(
        Response $response,
        DownloadResourceInterface $resource,
        string $etag,
        \DateTimeImmutable $lastModified,
        bool $supportsRanges,
        bool $withDisposition,
    ): void {
        $response->headers->set('Content-Type', $resource->getMimeType());
        $response->headers->set('ETag', $etag);
        $response->headers->set('Last-Modified', $lastModified->setTimezone(new \DateTimeZone('GMT'))->format('D, d M Y H:i:s').' GMT');
        $response->headers->set('Cache-Control', 'private, no-transform, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Accept-Ranges', $supportsRanges ? 'bytes' : 'none');

        if ($withDisposition) {
            $fileName = $resource->getFileName();
            $fallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', $fileName) ?: 'download.bin';
            $response->headers->set(
                'Content-Disposition',
                HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $fileName, $fallback),
            );
        }
    }

    /**
     * @brief Whether If-None-Match matches the current ETag.
     * @param Request $request HTTP request.
     * @param string $etag Current ETag.
     * @return bool
     * @date 2026-11-02
     * @author Stephane H.
     */
    private function ifNoneMatchSatisfied(Request $request, string $etag): bool
    {
        $header = $request->headers->get('If-None-Match');
        if ($header === null || $header === '') {
            return false;
        }
        if (trim($header) === '*') {
            return true;
        }

        foreach (explode(',', $header) as $part) {
            if (trim($part) === $etag) {
                return true;
            }
        }

        return false;
    }

    /**
     * @brief Whether If-Match failed (resource changed).
     * @param Request $request HTTP request.
     * @param string $etag Current ETag.
     * @return bool
     * @date 2026-11-02
     * @author Stephane H.
     */
    private function ifMatchFailed(Request $request, string $etag): bool
    {
        $header = $request->headers->get('If-Match');
        if ($header === null || $header === '') {
            return false;
        }
        if (trim($header) === '*') {
            return false;
        }
        foreach (explode(',', $header) as $part) {
            if (trim($part) === $etag) {
                return false;
            }
        }

        return true;
    }

    /**
     * @brief Whether If-Range matches current validators.
     * @param string $ifRange If-Range header value.
     * @param string $etag Current ETag.
     * @param \DateTimeImmutable $lastModified Last-Modified.
     * @return bool
     * @date 2026-11-02
     * @author Stephane H.
     */
    private function ifRangeMatches(string $ifRange, string $etag, \DateTimeImmutable $lastModified): bool
    {
        $trimmed = trim($ifRange);
        if ($trimmed === $etag) {
            return true;
        }
        $ts = strtotime($trimmed);
        if ($ts === false) {
            return false;
        }

        return $ts >= $lastModified->getTimestamp();
    }
}
