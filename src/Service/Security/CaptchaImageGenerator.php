<?php

declare(strict_types=1);

namespace App\Service\Security;

use Symfony\Component\HttpFoundation\Response;

/**
 * @brief Generates PNG captcha images.
 */
final class CaptchaImageGenerator
{
    /**
     * @brief Build captcha image generator service.
     *
     * @param CaptchaService $captchaService Session captcha storage.
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function __construct(
        private readonly CaptchaService $captchaService,
    ) {
    }

    /**
     * @brief Generate a new captcha code, persist it in session, and return PNG bytes.
     *
     * @param void No input parameter.
     * @return Response PNG image HTTP response.
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function generateResponse(): Response
    {
        $code = (string) random_int(1000, 9999);
        $this->captchaService->storeCaptchaCode($code);

        $image = $this->createCaptchaImage($code);
        ob_start();
        imagepng($image);
        $imageData = ob_get_clean() ?: '';
        imagedestroy($image);

        return new Response($imageData, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-cache, must-revalidate',
        ]);
    }

    /**
     * @brief Render captcha digits on a small true-color image.
     *
     * @param string $code Four-digit captcha code.
     * @return \GdImage GD image resource.
     * @date 2026-06-22
     * @author Stephane H.
     */
    private function createCaptchaImage(string $code): \GdImage
    {
        $image = imagecreatetruecolor(50, 24);

        $c1 = random_int(0, 255);
        $c2 = random_int(0, 255);
        $c3 = random_int(0, 255);
        $b1 = min($c1 + 128, 255);
        $b2 = min($c2 + 128, 255);
        $b3 = min($c3 + 128, 255);
        $background = imagecolorallocate($image, $c1, $c2, $c3);
        $foreground = imagecolorallocate($image, $b1, $b2, $b3);
        imagefill($image, 0, 0, $background);
        imagestring($image, 5, 5, 5, $code, $foreground);

        return $image;
    }
}
