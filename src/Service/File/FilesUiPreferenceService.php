<?php

namespace App\Service\File;

use App\Entity\User;
use App\Entity\UserDeviceUiPreference;
use App\Repository\UserDeviceUiPreferenceRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service FilesUiPreferenceService.
 */
class FilesUiPreferenceService
{
    /**
     * @var array<int, string>
     */
    private const ALLOWED_VIEW_MODES = ['list', 'grid'];

    /**
     * @var array<int, string>
     */
    private const ALLOWED_SCOPES = ['both', 'owned', 'shared'];

    /**
     * @var array<int, string>
     */
    private const ALLOWED_SORT_FIELDS = ['name', 'size', 'uploaded', 'modified', 'ext'];

    /**
     * @var array<int, string>
     */
    private const ALLOWED_SORT_DIRECTIONS = ['asc', 'desc'];

    private const DEFAULT_SORT_FIELD = 'name';

    private const DEFAULT_SORT_DIRECTION = 'asc';

    /**
     * @var array<int, string>
     */
    private const ALLOWED_COLUMN_KEYS = ['type', 'size', 'share_public', 'share_friends', 'uploaded', 'modified'];

    /**
     * @var array<string, bool>
     */
    private const COLUMN_DEFAULTS = [
        'type' => true,
        'size' => true,
        'share_public' => true,
        'share_friends' => true,
        'uploaded' => false,
        'modified' => false,
    ];

    /**
     * @var array<string, bool>
     */
    private const SECTION_DEFAULTS = [
        'my_files' => true,
        'shared_for_me' => true,
    ];

    /**
     * @brief Build files UI preference service.
     * @param UserDeviceUiPreferenceRepository $repository Preference repository.
     * @param EntityManagerInterface $entityManager Doctrine entity manager.
     * @return void
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function __construct(
        private readonly UserDeviceUiPreferenceRepository $repository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @brief Return normalized files UI preferences for one user and one device.
     * @param User $user Target user.
     * @param string $deviceId Opaque device identifier.
     * @return array{preferences: array<string, mixed>, source: string}
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function getFilesPreferences(User $user, string $deviceId): array
    {
        $normalizedDeviceId = $this->normalizeDeviceId($deviceId);
        if ($normalizedDeviceId === '') {
            return [
                'preferences' => $this->getDefaultPreferences(),
                'source' => 'defaults',
            ];
        }

        $row = $this->repository->findOneByUserAndDeviceId($user, $normalizedDeviceId);
        if (!$row instanceof UserDeviceUiPreference) {
            return [
                'preferences' => $this->getDefaultPreferences(),
                'source' => 'defaults',
            ];
        }

        return [
            'preferences' => $this->hydratePreferencePayload($row),
            'source' => 'backend',
        ];
    }

    /**
     * @brief Validate and persist files UI preferences for one user and one device.
     * @param User $user Target user.
     * @param string $deviceId Opaque device identifier.
     * @param array<string, mixed> $payload Raw incoming payload.
     * @return array{preferences: array<string, mixed>, persisted: bool}
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function saveFilesPreferences(User $user, string $deviceId, array $payload): array
    {
        $normalizedDeviceId = $this->normalizeDeviceId($deviceId);
        if ($normalizedDeviceId === '') {
            return [
                'preferences' => $this->normalizeIncomingPayload($payload),
                'persisted' => false,
            ];
        }

        $row = $this->repository->findOneByUserAndDeviceId($user, $normalizedDeviceId);
        if (!$row instanceof UserDeviceUiPreference) {
            $row = new UserDeviceUiPreference($user, $normalizedDeviceId);
            $this->entityManager->persist($row);
        }

        $normalized = $this->normalizeIncomingPayload($payload);
        $row->setFilesViewMode((string) $normalized['filesViewMode']);
        $row->setFilesScope((string) $normalized['filesScope']);
        $row->setFilesSortField((string) $normalized['filesSortField']);
        $row->setFilesSortDirection((string) $normalized['filesSortDirection']);
        $cloudState = $normalized['cloudVisibilityState'];
        $row->setCloudVisibilityState(is_array($cloudState) ? $cloudState : null);
        $row->touchUpdatedAt();
        $this->repository->syncSortPreferenceForUser(
            $user,
            (string) $normalized['filesSortField'],
            (string) $normalized['filesSortDirection'],
        );
        $this->entityManager->flush();

        return [
            'preferences' => $normalized,
            'persisted' => true,
        ];
    }

    /**
     * @brief Return default preference payload.
     * @param void No input parameter.
     * @return array{filesViewMode: string, filesScope: string, filesSortField: string, filesSortDirection: string, cloudVisibilityState: array<string, mixed>}
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function getDefaultPreferences(): array
    {
        return [
            'filesViewMode' => 'list',
            'filesScope' => 'both',
            'filesSortField' => self::DEFAULT_SORT_FIELD,
            'filesSortDirection' => self::DEFAULT_SORT_DIRECTION,
            'cloudVisibilityState' => [
                'columns' => self::COLUMN_DEFAULTS,
                'sections' => self::SECTION_DEFAULTS,
            ],
        ];
    }

    /**
     * @brief Resolve listing sort preference for one user (cross-device, default name ascending).
     * @param User $user Target user.
     * @return array{field: string, direction: string}
     * @date 2026-06-25
     * @author Stephane H.
     */
    public function resolveListingSortPreference(User $user): array
    {
        $latest = $this->repository->findLatestSortPreferenceByUser($user);
        if ($latest === null) {
            return [
                'field' => self::DEFAULT_SORT_FIELD,
                'direction' => self::DEFAULT_SORT_DIRECTION,
            ];
        }

        return [
            'field' => $this->normalizeSortField((string) $latest['field']),
            'direction' => $this->normalizeSortDirection((string) $latest['direction']),
        ];
    }

    /**
     * @brief Normalize an opaque device identifier.
     * @param string $deviceId Raw device identifier.
     * @return string
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function normalizeDeviceId(string $deviceId): string
    {
        $normalized = trim($deviceId);
        if ($normalized === '') {
            return '';
        }
        if (!preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $normalized)) {
            return '';
        }

        return $normalized;
    }

    /**
     * @brief Normalize incoming payload and clamp unsupported values to defaults.
     * @param array<string, mixed> $payload Raw request payload.
     * @return array{filesViewMode: string, filesScope: string, filesSortField: string, filesSortDirection: string, cloudVisibilityState: array<string, mixed>}
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function normalizeIncomingPayload(array $payload): array
    {
        $defaults = $this->getDefaultPreferences();
        $view = (string) ($payload['filesViewMode'] ?? $defaults['filesViewMode']);
        if (!in_array($view, self::ALLOWED_VIEW_MODES, true)) {
            $view = $defaults['filesViewMode'];
        }

        $scope = (string) ($payload['filesScope'] ?? $defaults['filesScope']);
        if (!in_array($scope, self::ALLOWED_SCOPES, true)) {
            $scope = $defaults['filesScope'];
        }

        $sortField = $this->normalizeSortField((string) ($payload['filesSortField'] ?? $defaults['filesSortField']));
        $sortDirection = $this->normalizeSortDirection((string) ($payload['filesSortDirection'] ?? $defaults['filesSortDirection']));

        $cloudState = $payload['cloudVisibilityState'] ?? [];
        if (!is_array($cloudState)) {
            $cloudState = [];
        }

        return [
            'filesViewMode' => $view,
            'filesScope' => $scope,
            'filesSortField' => $sortField,
            'filesSortDirection' => $sortDirection,
            'cloudVisibilityState' => [
                'columns' => $this->normalizeColumns($cloudState['columns'] ?? null),
                'sections' => $this->normalizeSections($cloudState['sections'] ?? null),
            ],
        ];
    }

    /**
     * @brief Hydrate normalized payload from persisted entity.
     * @param UserDeviceUiPreference $row Persisted row.
     * @return array{filesViewMode: string, filesScope: string, filesSortField: string, filesSortDirection: string, cloudVisibilityState: array<string, mixed>}
     * @date 2026-05-03
     * @author Stephane H.
     */
    private function hydratePreferencePayload(UserDeviceUiPreference $row): array
    {
        return $this->normalizeIncomingPayload([
            'filesViewMode' => $row->getFilesViewMode(),
            'filesScope' => $row->getFilesScope(),
            'filesSortField' => $row->getFilesSortField(),
            'filesSortDirection' => $row->getFilesSortDirection(),
            'cloudVisibilityState' => $row->getCloudVisibilityState(),
        ]);
    }

    /**
     * @brief Normalize one sort field token.
     * @param string $value Raw sort field token.
     * @return string
     * @date 2026-06-25
     * @author Stephane H.
     */
    private function normalizeSortField(string $value): string
    {
        $normalized = strtolower(trim($value));
        if ($normalized === 'type') {
            $normalized = 'ext';
        }
        if (!in_array($normalized, self::ALLOWED_SORT_FIELDS, true)) {
            return self::DEFAULT_SORT_FIELD;
        }

        return $normalized;
    }

    /**
     * @brief Normalize one sort direction token.
     * @param string $value Raw sort direction token.
     * @return string
     * @date 2026-06-25
     * @author Stephane H.
     */
    private function normalizeSortDirection(string $value): string
    {
        $normalized = strtolower(trim($value));
        if (!in_array($normalized, self::ALLOWED_SORT_DIRECTIONS, true)) {
            return self::DEFAULT_SORT_DIRECTION;
        }

        return $normalized;
    }

    /**
     * @brief Normalize columns visibility map.
     * @param mixed $value Raw columns payload.
     * @return array<string, bool>
     * @date 2026-05-03
     * @author Stephane H.
     */
    private function normalizeColumns(mixed $value): array
    {
        $normalized = self::COLUMN_DEFAULTS;
        if (!is_array($value)) {
            return $normalized;
        }

        foreach (self::ALLOWED_COLUMN_KEYS as $key) {
            if (array_key_exists($key, $value)) {
                $normalized[$key] = (bool) $value[$key];
            }
        }

        return $normalized;
    }

    /**
     * @brief Normalize sections expanded map.
     * @param mixed $value Raw sections payload.
     * @return array<string, bool>
     * @date 2026-05-03
     * @author Stephane H.
     */
    private function normalizeSections(mixed $value): array
    {
        $normalized = self::SECTION_DEFAULTS;
        if (!is_array($value)) {
            return $normalized;
        }

        foreach (array_keys(self::SECTION_DEFAULTS) as $key) {
            if (array_key_exists($key, $value)) {
                $normalized[$key] = (bool) $value[$key];
            }
        }

        return $normalized;
    }
}
