<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Home\StorageHomeStatsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * @brief Cloud storage landing page controller.
 */
final class HomeController extends AbstractController
{
    /**
     * @param StorageHomeStatsService $storageHomeStatsService Home stats aggregator.
     */
    public function __construct(
        private readonly StorageHomeStatsService $storageHomeStatsService,
    ) {
    }

    /**
     * @brief Render StuWebStorage cloud landing page.
     *
     * @return Response
     * @date 2026-06-22
     * @author Stephane H.
     */
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(): Response
    {
        $stats = null;
        $user = $this->getUser();
        if ($user instanceof User && $this->isGranted('ROLE_SHARE')) {
            $stats = $this->storageHomeStatsService->buildForOwner($user);
        }

        return $this->render('home/index.html.twig', [
            'stats' => $stats,
        ]);
    }
}
