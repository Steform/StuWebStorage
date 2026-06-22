<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * @brief Persist and restore last admin godview listing scope in session (GET /admin/files).
 * @date 2026-05-04
 * @author Stephane H.
 */
final class AdminGodviewSessionStateService
{
    public const SESSION_KEY = 'admin_godview.last_state';

    /**
     * @brief Build service with user lookup for remembered subject validation.
     * @param UserRepository $userRepository User persistence.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function __construct(
        private readonly UserRepository $userRepository,
    ) {
    }

    /**
     * @brief Store canonical godview query slice after a successful full admin listing render.
     * @param SessionInterface $session HTTP session.
     * @param string $adminViewScope Resolved admin_view_scope (owner|all).
     * @param string $canonicalViewScope Resolved view_scope (me|user|all).
     * @param int|null $subjectUserId subject_user query id or null when omitted.
     * @param int|null $owner owner query id or null when omitted.
     * @param int|null $folderId Current owned folder id or null at root.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function rememberState(
        SessionInterface $session,
        string $adminViewScope,
        string $canonicalViewScope,
        ?int $subjectUserId,
        ?int $owner,
        ?int $folderId,
    ): void {
        $avs = strtolower(trim($adminViewScope));
        if (!\in_array($avs, ['owner', 'all'], true)) {
            $avs = 'owner';
        }
        $vs = strtolower(trim($canonicalViewScope));
        if (!\in_array($vs, ['me', 'user', 'all'], true)) {
            $vs = 'me';
        }
        if ($avs === 'all') {
            $vs = 'all';
        }

        $payload = [
            'admin_view_scope' => $avs,
            'view_scope' => $vs,
            'subject_user' => $subjectUserId !== null && $subjectUserId > 0 ? $subjectUserId : null,
            'owner' => $owner !== null && $owner > 0 ? $owner : null,
            'folder' => $folderId !== null && $folderId > 0 ? $folderId : null,
        ];
        $session->set(self::SESSION_KEY, $payload);
    }

    /**
     * @brief Read last remembered state or null when missing or structurally invalid.
     * @param SessionInterface $session HTTP session.
     * @return array{admin_view_scope: string, view_scope: string, subject_user: int|null, owner: int|null, folder: int|null}|null
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function loadRememberedState(SessionInterface $session): ?array
    {
        $raw = $session->get(self::SESSION_KEY);
        if (!\is_array($raw)) {
            return null;
        }

        $avs = strtolower(trim((string) ($raw['admin_view_scope'] ?? '')));
        if (!\in_array($avs, ['owner', 'all'], true)) {
            return null;
        }
        $vs = strtolower(trim((string) ($raw['view_scope'] ?? '')));
        if (!\in_array($vs, ['me', 'user', 'all'], true)) {
            return null;
        }
        if ($avs === 'all' && $vs !== 'all') {
            return null;
        }

        $subject = isset($raw['subject_user']) ? (int) $raw['subject_user'] : 0;
        $owner = isset($raw['owner']) ? (int) $raw['owner'] : 0;
        $folder = isset($raw['folder']) ? (int) $raw['folder'] : 0;

        return [
            'admin_view_scope' => $avs,
            'view_scope' => $vs,
            'subject_user' => $subject > 0 ? $subject : null,
            'owner' => $owner > 0 ? $owner : null,
            'folder' => $folder > 0 ? $folder : null,
        ];
    }

    /**
     * @brief Drop remembered godview state (optional reset flows).
     * @param SessionInterface $session HTTP session.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function forgetState(SessionInterface $session): void
    {
        $session->remove(self::SESSION_KEY);
    }

    /**
     * @brief Whether remembered state is safe to redirect to (active share user when drilling a subject).
     * @param array{admin_view_scope: string, view_scope: string, subject_user: int|null, owner: int|null, folder: int|null} $state Normalized state from loadRememberedState.
     * @return bool True when redirect is permitted.
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function isValidRememberedState(array $state): bool
    {
        if ($state['admin_view_scope'] === 'all') {
            return $state['view_scope'] === 'all';
        }

        if ($state['view_scope'] !== 'user') {
            return true;
        }

        $subjectId = $state['subject_user'];
        if ($subjectId === null || $subjectId < 1) {
            return false;
        }

        $user = $this->userRepository->find($subjectId);
        if (!$user instanceof User) {
            return false;
        }

        if (!$user->isActive()) {
            return false;
        }

        $roles = $user->getRoles();

        return \in_array('ROLE_SHARE', $roles, true) || \in_array('ROLE_ADMIN', $roles, true);
    }

    /**
     * @brief Build filtered query parameters for admin_files_index from normalized remembered state.
     * @param array{admin_view_scope: string, view_scope: string, subject_user: int|null, owner: int|null, folder: int|null} $state Normalized state.
     * @return array<string, mixed> Query map before FilesController::filterListingRouteParams.
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function buildRedirectQueryFromState(array $state): array
    {
        $params = [
            'admin_context' => '1',
            'admin_view_scope' => $state['admin_view_scope'],
            'view_scope' => $state['view_scope'],
        ];
        if ($state['subject_user'] !== null) {
            $params['subject_user'] = $state['subject_user'];
        }
        if ($state['owner'] !== null) {
            $params['owner'] = (string) $state['owner'];
        }
        if ($state['folder'] !== null) {
            $params['folder'] = $state['folder'];
        }

        return $params;
    }
}
