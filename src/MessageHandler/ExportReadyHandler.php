<?php

declare(strict_types=1);

namespace Survos\CivicrmBundle\MessageHandler;

use Survos\CivicrmBundle\Message\ExportReady;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;

/**
 * Tell somebody their export is ready, with a link to it.
 *
 * Worth noticing what is absent: no spinner, no tab that had to stay open, no thirty-second
 * budget. The person asked for an export twenty minutes ago and got on with their day.
 */
#[AsMessageHandler]
final readonly class ExportReadyHandler
{
    public function __construct(
        private MailerInterface $mailer,
        private string $fromAddress,
        private string $fromName = 'VillageDesk',
    ) {
    }

    public function __invoke(ExportReady $message): void
    {
        $expires = $message->expiresAt?->format('j F Y');

        $body = sprintf(
            "Your export of %s is ready.\n\n%s\n\n%s records.%s\n",
            $message->entity,
            $message->downloadUrl,
            number_format($message->rowCount),
            null !== $expires ? "\n\nThe link works until {$expires}." : '',
        );

        $this->mailer->send(
            (new Email())
                ->from(sprintf('%s <%s>', $this->fromName, $this->fromAddress))
                ->to($message->notifyEmail)
                ->subject(sprintf('Your %s export is ready', $message->entity))
                ->text($body),
        );
    }
}
