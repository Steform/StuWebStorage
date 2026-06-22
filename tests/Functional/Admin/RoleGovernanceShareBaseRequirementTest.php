<?php



declare(strict_types=1);



namespace App\Tests\Functional\Admin;



use App\Entity\User;

use App\Repository\UserRepository;

use App\Service\Admin\RoleGovernanceService;
use App\Service\File\FilesStorageFeatureService;

use PHPUnit\Framework\TestCase;



/**

 * Role governance regression tests for outbound share tiers requiring ROLE_SHARE_SEND.

 */

class RoleGovernanceShareBaseRequirementTest extends TestCase

{

    /**

     * @brief Allowed roles list including sender tier.

     * @return array<int, string>

     * @date 2026-05-02

     * @author Stephane H.

     */

    private function governanceRoles(): array

    {

        return ['ROLE_USER', 'ROLE_ADMIN', 'ROLE_SHARE', 'ROLE_SHARE_SEND', 'ROLE_SHARE_PUBLIC', 'ROLE_SHARE_FRIENDS'];

    }



    /**

     * @brief Ensure ROLE_SHARE_PUBLIC alone is rejected without ROLE_SHARE_SEND.

     * @param void No input parameter.

     * @return void

     * @date 2026-05-02

     * @author Stephane H.

     */

    public function testSharePublicRequiresSendTier(): void

    {

        $repository = $this->createMock(UserRepository::class);

        $service = new RoleGovernanceService($repository, new FilesStorageFeatureService(true), $this->governanceRoles());



        $actor = new User();

        $target = new User();



        self::assertSame(

            'admin.users.error.share_public_friends_requires_send',

            $service->validateRoleChange($actor, $target, ['ROLE_USER', 'ROLE_SHARE_PUBLIC'])

        );

        self::assertSame(

            'admin.users.error.share_public_friends_requires_send',

            $service->validateRoleChange($actor, $target, ['ROLE_USER', 'ROLE_SHARE', 'ROLE_SHARE_PUBLIC'])

        );

        self::assertNull($service->validateRoleChange($actor, $target, ['ROLE_USER', 'ROLE_SHARE_SEND', 'ROLE_SHARE_PUBLIC']));

    }



    /**

     * @brief Ensure ROLE_SHARE_FRIENDS alone is rejected without ROLE_SHARE_SEND.

     * @param void No input parameter.

     * @return void

     * @date 2026-05-02

     * @author Stephane H.

     */

    public function testShareFriendsRequiresSendTier(): void

    {

        $repository = $this->createMock(UserRepository::class);

        $service = new RoleGovernanceService($repository, new FilesStorageFeatureService(true), $this->governanceRoles());



        $actor = new User();

        $target = new User();



        self::assertSame(

            'admin.users.error.share_public_friends_requires_send',

            $service->validateRoleChange($actor, $target, ['ROLE_USER', 'ROLE_SHARE_FRIENDS'])

        );

        self::assertSame(

            'admin.users.error.share_public_friends_requires_send',

            $service->validateRoleChange($actor, $target, ['ROLE_USER', 'ROLE_SHARE', 'ROLE_SHARE_FRIENDS'])

        );

        self::assertNull($service->validateRoleChange($actor, $target, ['ROLE_USER', 'ROLE_SHARE_SEND', 'ROLE_SHARE_FRIENDS']));

    }

}


