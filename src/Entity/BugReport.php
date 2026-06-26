<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Class BugReport.
 */
#[ORM\Entity(repositoryClass: 'App\Repository\BugReportRepository')]
#[ORM\Table(name: 'bug_report', indexes: [
    new ORM\Index(name: 'idx_bug_report_status', columns: ['status']),
    new ORM\Index(name: 'idx_bug_report_severity', columns: ['severity']),
    new ORM\Index(name: 'idx_bug_report_route_name', columns: ['route_name']),
    new ORM\Index(name: 'idx_bug_report_created_at', columns: ['created_at']),
    new ORM\Index(name: 'idx_bug_report_archived_at', columns: ['archived_at']),
])]
class BugReport
{
    public const STATUS_NEW = 'new';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_REOPENED = 'reopened';

    public const SEVERITY_MINOR = 'minor';
    public const SEVERITY_MAJOR = 'major';
    public const SEVERITY_CRITICAL = 'critical';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'reporter_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $reporterUser;

    #[ORM\Column(length: 32)]
    private string $status = self::STATUS_NEW;

    #[ORM\Column(length: 32)]
    private string $severity = self::SEVERITY_MINOR;

    #[ORM\Column(type: 'text')]
    private string $actionDescription;

    #[ORM\Column(type: 'text')]
    private string $observedResult;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $expectedResult = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $routeName = null;

    #[ORM\Column(length: 2048)]
    private string $path;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $queryString = null;

    #[ORM\Column(length: 10)]
    private string $locale;

    #[ORM\Column(length: 10)]
    private string $theme;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $viewportWidth = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $viewportHeight = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $referrer = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $correlationId = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $appVersion = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $actionTimelineJson = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $screenshotPath = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $screenshotMime = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $screenshotByteSize = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $resolvedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'resolved_by_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $resolvedByUser = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $archivedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'archived_by_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $archivedByUser = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $archiveReason = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    /**
     * @brief Build bug report aggregate.
     * @param User $reporterUser Reporter user.
     * @param string $actionDescription User action description.
     * @param string $observedResult User observed result.
     * @param string|null $expectedResult User expected result.
     * @param string|null $routeName Route name.
     * @param string $path Request path.
     * @param string|null $queryString Request query string.
     * @param string $locale Active locale.
     * @param string $theme Active theme.
     * @param string|null $userAgent Browser user agent.
     * @param int|null $viewportWidth Front viewport width.
     * @param int|null $viewportHeight Front viewport height.
     * @param string|null $referrer HTTP referrer.
     * @param string|null $correlationId Request correlation identifier.
     * @param string|null $appVersion Application version.
     * @param array<int, mixed>|null $actionTimelineJson Front action timeline.
     * @return void
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function __construct(
        User $reporterUser,
        string $actionDescription,
        string $observedResult,
        ?string $expectedResult,
        ?string $routeName,
        string $path,
        ?string $queryString,
        string $locale,
        string $theme,
        ?string $userAgent,
        ?int $viewportWidth,
        ?int $viewportHeight,
        ?string $referrer,
        ?string $correlationId,
        ?string $appVersion,
        ?array $actionTimelineJson
    ) {
        $now = new DateTimeImmutable();
        $this->reporterUser = $reporterUser;
        $this->actionDescription = $actionDescription;
        $this->observedResult = $observedResult;
        $this->expectedResult = $expectedResult;
        $this->routeName = $routeName;
        $this->path = $path;
        $this->queryString = $queryString;
        $this->locale = $locale;
        $this->theme = $theme;
        $this->userAgent = $userAgent;
        $this->viewportWidth = $viewportWidth;
        $this->viewportHeight = $viewportHeight;
        $this->referrer = $referrer;
        $this->correlationId = $correlationId;
        $this->appVersion = $appVersion;
        $this->actionTimelineJson = $actionTimelineJson;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @brief Get bug report identifier.
     * @param void No input parameter.
     * @return int|null
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @brief Get reporter user.
     * @param void No input parameter.
     * @return User
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function getReporterUser(): User
    {
        return $this->reporterUser;
    }

    /**
     * @brief Get ticket status.
     * @param void No input parameter.
     * @return string
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * @brief Get ticket severity.
     * @param void No input parameter.
     * @return string
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function getSeverity(): string
    {
        return $this->severity;
    }

    /**
     * @brief Update ticket severity.
     * @param string $severity Ticket severity.
     * @return void
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function setSeverity(string $severity): void
    {
        if (!\in_array($severity, [self::SEVERITY_MINOR, self::SEVERITY_MAJOR, self::SEVERITY_CRITICAL], true)) {
            return;
        }

        $this->severity = $severity;
        $this->touch();
    }

    /**
     * @brief Get user action description.
     * @param void No input parameter.
     * @return string
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function getActionDescription(): string
    {
        return $this->actionDescription;
    }

    /**
     * @brief Get observed bug result.
     * @param void No input parameter.
     * @return string
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function getObservedResult(): string
    {
        return $this->observedResult;
    }

    /**
     * @brief Get expected result.
     * @param void No input parameter.
     * @return string|null
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function getExpectedResult(): ?string
    {
        return $this->expectedResult;
    }

    /**
     * @brief Get route name context.
     * @param void No input parameter.
     * @return string|null
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function getRouteName(): ?string
    {
        return $this->routeName;
    }

    /**
     * @brief Get request query string context.
     * @param void No input parameter.
     * @return string|null
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function getQueryString(): ?string
    {
        return $this->queryString;
    }

    /**
     * @brief Get path context.
     * @param void No input parameter.
     * @return string
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * @brief Get locale context.
     * @param void No input parameter.
     * @return string
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function getLocale(): string
    {
        return $this->locale;
    }

    /**
     * @brief Get theme context.
     * @param void No input parameter.
     * @return string
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function getTheme(): string
    {
        return $this->theme;
    }

    /**
     * @brief Get action timeline payload.
     * @param void No input parameter.
     * @return array<int, mixed>|null
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function getActionTimelineJson(): ?array
    {
        return $this->actionTimelineJson;
    }

    /**
     * @brief Get relative screenshot storage path.
     * @param void No input parameter.
     * @return string|null
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function getScreenshotPath(): ?string
    {
        return $this->screenshotPath;
    }

    /**
     * @brief Get screenshot MIME type.
     * @param void No input parameter.
     * @return string|null
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function getScreenshotMime(): ?string
    {
        return $this->screenshotMime;
    }

    /**
     * @brief Get screenshot byte size.
     * @param void No input parameter.
     * @return int|null
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function getScreenshotByteSize(): ?int
    {
        return $this->screenshotByteSize;
    }

    /**
     * @brief Attach screenshot metadata after file persistence.
     * @param string $screenshotPath Relative screenshot path.
     * @param string $screenshotMime Screenshot MIME type.
     * @param int $screenshotByteSize Screenshot byte size.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function attachScreenshot(string $screenshotPath, string $screenshotMime, int $screenshotByteSize): void
    {
        $this->screenshotPath = $screenshotPath;
        $this->screenshotMime = $screenshotMime;
        $this->screenshotByteSize = $screenshotByteSize;
        $this->touch();
    }

    /**
     * @brief Get request user agent context.
     * @param void No input parameter.
     * @return string|null
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    /**
     * @brief Get viewport width context.
     * @param void No input parameter.
     * @return int|null
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function getViewportWidth(): ?int
    {
        return $this->viewportWidth;
    }

    /**
     * @brief Get viewport height context.
     * @param void No input parameter.
     * @return int|null
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function getViewportHeight(): ?int
    {
        return $this->viewportHeight;
    }

    /**
     * @brief Get request referrer context.
     * @param void No input parameter.
     * @return string|null
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function getReferrer(): ?string
    {
        return $this->referrer;
    }

    /**
     * @brief Get request correlation identifier.
     * @param void No input parameter.
     * @return string|null
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function getCorrelationId(): ?string
    {
        return $this->correlationId;
    }

    /**
     * @brief Get application version context.
     * @param void No input parameter.
     * @return string|null
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function getAppVersion(): ?string
    {
        return $this->appVersion;
    }

    /**
     * @brief Get archive datetime.
     * @param void No input parameter.
     * @return DateTimeImmutable|null
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function getArchivedAt(): ?DateTimeImmutable
    {
        return $this->archivedAt;
    }

    /**
     * @brief Get archive actor user.
     * @param void No input parameter.
     * @return User|null
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function getArchivedByUser(): ?User
    {
        return $this->archivedByUser;
    }

    /**
     * @brief Get archive reason context.
     * @param void No input parameter.
     * @return string|null
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function getArchiveReason(): ?string
    {
        return $this->archiveReason;
    }

    /**
     * @brief Get update datetime.
     * @param void No input parameter.
     * @return DateTimeImmutable
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @brief Get creation datetime.
     * @param void No input parameter.
     * @return DateTimeImmutable
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @brief Mark ticket as in progress.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function markInProgress(): void
    {
        $this->status = self::STATUS_IN_PROGRESS;
        $this->touch();
    }

    /**
     * @brief Mark ticket as resolved.
     * @param User $resolverUser Resolver user.
     * @param DateTimeImmutable|null $resolvedAt Resolver datetime override.
     * @return void
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function markResolved(User $resolverUser, ?DateTimeImmutable $resolvedAt = null): void
    {
        $this->status = self::STATUS_RESOLVED;
        $this->resolvedByUser = $resolverUser;
        $this->resolvedAt = $resolvedAt ?? new DateTimeImmutable();
        $this->touch();
    }

    /**
     * @brief Reopen resolved ticket.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function reopen(): void
    {
        $this->status = self::STATUS_REOPENED;
        $this->resolvedByUser = null;
        $this->resolvedAt = null;
        $this->touch();
    }

    /**
     * @brief Archive ticket for history tracking.
     * @param User $archiverUser Archiver user.
     * @param string|null $archiveReason Archive reason.
     * @param DateTimeImmutable|null $archivedAt Archive datetime override.
     * @return void
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function archive(User $archiverUser, ?string $archiveReason, ?DateTimeImmutable $archivedAt = null): void
    {
        $this->archivedByUser = $archiverUser;
        $this->archiveReason = $archiveReason;
        $this->archivedAt = $archivedAt ?? new DateTimeImmutable();
        $this->touch();
    }

    /**
     * @brief Remove archive metadata to reactivate ticket visibility.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function unarchive(): void
    {
        $this->archivedByUser = null;
        $this->archiveReason = null;
        $this->archivedAt = null;
        $this->touch();
    }

    /**
     * @brief Get resolved datetime.
     * @param void No input parameter.
     * @return DateTimeImmutable|null
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function getResolvedAt(): ?DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    /**
     * @brief Get resolver user.
     * @param void No input parameter.
     * @return User|null
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function getResolvedByUser(): ?User
    {
        return $this->resolvedByUser;
    }

    /**
     * @brief Check whether ticket is archived.
     * @param void No input parameter.
     * @return bool
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function isArchived(): bool
    {
        return $this->archivedAt !== null;
    }

    /**
     * @brief Touch update datetime.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-06
     * @author Stephane H.
     */
    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
