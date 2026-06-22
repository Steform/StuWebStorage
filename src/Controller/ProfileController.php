<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\Profile\ProfileUpdateService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;

/**
 * Controller ProfileController.
 */
class ProfileController
{
    /**
     * @brief Build profile controller.
     * @param ProfileUpdateService $profileUpdateService Profile update service.
     * @return void
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function __construct(private readonly ProfileUpdateService $profileUpdateService)
    {
    }

    /**
     * @brief Render authenticated user profile page.
     * @param Environment $twig Twig environment.
     * @param Security $security Security helper.
     * @return Response
     * @date 2026-04-23
     * @author Stephane H.
     */
    #[IsGranted('ROLE_USER')]
    #[Route('/profile', name: 'app_profile_show', methods: ['GET'])]
    public function show(Environment $twig, Security $security): Response
    {
        $user = $security->getUser();
        if (!$user instanceof User) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        return new Response($twig->render('profile/show.html.twig', [
            'profileUser' => $user,
        ]));
    }

    /**
     * @brief Update authenticated user pseudonym.
     * @param Request $request Current request.
     * @param Security $security Security helper.
     * @return Response
     * @date 2026-04-23
     * @author Stephane H.
     */
    #[IsGranted('ROLE_USER')]
    #[Route('/profile/pseudonym', name: 'app_profile_update_pseudonym', methods: ['POST'])]
    public function updatePseudonym(Request $request, Security $security): Response
    {
        $user = $security->getUser();
        if (!$user instanceof User) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        $errorKey = $this->profileUpdateService->updatePseudonym($user, (string) $request->request->get('pseudonym', ''));
        if (is_string($errorKey) && $request->hasSession()) {
            $request->getSession()->getFlashBag()->add('danger', $errorKey);
        }
        if ($errorKey === null && $request->hasSession()) {
            $request->getSession()->getFlashBag()->add('success', 'profile.success.pseudonym_updated');
        }

        return new RedirectResponse('/profile');
    }

    /**
     * @brief Request pending email change with TOTP sent to new email.
     * @param Request $request Current request.
     * @param Security $security Security helper.
     * @return Response
     * @date 2026-04-23
     * @author Stephane H.
     */
    #[IsGranted('ROLE_USER')]
    #[Route('/profile/email/request', name: 'app_profile_email_request', methods: ['POST'])]
    public function requestEmailChange(Request $request, Security $security): Response
    {
        $user = $security->getUser();
        if (!$user instanceof User) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        $errorKey = $this->profileUpdateService->requestEmailChange($user, (string) $request->request->get('new_email', ''));
        if (is_string($errorKey) && $request->hasSession()) {
            $request->getSession()->getFlashBag()->add('danger', $errorKey);
        }
        if ($errorKey === null && $request->hasSession()) {
            $request->getSession()->getFlashBag()->add('success', 'profile.success.email_change_requested');
        }

        return new RedirectResponse('/profile');
    }

    /**
     * @brief Confirm pending email change with provided TOTP.
     * @param Request $request Current request.
     * @param Security $security Security helper.
     * @return Response
     * @date 2026-04-23
     * @author Stephane H.
     */
    #[IsGranted('ROLE_USER')]
    #[Route('/profile/email/confirm', name: 'app_profile_email_confirm', methods: ['POST'])]
    public function confirmEmailChange(Request $request, Security $security): Response
    {
        $user = $security->getUser();
        if (!$user instanceof User) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        $errorKey = $this->profileUpdateService->confirmEmailChange($user, (string) $request->request->get('email_totp_code', ''));
        if (is_string($errorKey) && $request->hasSession()) {
            $request->getSession()->getFlashBag()->add('danger', $errorKey);
        }
        if ($errorKey === null && $request->hasSession()) {
            $request->getSession()->getFlashBag()->add('success', 'profile.success.email_updated');
        }

        return new RedirectResponse('/profile');
    }

    /**
     * @brief Request password change TOTP to current email.
     * @param Request $request Current request.
     * @param Security $security Security helper.
     * @return Response
     * @date 2026-04-23
     * @author Stephane H.
     */
    #[IsGranted('ROLE_USER')]
    #[Route('/profile/password/request', name: 'app_profile_password_request', methods: ['POST'])]
    public function requestPasswordChange(Request $request, Security $security): Response
    {
        $user = $security->getUser();
        if (!$user instanceof User) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        $this->profileUpdateService->requestPasswordChangeTotp($user);
        if ($request->hasSession()) {
            $request->getSession()->getFlashBag()->add('success', 'profile.success.password_totp_sent');
        }

        return new RedirectResponse('/profile');
    }

    /**
     * @brief Confirm password change with current password and TOTP.
     * @param Request $request Current request.
     * @param Security $security Security helper.
     * @return Response
     * @date 2026-04-23
     * @author Stephane H.
     */
    #[IsGranted('ROLE_USER')]
    #[Route('/profile/password/confirm', name: 'app_profile_password_confirm', methods: ['POST'])]
    public function confirmPasswordChange(Request $request, Security $security): Response
    {
        $user = $security->getUser();
        if (!$user instanceof User) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        $errorKey = $this->profileUpdateService->confirmPasswordChange(
            $user,
            (string) $request->request->get('current_password', ''),
            (string) $request->request->get('new_password', ''),
            (string) $request->request->get('password_totp_code', '')
        );
        if (is_string($errorKey) && $request->hasSession()) {
            $request->getSession()->getFlashBag()->add('danger', $errorKey);
        }
        if ($errorKey === null && $request->hasSession()) {
            $request->getSession()->getFlashBag()->add('success', 'profile.success.password_updated');
        }

        return new RedirectResponse('/profile');
    }
}
