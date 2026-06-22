<?php

namespace App\Service\Notification;

use App\Service\Site\SiteMailTemplateResolverService;
use App\Site\SiteMailTemplatesContract;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Service TotpEmailNotificationService.
 */
class TotpEmailNotificationService
{
    /**
     * @var list<array{email: string, code: string}>
     */
    private array $messages = [];

    /**
     * @brief Build TOTP email notification service.
     * @param MailerInterface $mailer Mailer transport service.
     * @param SiteMailTemplateResolverService $mailTemplateResolver Mail template resolver.
     * @return void
     * @date 2026-06-16
     * @author Stephane H.
     */
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly SiteMailTemplateResolverService $mailTemplateResolver,
    ) {
    }

    /**
     * @brief Send TOTP code through SMTP and keep local trace.
     * @param string $email Recipient email address.
     * @param string $code Generated TOTP code.
     * @param string|null $locale Preferred recipient locale.
     * @return void
     * @date 2026-06-16
     * @author Stephane H.
     */
    public function sendTotpCode(string $email, string $code, ?string $locale = null): void
    {
        $normalizedEmail = strtolower(trim($email));
        $normalizedCode = trim($code);
        if ($normalizedEmail === '' || $normalizedCode === '') {
            return;
        }

        $this->messages[] = [
            'email' => $normalizedEmail,
            'code' => $normalizedCode,
        ];

        $resolved = $this->mailTemplateResolver->resolve(SiteMailTemplatesContract::TYPE_TOTP, $locale);
        $plainBlocks = $this->buildPlainBlocks($resolved['blocks']);

        $email = (new TemplatedEmail())
            ->from(new Address($resolved['fromEmail'], $resolved['fromName']))
            ->to(new Address($normalizedEmail))
            ->subject($resolved['subject'])
            ->htmlTemplate('emails/totp_code.html.twig')
            ->textTemplate('emails/totp_code.txt.twig')
            ->context([
                'totpCode' => $normalizedCode,
                'locale' => $resolved['locale'],
                'blocks' => $resolved['blocks'],
                'labels' => $resolved['labels'],
                'plainBlocks' => $plainBlocks,
            ]);
        $this->mailer->send($email);
    }

    /**
     * @brief Return locally tracked TOTP notifications.
     * @param void No input parameter.
     * @return list<array{email: string, code: string}>
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * @brief Convert resolved HTML blocks to plain text for text templates.
     *
     * @param array<string, string> $blocks Resolved HTML blocks.
     * @return array<string, string> Plain-text blocks.
     * @date 2026-06-16
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
