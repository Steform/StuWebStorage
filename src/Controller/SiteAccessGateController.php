<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Security\CaptchaService;
use App\Service\Site\SiteAccessGateService;
use App\Service\Site\SiteAccessGateTargetResolver;
use App\Service\Site\StorageBotSignalEvaluator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * @brief Public antibot access gate (behavioural check + captcha fallback).
 */
final class SiteAccessGateController extends AbstractController
{
    private const CSRF_GATE = 'storage_access_gate';

    private const PHASE_CHECKING = 'checking';

    private const PHASE_CAPTCHA = 'captcha';

    private const SESSION_DEV_TECHNICAL_SCORE = 'storage_access_gate.dev_technical_score';

    private const SESSION_DEV_THRESHOLD = 'storage_access_gate.dev_threshold';

    /**
     * @param SiteAccessGateService $siteAccessGateService Gate service.
     * @param SiteAccessGateTargetResolver $targetResolver Redirect target resolver.
     * @param StorageBotSignalEvaluator $storageBotSignalEvaluator Bot signal scorer.
     * @param CaptchaService $captchaService Captcha verifier.
     */
    public function __construct(
        private readonly SiteAccessGateService $siteAccessGateService,
        private readonly SiteAccessGateTargetResolver $targetResolver,
        private readonly StorageBotSignalEvaluator $storageBotSignalEvaluator,
        private readonly CaptchaService $captchaService,
    ) {
    }

    /**
     * @brief Render or process antibot access gate with phased UX.
     *
     * @param Request $request Current HTTP request.
     * @return Response
     * @date 2026-06-22
     * @author Stephane H.
     */
    #[Route('/access-gate', name: 'storage_access_gate', methods: ['GET', 'POST'])]
    public function access(Request $request): Response
    {
        $target = $this->targetResolver->resolve(
            $request->query->getString('target', $request->request->getString('target'))
        );

        if (!$this->siteAccessGateService->isGateEnabled()) {
            return $this->redirect($target);
        }

        if ($this->isGranted('ROLE_ADMIN') || $this->siteAccessGateService->isBypassGranted(false)) {
            return $this->redirect($target);
        }

        if ($request->isMethod('POST')) {
            return $this->handleAccessPost($request, $target);
        }

        $accessPhase = $this->resolveAccessPhase($request);

        return $this->render('access_gate/index.html.twig', [
            'target' => $target,
            'accessPhase' => $accessPhase,
            'devAccessScore' => $this->resolveDevAccessScoreForTemplate($request, $accessPhase),
        ]);
    }

    /**
     * @brief Process gate POST: captcha, behavioural score, or redirect to captcha phase.
     *
     * @param Request $request Current HTTP POST request.
     * @param string $target Safe redirect path after grant.
     * @return Response
     * @date 2026-06-22
     * @author Stephane H.
     */
    private function handleAccessPost(Request $request, string $target): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF_GATE, (string) $request->request->get('_csrf_token', ''))) {
            $this->addFlash('warning', 'storage.access_gate.flash.invalid_csrf');

            return $this->redirectToCaptchaPhase($target);
        }

        if ($this->captchaService->verifyCaptcha($request)) {
            $this->captchaService->removeCaptcha();
            $this->siteAccessGateService->grantAccess();

            return $this->redirect($target);
        }

        $scoreResult = $this->storageBotSignalEvaluator->evaluateRequest($request);
        if ($scoreResult['eligibleForCounting'] ?? false) {
            $this->siteAccessGateService->grantAccess();

            return $this->redirect($target);
        }

        $this->addFlash('warning', 'storage.access_gate.flash.denied');
        $this->storeDevAccessScoreInSession($request, $scoreResult);

        return $this->redirectToCaptchaPhase($target, $scoreResult);
    }

    /**
     * @brief Resolve initial UI phase for GET /access-gate.
     *
     * @param Request $request HTTP GET request.
     * @return string checking or captcha.
     * @date 2026-06-22
     * @author Stephane H.
     */
    private function resolveAccessPhase(Request $request): string
    {
        if ($request->query->getString('phase') === self::PHASE_CAPTCHA) {
            return self::PHASE_CAPTCHA;
        }

        foreach ($request->getSession()->getFlashBag()->peek('warning') as $flash) {
            if ($flash === 'storage.access_gate.flash.denied' || $flash === 'storage.access_gate.flash.invalid_csrf') {
                return self::PHASE_CAPTCHA;
            }
        }

        return self::PHASE_CHECKING;
    }

    /**
     * @brief Redirect back to gate with captcha phase query parameter.
     *
     * @param string $target Safe redirect target preserved in query.
     * @param array{technicalScore?: int, threshold?: int}|null $scoreResult Optional evaluator output for dev query params.
     * @return Response
     * @date 2026-06-22
     * @author Stephane H.
     */
    private function redirectToCaptchaPhase(string $target, ?array $scoreResult = null): Response
    {
        $params = [
            'target' => $target,
            'phase' => self::PHASE_CAPTCHA,
        ];

        if ($this->getParameter('kernel.debug') && $scoreResult !== null) {
            $params['dev_score'] = (int) ($scoreResult['technicalScore'] ?? 0);
            $params['dev_threshold'] = (int) ($scoreResult['threshold'] ?? 0);
        }

        return $this->redirectToRoute('storage_access_gate', $params);
    }

    /**
     * @brief Persist last technical score in session for dev-only captcha phase display.
     *
     * @param Request $request HTTP request with session.
     * @param array{technicalScore?: int, threshold?: int} $scoreResult Evaluator output.
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    private function storeDevAccessScoreInSession(Request $request, array $scoreResult): void
    {
        $session = $request->getSession();
        $session->set(self::SESSION_DEV_TECHNICAL_SCORE, (int) ($scoreResult['technicalScore'] ?? 0));
        $session->set(self::SESSION_DEV_THRESHOLD, (int) ($scoreResult['threshold'] ?? 0));
    }

    /**
     * @brief Build dev score payload for Twig when captcha phase is shown in development.
     *
     * @param Request $request HTTP GET request.
     * @param string $accessPhase Resolved UI phase.
     * @return array{score: int, threshold: int}|null
     * @date 2026-06-22
     * @author Stephane H.
     */
    private function resolveDevAccessScoreForTemplate(Request $request, string $accessPhase): ?array
    {
        if (!$this->getParameter('kernel.debug') || $accessPhase !== self::PHASE_CAPTCHA) {
            return null;
        }

        if ($request->query->has('dev_score')) {
            return [
                'score' => $request->query->getInt('dev_score'),
                'threshold' => $request->query->getInt('dev_threshold'),
            ];
        }

        $session = $request->getSession();
        if (!$session->has(self::SESSION_DEV_TECHNICAL_SCORE)) {
            return null;
        }

        return [
            'score' => (int) $session->get(self::SESSION_DEV_TECHNICAL_SCORE, 0),
            'threshold' => (int) $session->get(self::SESSION_DEV_THRESHOLD, 0),
        ];
    }
}
