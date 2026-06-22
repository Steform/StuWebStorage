<?php

namespace App\Service\Notification;

use App\Service\Site\SiteMailTemplateResolverService;
use App\Site\SiteMailTemplatesContract;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Service InvitationEmailNotificationService.
 */
class InvitationEmailNotificationService
{
    /**
     * @var list<array{email: string, activationUrl: string}>
     */
    private array $messages = [];

    /**
     * @brief Build invitation email notification service.
     * @param MailerInterface|null $mailer Mailer transport service.
     * @param SiteMailTemplateResolverService|null $mailTemplateResolver Mail template resolver.
     * @param list<string> $supportedLocales Supported locale codes.
     * @param string $defaultLocale Default locale fallback.
     * @param string $fallbackLocale Secondary locale fallback.
     * @return void
     * @date 2026-06-16
     * @author Stephane H.
     */
    public function __construct(
        private readonly ?MailerInterface $mailer = null,
        private readonly ?SiteMailTemplateResolverService $mailTemplateResolver = null,
        private readonly array $supportedLocales = ['fr', 'en', 'de', 'lt', 'no'],
        private readonly string $defaultLocale = 'en',
        private readonly string $fallbackLocale = 'fr'
    ) {
    }

    /**
     * @brief Register and send invitation email payload.
     * @param string $email Target email.
     * @param string $activationUrl Activation URL.
     * @param string|null $locale Preferred recipient locale.
     * @return void
     * @date 2026-06-16
     * @author Stephane H.
     */
    public function sendInvitation(string $email, string $activationUrl, ?string $locale = null): void
    {
        $normalizedEmail = strtolower(trim($email));
        $normalizedActivationUrl = trim($activationUrl);
        if ($normalizedEmail === '' || $normalizedActivationUrl === '') {
            return;
        }
        $resolvedLocale = $this->resolveSupportedLocale($locale);

        $this->messages[] = [
            'email' => $normalizedEmail,
            'activationUrl' => $normalizedActivationUrl,
        ];

        if (!$this->mailer instanceof MailerInterface || !$this->mailTemplateResolver instanceof SiteMailTemplateResolverService) {
            return;
        }

        $resolved = $this->mailTemplateResolver->resolve(SiteMailTemplatesContract::TYPE_INVITATION, $resolvedLocale);
        $plainBlocks = $this->buildPlainBlocks($resolved['blocks']);

        $emailMessage = (new TemplatedEmail())
            ->from(new Address($resolved['fromEmail'], $resolved['fromName']))
            ->to(new Address($normalizedEmail))
            ->subject($resolved['subject'])
            ->htmlTemplate('emails/invitation.html.twig')
            ->textTemplate('emails/invitation.txt.twig')
            ->context([
                'activationUrl' => $normalizedActivationUrl,
                'locale' => $resolved['locale'],
                'blocks' => $resolved['blocks'],
                'labels' => $resolved['labels'],
                'plainBlocks' => $plainBlocks,
            ]);
        $this->mailer->send($emailMessage);
    }

    /**
     * @brief Resolve a supported locale with fallback strategy.
     * @param string|null $locale Preferred locale candidate.
     * @return string
     * @date 2026-04-28
     * @author Stephane H.
     */
    private function resolveSupportedLocale(?string $locale): string
    {
        $normalizedLocale = strtolower(trim((string) $locale));
        if (in_array($normalizedLocale, $this->supportedLocales, true)) {
            return $normalizedLocale;
        }
        if (in_array($this->defaultLocale, $this->supportedLocales, true)) {
            return $this->defaultLocale;
        }
        if (in_array($this->fallbackLocale, $this->supportedLocales, true)) {
            return $this->fallbackLocale;
        }

        return $this->supportedLocales[0] ?? 'en';
    }

    /**
     * @brief Return all queued invitation messages.
     * @param void No input parameter.
     * @return list<array{email: string, activationUrl: string}>
     * @date 2026-04-28
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
