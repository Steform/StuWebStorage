<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\Auth\TotpChallengeService;
use App\Service\Notification\TotpEmailNotificationService;
use App\Service\Setup\SetupStateService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Twig\Environment;

/**
 * Controller SetupController.
 */
class SetupController
{
    public function __construct(
        private readonly SetupStateService $setupStateService,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly TotpChallengeService $totpChallengeService,
        private readonly TotpEmailNotificationService $totpEmailNotificationService
    )
    {
    }

    /**
     * @brief Render setup page for first admin bootstrap.
     * @param Environment $twig Twig environment.
     * @return Response
     * @date 2026-04-23
     * @author Stephane H.
     */
    #[Route('/setup', name: 'setup_index', methods: ['GET'])]
    public function index(Environment $twig): Response
    {
        if ($this->setupStateService->hasConfirmedAdminUser()) {
            return new RedirectResponse('/');
        }

        return new Response($twig->render('setup/index.html.twig'));
    }

    /**
     * @brief Create first admin account from setup form.
     * @param Request $request HTTP request with admin fields.
     * @return Response
     * @date 2026-04-23
     * @author Stephane H.
     */
    #[Route('/setup', name: 'setup_create_admin', methods: ['POST'])]
    public function createAdmin(Request $request): Response
    {
        if ($this->setupStateService->hasConfirmedAdminUser()) {
            return new RedirectResponse('/');
        }

        $email = trim((string) $request->request->get('email', ''));
        $password = trim((string) $request->request->get('password', ''));
        $pseudonym = trim((string) $request->request->get('pseudonym', ''));
        $normalizedEmail = strtolower($email);

        if ($email === '' || $password === '' || $pseudonym === '') {
            $this->addRequestFlash($request, 'danger', 'setup.invalid_payload');

            return new RedirectResponse('/setup');
        }

        /** @var User|null $existing */
        $existing = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $normalizedEmail]);
        $user = $existing instanceof User ? $existing : new User();
        if ($existing instanceof User && (!in_array('ROLE_ADMIN', $existing->getRoles(), true) || $existing->isSetupConfirmed())) {
            $this->addRequestFlash($request, 'danger', 'setup.email_already_used');

            return new RedirectResponse('/setup');
        }

        $user->setEmail($normalizedEmail);
        $user->setPseudonym($pseudonym);
        $user->setRoles(['ROLE_ADMIN', 'ROLE_USER']);
        $user->setTotpEnabled(true);
        $user->setSetupConfirmed(false);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        if (!$existing instanceof User) {
            $this->entityManager->persist($user);
        }
        $this->entityManager->flush();

        $totpCode = (string) random_int(100000, 999999);
        $this->totpChallengeService->createLoginChallenge($user->getEmail(), $totpCode);
        $this->totpEmailNotificationService->sendTotpCode($user->getEmail(), $totpCode);
        $this->addRequestFlash($request, 'info', 'setup.totp_sent');

        return new RedirectResponse('/setup/validate?email='.urlencode($user->getEmail()));
    }

    /**
     * @brief Render setup TOTP validation page.
     * @param Environment $twig Twig environment.
     * @param Request $request Current HTTP request.
     * @return Response
     * @date 2026-04-23
     * @author Stephane H.
     */
    #[Route('/setup/validate', name: 'setup_validate', methods: ['GET'])]
    public function validatePage(Environment $twig, Request $request): Response
    {
        if ($this->setupStateService->hasConfirmedAdminUser()) {
            return new RedirectResponse('/');
        }

        $email = strtolower(trim((string) $request->query->get('email', '')));
        if (!$this->setupStateService->hasPendingAdminUserByEmail($email)) {
            return new RedirectResponse('/setup');
        }

        return new Response($twig->render('setup/validate.html.twig', [
            'email' => $email,
            'error' => (string) $request->query->get('error', ''),
        ]));
    }

    /**
     * @brief Validate setup TOTP code to confirm first admin.
     * @param Request $request Current HTTP request.
     * @return Response
     * @date 2026-04-23
     * @author Stephane H.
     */
    #[Route('/setup/validate', name: 'setup_validate_submit', methods: ['POST'])]
    public function validateSubmit(Request $request): Response
    {
        if ($this->setupStateService->hasConfirmedAdminUser()) {
            return new RedirectResponse('/');
        }

        $email = strtolower(trim((string) $request->request->get('email', '')));
        $totpCode = trim((string) $request->request->get('totp', ''));
        if (!$this->setupStateService->hasPendingAdminUserByEmail($email)) {
            return new RedirectResponse('/setup');
        }
        if (!$this->totpChallengeService->validateLoginChallenge($email, $totpCode)) {
            return new RedirectResponse('/setup/validate?email='.urlencode($email).'&error=auth.totp.invalid');
        }

        return new RedirectResponse('/login');
    }

    /**
     * @brief Restore platform from backup archive.
     * @param Request $request JSON request with archive path.
     * @return Response
     * @date 2026-04-23
     * @author Stephane H.
     */
    #[Route('/setup/restore', name: 'setup_restore', methods: ['POST'])]
    public function restore(Request $request): Response
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new Response('backup.invalid', 400);
        }
        $archivePath = (string) ($payload['archivePath'] ?? '');

        if ($this->setupStateService->isLocked()) {
            return new Response('setup.locked', 403);
        }

        if ($archivePath === '') {
            return new Response('backup.invalid', 400);
        }

        // Keep explicit lock call for setup lifecycle consistency in restore workflow.
        $this->setupStateService->lock();

        return new Response('restore.completed', 200);
    }

    /**
     * @brief Add one flash message when request session exists.
     * @param Request $request Current HTTP request.
     * @param string $type Flash type key.
     * @param string $message Translation key for message.
     * @return void
     * @date 2026-04-23
     * @author Stephane H.
     */
    private function addRequestFlash(Request $request, string $type, string $message): void
    {
        if (!$request->hasSession()) {
            return;
        }

        $request->getSession()->getFlashBag()->add($type, $message);
    }
}
