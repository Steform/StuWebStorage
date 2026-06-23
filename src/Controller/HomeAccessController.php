<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Home\HomeAccessSessionService;
use App\Service\Home\HomeAccessTargetResolver;
use App\Service\Home\HomeBotSignalEvaluator;
use App\Service\Security\CaptchaService;
use App\Service\Security\StorageBotAttestationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * @brief Homepage public access gate (captcha and client signal verification).
 */
final class HomeAccessController extends AbstractController
{
    private const PHASE_CHECKING = 'checking';

    private const PHASE_CAPTCHA = 'captcha';

    private const SESSION_DEV_TECHNICAL_SCORE = 'storage_home_access.dev_technical_score';

    private const SESSION_DEV_THRESHOLD = 'storage_home_access.dev_threshold';

    /**
     * @brief Render or process homepage access gate with phased UX.
     *
     * @param Request $request HTTP request.
     * @param HomeAccessSessionService $homeAccessSessionService Session access helper.
     * @param HomeAccessTargetResolver $homeAccessTargetResolver Safe redirect resolver.
     * @param HomeBotSignalEvaluator $homeBotSignalEvaluator Bot signal scorer.
     * @param CaptchaService $captchaService Captcha verifier.
     * @param StorageBotAttestationService $storageBotAttestation Signed session attestation helper.
     * @return Response Redirect on grant, or gate HTML with accessPhase for GET.
     * @date 2026-06-23
     * @author Stephane H.
     */
    #[Route('/home/access', name: 'storage_home_access', methods: ['GET', 'POST'])]
    public function access(
        Request $request,
        HomeAccessSessionService $homeAccessSessionService,
        HomeAccessTargetResolver $homeAccessTargetResolver,
        HomeBotSignalEvaluator $homeBotSignalEvaluator,
        CaptchaService $captchaService,
        StorageBotAttestationService $storageBotAttestation,
    ): Response {
        $target = $homeAccessTargetResolver->resolveSafeTarget(
            (string) $request->query->get('target', $request->request->get('target', ''))
        );

        if ($homeAccessSessionService->isBypassGranted() || $homeAccessSessionService->isAccessGranted()) {
            return $this->redirect($target);
        }

        if ($request->isMethod('POST')) {
            return $this->handleAccessPost(
                $request,
                $homeAccessSessionService,
                $homeBotSignalEvaluator,
                $captchaService,
                $storageBotAttestation,
                $target,
            );
        }

        $accessPhase = $this->resolveAccessPhase($request);

        return $this->render('home/access.html.twig', [
            'target' => $target,
            'currentLocale' => $request->getLocale(),
            'accessPhase' => $accessPhase,
            'devAccessScore' => $this->resolveDevAccessScoreForTemplate($request, $accessPhase),
        ]);
    }

    /**
     * @brief Process gate POST: captcha, behavioural score, or redirect to captcha phase.
     *
     * @param Request $request HTTP POST request.
     * @param HomeAccessSessionService $homeAccessSessionService Session access helper.
     * @param HomeBotSignalEvaluator $homeBotSignalEvaluator Bot signal scorer.
     * @param CaptchaService $captchaService Captcha verifier.
     * @param StorageBotAttestationService $storageBotAttestation Signed session attestation helper.
     * @param string $target Safe redirect path after grant.
     * @return Response Redirect to homepage, captcha phase, or access gate with flash.
     * @date 2026-06-23
     * @author Stephane H.
     */
    private function handleAccessPost(
        Request $request,
        HomeAccessSessionService $homeAccessSessionService,
        HomeBotSignalEvaluator $homeBotSignalEvaluator,
        CaptchaService $captchaService,
        StorageBotAttestationService $storageBotAttestation,
        string $target,
    ): Response {
        $csrfToken = (string) $request->request->get('_csrf_token', '');
        if (!$this->isCsrfTokenValid('storage_home_access', $csrfToken)) {
            $this->addFlash('warning', 'storage.home.access.flash.invalid_csrf');

            return $this->redirectToCaptchaPhase($target);
        }

        if ($captchaService->verifyCaptcha($request)) {
            $captchaService->removeCaptcha();
            $storageBotAttestation->issueFromCaptcha();
            $homeAccessSessionService->grantAccess();

            return $this->redirect($target);
        }

        $scoreResult = $homeBotSignalEvaluator->evaluateRequest($request);
        if ($scoreResult['eligibleForCounting'] ?? false) {
            $storageBotAttestation->issueFromSignals((int) ($scoreResult['technicalScore'] ?? 0));
            $homeAccessSessionService->grantAccess();

            return $this->redirect($target);
        }

        $this->addFlash('warning', 'storage.home.access.flash.denied');
        $this->storeDevAccessScoreInSession($request, $scoreResult);

        return $this->redirectToCaptchaPhase($target, $scoreResult);
    }

    /**
     * @brief Resolve initial UI phase for GET /home/access.
     *
     * @param Request $request HTTP GET request.
     * @return string checking or captcha.
     * @date 2026-06-23
     * @author Stephane H.
     */
    private function resolveAccessPhase(Request $request): string
    {
        if ($request->query->getString('phase') === self::PHASE_CAPTCHA) {
            return self::PHASE_CAPTCHA;
        }

        foreach ($request->getSession()->getFlashBag()->peek('warning') as $flash) {
            if ($flash === 'storage.home.access.flash.denied' || $flash === 'storage.home.access.flash.invalid_csrf') {
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
     * @return Response 302 to storage_home_access with phase=captcha.
     * @date 2026-06-23
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

        return $this->redirectToRoute('storage_home_access', $params);
    }

    /**
     * @brief Persist last technical score in session for dev-only captcha phase display.
     *
     * @param Request $request HTTP request with session.
     * @param array{technicalScore?: int, threshold?: int} $scoreResult Evaluator output.
     * @return void
     * @date 2026-06-23
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
     * @param string $accessPhase Resolved UI phase (checking or captcha).
     * @return array{score: int, threshold: int}|null Null outside dev or when no score stored.
     * @date 2026-06-23
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
