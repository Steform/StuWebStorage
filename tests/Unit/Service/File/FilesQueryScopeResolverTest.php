<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Entity\User;
use App\Service\File\FilesQueryScopeResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * @brief Unit tests for admin godview canonical view_scope resolution.
 * @date 2026-05-04
 * @author Stephane H.
 */
final class FilesQueryScopeResolverTest extends TestCase
{
    /**
     * @brief When admin_view_scope=all, stale view_scope=me must not block canonical "all" (multi-pane).
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function testAdminViewScopeAllOverridesStaleViewScopeMe(): void
    {
        $resolver = new FilesQueryScopeResolver();
        $user = $this->createUser(42);
        $request = Request::create('/files?admin_context=1&admin_view_scope=all&view_scope=me');

        $out = $resolver->resolve($request, $user, true);

        self::assertTrue($out['adminContext']);
        self::assertSame('all', $out['viewScope']);
        self::assertSame('all', $out['canonicalViewScope']);
        self::assertNull($out['subjectUserId']);
    }

    /**
     * @brief Explicit view_scope=user with subject_user keeps single-subject drilldown.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function testAdminViewScopeAllPreservesUserDrilldownWhenViewScopeUser(): void
    {
        $resolver = new FilesQueryScopeResolver();
        $user = $this->createUser(1);
        $request = Request::create('/files?admin_context=1&admin_view_scope=all&view_scope=user&subject_user=99');

        $out = $resolver->resolve($request, $user, true);

        self::assertSame('all', $out['viewScope']);
        self::assertSame('user', $out['canonicalViewScope']);
        self::assertSame(99, $out['subjectUserId']);
    }

    /**
     * @brief Non-admin requests ignore admin_view_scope and stay on "me".
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function testNonAdminIgnoresAdminQueryParams(): void
    {
        $resolver = new FilesQueryScopeResolver();
        $user = $this->createUser(7);
        $request = Request::create('/files?admin_context=1&admin_view_scope=all&view_scope=all');

        $out = $resolver->resolve($request, $user, false);

        self::assertFalse($out['adminContext']);
        self::assertSame('me', $out['canonicalViewScope']);
        self::assertSame(7, $out['subjectUserId']);
    }

    /**
     * @brief Create a User stub with a fixed id.
     * @param int $id User id.
     * @return User
     * @date 2026-05-04
     * @author Stephane H.
     */
    private function createUser(int $id): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);

        return $user;
    }
}
