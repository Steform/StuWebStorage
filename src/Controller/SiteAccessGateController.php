<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Site\SiteAccessGateService;
use App\Service\Site\SiteAccessGateTargetResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * @brief Public access gate page with bypass note verification.
 */
final class SiteAccessGateController extends AbstractController
{
    private const CSRF_GATE = 'storage_access_gate';

    /**
     * @param SiteAccessGateService $siteAccessGateService Gate service.
     * @param SiteAccessGateTargetResolver $targetResolver Redirect target resolver.
     * @param CsrfTokenManagerInterface $csrfTokenManager CSRF token manager.
     */
    public function __construct(
        private readonly SiteAccessGateService $siteAccessGateService,
        private readonly SiteAccessGateTargetResolver $targetResolver,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    /**
     * @brief Render access gate page or redirect when gate is disabled.
     *
     * @param Request $request Current HTTP request.
     * @return Response
     * @date 2026-06-22
     * @author Stephane H.
     */
    #[Route('/access-gate', name: 'storage_access_gate', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->siteAccessGateService->isGateEnabled()) {
            return $this->redirect($this->targetResolver->resolve($request->query->getString('target')));
        }

        if ($this->isGranted('ROLE_ADMIN') || $this->siteAccessGateService->isBypassGranted(false)) {
            return $this->redirect($this->targetResolver->resolve($request->query->getString('target')));
        }

        $settings = $this->siteAccessGateService->getSettings();

        return $this->render('access_gate/index.html.twig', [
            'target' => $this->targetResolver->resolve($request->query->getString('target')),
            'gateMessage' => $settings->getGateMessage(),
            'csrfGate' => self::CSRF_GATE,
        ]);
    }

    /**
     * @brief Validate bypass note and redirect to requested target.
     *
     * @param Request $request Current HTTP request.
     * @return Response
     * @date 2026-06-22
     * @author Stephane H.
     */
    #[Route('/access-gate', name: 'storage_access_gate_submit', methods: ['POST'])]
    public function submit(Request $request): Response
    {
        $target = $this->targetResolver->resolve($request->request->getString('target'));

        if (!$this->siteAccessGateService->isGateEnabled()) {
            return $this->redirect($target);
        }

        $tokenValue = $request->request->getString('_csrf_token');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_GATE, $tokenValue))) {
            $this->addFlash('danger', 'storage.access_gate.error.csrf_invalid');

            return $this->redirectToRoute('storage_access_gate', ['target' => $target]);
        }

        $submittedNote = $request->request->getString('bypass_note');
        if (!$this->siteAccessGateService->verifyBypassNoteAndGrant($submittedNote)) {
            $this->addFlash('danger', 'storage.access_gate.error.invalid_note');

            return $this->redirectToRoute('storage_access_gate', ['target' => $target]);
        }

        return $this->redirect($target);
    }
}
