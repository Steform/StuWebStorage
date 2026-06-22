<?php

declare(strict_types=1);

namespace App\Service\Security;

use App\Service\Site\SiteAccessGateService;

/**
 * @brief Evaluate client antibot scores against the configured threshold.
 */
final class AntiBotScoringService
{
    /**
     * @brief Build anti-bot scoring service.
     *
     * @param SiteAccessGateService $siteAccessGateService Gate settings provider.
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function __construct(
        private readonly SiteAccessGateService $siteAccessGateService,
    ) {
    }

    /**
     * @brief Validate anti-bot score against current threshold.
     *
     * @param int $score Evaluated score.
     * @return bool
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function isAllowed(int $score): bool
    {
        return (bool) $this->evaluate($score)['eligibleForCounting'];
    }

    /**
     * @brief Evaluate anti-bot scoring policy.
     *
     * @param int $providedScore Raw technical score input.
     * @return array{technicalScore: int, threshold: int, eligibleForCounting: bool, reasons: array<int, string>}
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function evaluate(int $providedScore): array
    {
        $threshold = $this->getThreshold();
        $technicalScore = max(0, min(100, $providedScore));
        $eligibleForCounting = $technicalScore >= $threshold;
        $reasons = [];

        if ($providedScore < 0 || $providedScore > 100) {
            $reasons[] = 'score.normalized';
        }
        if ($eligibleForCounting) {
            $reasons[] = 'score.threshold.passed';
        } else {
            $reasons[] = 'score.threshold.failed';
        }

        return [
            'technicalScore' => $technicalScore,
            'threshold' => $threshold,
            'eligibleForCounting' => $eligibleForCounting,
            'reasons' => $reasons,
        ];
    }

    /**
     * @brief Get current anti-bot threshold.
     *
     * @param void No input parameter.
     * @return int
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function getThreshold(): int
    {
        return $this->siteAccessGateService->getAntibotThreshold();
    }
}
