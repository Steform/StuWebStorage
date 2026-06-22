<?php

declare(strict_types=1);

namespace App\Service\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * @brief Session-based captcha verification.
 */
final class CaptchaService
{
    public const SESSION_KEY = 'captcha_code';

    private SessionInterface $session;

    /**
     * @brief Build captcha verification service with request session.
     *
     * @param RequestStack $requestStack Symfony request stack.
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function __construct(RequestStack $requestStack)
    {
        $session = $requestStack->getSession();
        if (!$session instanceof SessionInterface) {
            throw new \LogicException('Session is not available at the time of service construction.');
        }
        $this->session = $session;
    }

    /**
     * @brief Compare submitted captcha code with session value (case-insensitive).
     *
     * @param Request $request HTTP request containing captcha_code field.
     * @return bool True when codes match.
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function verifyCaptcha(Request $request): bool
    {
        $captchaCode = (string) $request->request->get('captcha_code', '');
        $expected = (string) $this->session->get(self::SESSION_KEY, '');

        if ($expected === '') {
            return false;
        }

        return strcasecmp($captchaCode, $expected) === 0;
    }

    /**
     * @brief Remove captcha code from session after successful verification.
     *
     * @param void No input parameter.
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function removeCaptcha(): void
    {
        $this->session->remove(self::SESSION_KEY);
    }

    /**
     * @brief Store captcha code in session (used when generating image).
     *
     * @param string $code Numeric captcha code.
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function storeCaptchaCode(string $code): void
    {
        $this->session->set(self::SESSION_KEY, $code);
    }
}
