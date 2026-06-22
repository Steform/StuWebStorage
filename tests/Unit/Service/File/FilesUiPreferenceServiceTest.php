<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Repository\UserDeviceUiPreferenceRepository;
use App\Service\File\FilesUiPreferenceService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * @brief Unit tests for files UI preference normalization and guards.
 * @author Stephane H.
 * @date 2026-05-03
 */
final class FilesUiPreferenceServiceTest extends TestCase
{
    private FilesUiPreferenceService $service;

    /**
     * @return void
     * @date 2026-05-03
     * @author Stephane H.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $repository = $this->createMock(UserDeviceUiPreferenceRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $this->service = new FilesUiPreferenceService($repository, $entityManager);
    }

    /**
     * @return void
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function testNormalizeDeviceIdRejectsInvalidTokens(): void
    {
        self::assertSame('', $this->service->normalizeDeviceId(''));
        self::assertSame('', $this->service->normalizeDeviceId('bad id with spaces'));
        self::assertSame('', $this->service->normalizeDeviceId('short'));
        self::assertSame('550e8400-e29b-41d4-a716-446655440000', $this->service->normalizeDeviceId('550e8400-e29b-41d4-a716-446655440000'));
    }

    /**
     * @return void
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function testNormalizeIncomingPayloadFallsBackToDefaultsForInvalidValues(): void
    {
        $normalized = $this->service->normalizeIncomingPayload([
            'filesViewMode' => 'invalid',
            'filesScope' => 'invalid',
            'cloudVisibilityState' => [
                'columns' => [
                    'type' => false,
                    'size' => true,
                    'intruder' => true,
                ],
                'sections' => [
                    'my_files' => false,
                    'shared_for_me' => true,
                    'extra' => false,
                ],
            ],
        ]);

        self::assertSame('list', $normalized['filesViewMode']);
        self::assertSame('both', $normalized['filesScope']);
        self::assertSame(false, $normalized['cloudVisibilityState']['columns']['type']);
        self::assertArrayNotHasKey('intruder', $normalized['cloudVisibilityState']['columns']);
        self::assertSame(false, $normalized['cloudVisibilityState']['sections']['my_files']);
        self::assertArrayNotHasKey('extra', $normalized['cloudVisibilityState']['sections']);
    }
}
