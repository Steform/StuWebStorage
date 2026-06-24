<?php

namespace App\Service\Notification;

use App\Service\Site\SiteMailTemplateResolverService;
use App\Site\SiteMailTemplatesContract;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Service PasswordResetEmailNotificationService.
 */
class PasswordResetEmailNotificationService
{
    /**
     * @brief Build password reset email notification service.
     * @param MailerInterface|null $mailer Mailer transport service.
     * @param SiteMailTemplateResolverService|null $mailTemplateResolver Mail template resolver.
     * @param list<string> $supportedLocales Supported locale codes.
     * @return void
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function __construct(
        private readonly ?MailerInterface $mailer = null,
        private readonly ?SiteMailTemplateResolverService $mailTemplateResolver = null,
        private readonly array $supportedLocales = ['fr', 'en', 'de', 'lt', 'no'],
    ) {
    }

    /**
     * @brief Send password reset email payload.
     * @param string $email Target email.
     * @param string $resetUrl Reset URL.
     * @param string|null $locale Preferred recipient locale.
     * @return void
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function sendPasswordReset(string $email, string $resetUrl, ?string $locale = null): void
    {
        $normalizedEmail = strtolower(trim($email));
        $normalizedResetUrl = trim($resetUrl);
        if ($normalizedEmail === '' || $normalizedResetUrl === '') {
            return;
        }

        if (!$this->mailer instanceof MailerInterface || !$this->mailTemplateResolver instanceof SiteMailTemplateResolverService) {
            return;
        }

        $resolvedLocale = $this->resolveSupportedLocale($locale);
        $resolved = $this->mailTemplateResolver->resolve(SiteMailTemplatesContract::TYPE_PASSWORD_RESET, $resolvedLocale);
        $plainBlocks = $this->buildPlainBlocks($resolved['blocks']);

        $emailMessage = (new TemplatedEmail())
            ->from(new Address($resolved['fromEmail'], $resolved['fromName']))
            ->to(new Address($normalizedEmail))
            ->subject($resolved['subject'])
            ->htmlTemplate('emails/password_reset.html.twig')
            ->textTemplate('emails/password_reset.txt.twig')
            ->context([
                'resetUrl' => $normalizedResetUrl,
                'locale' => $resolved['locale'],
                'blocks' => $resolved['blocks'],
                'labels' => $resolved['labels'],
                'plainBlocks' => $plainBlocks,
            ]);
        $this->mailer->send($emailMessage);
    }

    /**
     * @brief Resolve a supported locale with English fallback.
     * @param string|null $locale Preferred locale candidate.
     * @return string
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function resolveSupportedLocale(?string $locale): string
    {
        $normalizedLocale = strtolower(trim((string) $locale));
        if (in_array($normalizedLocale, $this->supportedLocales, true)) {
            return $normalizedLocale;
        }

        return 'en';
    }

    /**
     * @brief Convert resolved HTML blocks to plain text for text templates.
     * @param array<string, string> $blocks Resolved HTML blocks.
     * @return array<string, string> Plain-text blocks.
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function buildPlainBlocks(array $blocks): array
    {
        $plainBlocks = [];
        foreach ($blocks as $key => $html) {
            $plainBlocks[$key] = $this->mailTemplateResolver->toPlainText($html);
        }

        return $plainBlocks;
    }
}
