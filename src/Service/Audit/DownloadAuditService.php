<?php

namespace App\Service\Audit;

/**
 * Service DownloadAuditService.
 */
class DownloadAuditService
{
    /**
     * @brief Ignore authorized download audit after audit feature removal.
     * @param string $actorIdentity Actor identity.
     * @param string $ipAddress Request IP.
     * @param string $resourceToken Shared token.
     * @return void
     * @date 2026-05-05
     * @author Stephane H.
     */
    public function create(string $actorIdentity, string $ipAddress, string $resourceToken): void
    {
        // Audit subsystem removed: keep method as explicit no-op for compatibility.
    }

    /**
     * @brief Ignore denied download audit after audit feature removal.
     * @param string $actorIdentity Actor identity.
     * @param string $ipAddress Request IP.
     * @param string $resourceToken Shared token.
     * @return void
     * @date 2026-05-05
     * @author Stephane H.
     */
    public function createDenied(string $actorIdentity, string $ipAddress, string $resourceToken): void
    {
        // Audit subsystem removed: keep method as explicit no-op for compatibility.
    }
}
