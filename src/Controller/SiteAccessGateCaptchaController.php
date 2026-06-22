<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Security\CaptchaImageGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * @brief Serves antibot gate captcha PNG images.
 */
final class SiteAccessGateCaptchaController extends AbstractController
{
    /**
     * @brief Generate captcha PNG and store code in session.
     *
     * @param CaptchaImageGenerator $captchaImageGenerator Captcha image builder.
     * @return Response PNG image response.
     * @date 2026-06-22
     * @author Stephane H.
     */
    #[Route('/access-gate/captcha', name: 'storage_access_gate_captcha', methods: ['GET'])]
    public function captcha(CaptchaImageGenerator $captchaImageGenerator): Response
    {
        return $captchaImageGenerator->generateResponse();
    }
}
