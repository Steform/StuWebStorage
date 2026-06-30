<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Class UserDeviceUiPreference.
 */
#[ORM\Entity(repositoryClass: 'App\Repository\UserDeviceUiPreferenceRepository')]
#[ORM\Table(name: 'user_device_ui_preference')]
#[ORM\UniqueConstraint(name: 'uniq_user_device_ui_preference_user_device', columns: ['user_id', 'device_id'])]
#[ORM\Index(name: 'idx_user_device_ui_preference_user', columns: ['user_id'])]
class UserDeviceUiPreference
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'device_id', length: 128)]
    private string $deviceId;

    #[ORM\Column(name: 'files_view_mode', length: 8, options: ['default' => 'list'])]
    private string $filesViewMode = 'list';

    #[ORM\Column(name: 'files_view_mode_mobile', length: 8, options: ['default' => 'grid'])]
    private string $filesViewModeMobile = 'grid';

    #[ORM\Column(name: 'files_scope', length: 8, options: ['default' => 'both'])]
    private string $filesScope = 'both';

    #[ORM\Column(name: 'files_sort_field', length: 32, options: ['default' => 'name'])]
    private string $filesSortField = 'name';

    #[ORM\Column(name: 'files_sort_direction', length: 4, options: ['default' => 'asc'])]
    private string $filesSortDirection = 'asc';

    /**
     * @var array<string, mixed>|null
     */
    #[ORM\Column(name: 'cloud_visibility_state', type: 'json', nullable: true)]
    private ?array $cloudVisibilityState = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    /**
     * @brief Build preference row for one user and one device.
     * @param User $user Target user.
     * @param string $deviceId Opaque device identifier.
     * @return void
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function __construct(User $user, string $deviceId)
    {
        $this->user = $user;
        $this->deviceId = $deviceId;
        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * @brief Get primary key.
     * @param void No input parameter.
     * @return int|null
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @brief Get linked user.
     * @param void No input parameter.
     * @return User
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function getUser(): User
    {
        return $this->user;
    }

    /**
     * @brief Get opaque device identifier.
     * @param void No input parameter.
     * @return string
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function getDeviceId(): string
    {
        return $this->deviceId;
    }

    /**
     * @brief Set opaque device identifier.
     * @param string $deviceId Opaque device identifier.
     * @return void
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function setDeviceId(string $deviceId): void
    {
        $this->deviceId = $deviceId;
    }

    /**
     * @brief Get files layout mode preference.
     * @param void No input parameter.
     * @return string
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function getFilesViewMode(): string
    {
        return $this->filesViewMode;
    }

    /**
     * @brief Set files layout mode preference.
     * @param string $filesViewMode Layout mode (`list` or `grid`).
     * @return void
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function setFilesViewMode(string $filesViewMode): void
    {
        $this->filesViewMode = $filesViewMode;
    }

    /**
     * @brief Get files layout mode preference for mobile viewports.
     * @param void No input parameter.
     * @return string
     * @date 2026-06-30
     * @author Stephane H.
     */
    public function getFilesViewModeMobile(): string
    {
        return $this->filesViewModeMobile;
    }

    /**
     * @brief Set files layout mode preference for mobile viewports.
     * @param string $filesViewModeMobile Layout mode (`list` or `grid`).
     * @return void
     * @date 2026-06-30
     * @author Stephane H.
     */
    public function setFilesViewModeMobile(string $filesViewModeMobile): void
    {
        $this->filesViewModeMobile = $filesViewModeMobile;
    }

    /**
     * @brief Get files listing scope preference.
     * @param void No input parameter.
     * @return string
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function getFilesScope(): string
    {
        return $this->filesScope;
    }

    /**
     * @brief Set files listing scope preference.
     * @param string $filesScope Scope token (`owned`, `shared`, `both`).
     * @return void
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function setFilesScope(string $filesScope): void
    {
        $this->filesScope = $filesScope;
    }

    /**
     * @brief Get files listing sort field preference.
     * @param void No input parameter.
     * @return string
     * @date 2026-06-25
     * @author Stephane H.
     */
    public function getFilesSortField(): string
    {
        return $this->filesSortField;
    }

    /**
     * @brief Set files listing sort field preference.
     * @param string $filesSortField Whitelisted sort field key.
     * @return void
     * @date 2026-06-25
     * @author Stephane H.
     */
    public function setFilesSortField(string $filesSortField): void
    {
        $this->filesSortField = $filesSortField;
    }

    /**
     * @brief Get files listing sort direction preference.
     * @param void No input parameter.
     * @return string
     * @date 2026-06-25
     * @author Stephane H.
     */
    public function getFilesSortDirection(): string
    {
        return $this->filesSortDirection;
    }

    /**
     * @brief Set files listing sort direction preference.
     * @param string $filesSortDirection Sort direction (`asc` or `desc`).
     * @return void
     * @date 2026-06-25
     * @author Stephane H.
     */
    public function setFilesSortDirection(string $filesSortDirection): void
    {
        $this->filesSortDirection = $filesSortDirection;
    }

    /**
     * @brief Get optional cloud visibility state payload.
     * @param void No input parameter.
     * @return array<string, mixed>|null
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function getCloudVisibilityState(): ?array
    {
        return $this->cloudVisibilityState;
    }

    /**
     * @brief Set optional cloud visibility state payload.
     * @param array<string, mixed>|null $cloudVisibilityState Canonical visibility payload.
     * @return void
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function setCloudVisibilityState(?array $cloudVisibilityState): void
    {
        $this->cloudVisibilityState = $cloudVisibilityState;
    }

    /**
     * @brief Get last update instant.
     * @param void No input parameter.
     * @return DateTimeImmutable
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @brief Refresh last update instant to now.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
