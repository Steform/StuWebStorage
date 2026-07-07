<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\DownloadDiagnosticEventRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @brief Admin listing and export for download diagnostic events.
 */
#[IsGranted('ROLE_ADMIN')]
final class DownloadDiagnosticsAdminController extends AbstractController
{
    public function __construct(
        private readonly DownloadDiagnosticEventRepository $repository,
    ) {
    }

    /**
     * @brief Render filtered diagnostics table.
     * @param Request $request Current request.
     * @return Response
     * @date 2026-07-07
     * @author Stephane H.
     */
    #[Route('/admin/download-diagnostics', name: 'admin_download_diagnostics_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filters = [
            'downloadId' => trim((string) $request->query->get('download_id', '')),
            'phase' => trim((string) $request->query->get('phase', '')),
            'status' => trim((string) $request->query->get('status', '')),
            'sharedFileId' => (string) $request->query->get('shared_file_id', ''),
        ];

        $events = $this->repository->search($filters, 300);

        return $this->render('admin/download_diagnostics/index.html.twig', [
            'events' => $events,
            'filters' => $filters,
        ]);
    }

    /**
     * @brief Export filtered diagnostics as JSON or CSV.
     * @param Request $request Current request.
     * @return Response
     * @date 2026-07-07
     * @author Stephane H.
     */
    #[Route('/admin/download-diagnostics/export', name: 'admin_download_diagnostics_export', methods: ['GET'])]
    public function export(Request $request): Response
    {
        $format = strtolower(trim((string) $request->query->get('format', 'json')));
        $filters = [
            'downloadId' => trim((string) $request->query->get('download_id', '')),
            'phase' => trim((string) $request->query->get('phase', '')),
            'status' => trim((string) $request->query->get('status', '')),
            'sharedFileId' => (string) $request->query->get('shared_file_id', ''),
        ];
        $events = $this->repository->search($filters, 1000);

        if ($format === 'csv') {
            $rows = ['id,download_id,phase,status,shared_file_id,bytes_total,bytes_sent,http_status,created_at'];
            foreach ($events as $event) {
                $rows[] = sprintf(
                    '%s,%s,%s,%s,%s,%s,%s,%s,%s',
                    (string) $event->getId(),
                    $event->getDownloadId(),
                    $event->getPhase(),
                    $event->getStatus(),
                    '',
                    '',
                    '',
                    '',
                    $event->getCreatedAt()->format(\DateTimeInterface::ATOM)
                );
            }

            return new Response(implode("\n", $rows), 200, [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="download-diagnostics.csv"',
            ]);
        }

        $payload = array_map(static fn ($event): array => [
            'id' => $event->getId(),
            'downloadId' => $event->getDownloadId(),
            'phase' => $event->getPhase(),
            'status' => $event->getStatus(),
            'createdAt' => $event->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], $events);

        return $this->json($payload, 200, [
            'Content-Disposition' => 'attachment; filename="download-diagnostics.json"',
        ]);
    }

    /**
     * @brief Render timeline detail for one download correlation id.
     * @param string $downloadId Correlation identifier.
     * @return Response
     * @date 2026-07-07
     * @author Stephane H.
     */
    #[Route('/admin/download-diagnostics/{downloadId}', name: 'admin_download_diagnostics_show', methods: ['GET'])]
    public function show(string $downloadId): Response
    {
        $events = $this->repository->findTimeline($downloadId);

        return $this->render('admin/download_diagnostics/show.html.twig', [
            'downloadId' => $downloadId,
            'events' => $events,
        ]);
    }
}
