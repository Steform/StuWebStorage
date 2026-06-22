<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Service\File\UserPaneFolderQueryKeys;
use PHPUnit\Framework\TestCase;

/**
 * @brief Unit tests for per-pane folder query key helpers.
 * @date 2026-05-04
 * @author Stephane H.
 */
final class UserPaneFolderQueryKeysTest extends TestCase
{
    /**
     * @brief Owned and shared keys follow uf_/sf_ prefix convention.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function testKeysMatchConvention(): void
    {
        self::assertSame('uf_42', UserPaneFolderQueryKeys::ownedFolderKey(42));
        self::assertSame('sf_7', UserPaneFolderQueryKeys::sharedFolderKey(7));
    }
}
