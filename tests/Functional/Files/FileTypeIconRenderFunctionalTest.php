<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use App\Entity\User;
use App\Repository\UserRepository;
use PHPUnit\Framework\Assert;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * @brief HTTP contract ensuring /files renders file type icons without server errors.
 *
 * @date 2026-06-24
 * @author Stephane H.
 */
final class FileTypeIconRenderFunctionalTest extends KernelTestCase
{
    /**
     * @brief Return one persisted user matching all required roles or null when unavailable.
     *
     * @param UserRepository $userRepository User repository.
     * @param string[] $requiredRoles Required role names.
     * @return User|null
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function findUserWithRoles(UserRepository $userRepository, array $requiredRoles): ?User
    {
        foreach ($userRepository->findAll() as $candidate) {
            $roles = $candidate->getRoles();
            $ok = true;
            foreach ($requiredRoles as $requiredRole) {
                if (!\in_array($requiredRole, $roles, true)) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @brief Perform one HTTP request with an authenticated persisted user.
     *
     * @param HttpKernelInterface $kernel HTTP kernel.
     * @param EventDispatcherInterface $dispatcher Event dispatcher.
     * @param TokenStorageInterface $tokenStorage Token storage.
     * @param User $user Authenticated user.
     * @param string $uri Absolute path and query.
     * @return Response
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function requestAsUser(
        HttpKernelInterface $kernel,
        EventDispatcherInterface $dispatcher,
        TokenStorageInterface $tokenStorage,
        User $user,
        string $uri,
    ): Response {
        $token = new PostAuthenticationToken($user, 'main', $user->getRoles());
        $tokenStorage->setToken($token);
        $dispatcher->addListener(KernelEvents::REQUEST, function () use ($token, $tokenStorage): void {
            $tokenStorage->setToken($token);
        }, 300);

        $request = Request::create($uri, 'GET');
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $request->setSession($session);

        return $kernel->handle($request);
    }

    /**
     * @brief /files listing must render UX file type icons without HTTP 500.
     *
     * @param void No input parameter.
     * @return void
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function testFilesIndexRendersFileTypeIcons(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $user = $this->findUserWithRoles(
            $container->get(UserRepository::class),
            ['ROLE_SHARE'],
        );
        if ($user === null) {
            self::markTestSkipped('No persisted ROLE_SHARE user in database for /files icon render test.');
        }

        $response = $this->requestAsUser(
            $container->get('http_kernel'),
            $container->get('event_dispatcher'),
            $container->get(TokenStorageInterface::class),
            $user,
            '/files',
        );

        Assert::assertLessThan(
            500,
            $response->getStatusCode(),
            'Files index must not fail when rendering file type icons.',
        );
        Assert::assertStringContainsString(
            'files-type-icon',
            (string) $response->getContent(),
            'Files index markup should include rendered UX file type icons.',
        );
    }
}
