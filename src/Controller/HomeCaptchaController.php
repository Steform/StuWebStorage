<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Security\CaptchaImageGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * @brief Serves homepage captcha PNG images.
 */
final class HomeCaptchaController extends AbstractController
{
    /**
     * @brief Generate captcha PNG and store code in session.
     *
     * @param CaptchaImageGenerator $captchaImageGenerator Captcha image builder.
     * @return Response PNG image response.
     * @date 2026-06-23
     * @author Stephane H.
     */
    #[Route('/home/captcha', name: 'storage_home_captcha', methods: ['GET'])]
    public function captcha(CaptchaImageGenerator $captchaImageGenerator): Response
    {
        return $captchaImageGenerator->generateResponse();
    }
}
