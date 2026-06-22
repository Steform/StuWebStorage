<?php

declare(strict_types=1);

namespace App\Service\Site;

use App\Service\Security\AntiBotScoringService;
use Symfony\Component\HttpFoundation\Request;

/**
 * @brief Server-side evaluation of client bot-detection signals for site access.
 */
final class StorageBotSignalEvaluator
{
    /**
     * @brief Build storage bot signal evaluator.
     *
     * @param AntiBotScoringService $antiBotScoringService Threshold-aware scoring service.
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function __construct(
        private readonly AntiBotScoringService $antiBotScoringService,
    ) {
    }

    /**
     * @brief Compute technical score from POSTed client signals and apply antibot policy.
     *
     * @param Request $request Gate POST request.
     * @return array{technicalScore: int, threshold: int, eligibleForCounting: bool, reasons: array<int, string>}
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function evaluateRequest(Request $request): array
    {
        $score = $this->computeTechnicalScore($request);

        return $this->antiBotScoringService->evaluate($score);
    }

    /**
     * @brief Heuristic technical score from client interaction signals.
     *
     * @param Request $request Gate POST request.
     * @return int Score between 0 and 100.
     * @date 2026-06-22
     * @author Stephane H.
     */
    private function computeTechnicalScore(Request $request): int
    {
        $pointerMoves = max(0, (int) $request->request->get('pointer_moves', 0));
        $focusEvents = max(0, (int) $request->request->get('focus_events', 0));
        $timeOnPageMs = max(0, (int) $request->request->get('time_on_page_ms', 0));
        $webdriver = filter_var($request->request->get('webdriver', false), FILTER_VALIDATE_BOOL);

        $score = 15;

        if ($pointerMoves >= 3) {
            $score += 25;
        }
        if ($pointerMoves >= 10) {
            $score += 10;
        }
        if ($focusEvents >= 1) {
            $score += 15;
        }
        if ($timeOnPageMs >= 1500) {
            $score += 20;
        }
        if ($timeOnPageMs >= 4000) {
            $score += 10;
        }
        if (!$webdriver) {
            $score += 15;
        }

        $userAgent = strtolower((string) $request->headers->get('User-Agent', ''));
        if ($userAgent !== '' && !str_contains($userAgent, 'headless')) {
            $score += 10;
        }

        return max(0, min(100, $score));
    }
}
