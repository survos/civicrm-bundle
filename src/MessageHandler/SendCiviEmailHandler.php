<?php

declare(strict_types=1);

namespace Survos\CivicrmBundle\MessageHandler;

use Survos\Civicrm\Contract\CiviCrmClientInterface;
use Survos\CivicrmBundle\Message\SendCiviEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;

/**
 * Send one message, and record that it was sent.
 *
 * Two things happen here that CiviCRM does in one synchronous step inside the request: the
 * delivery, and the activity that records it. Splitting them out means a slow mail server costs
 * a worker rather than a page, and a failure is retried rather than shown to whoever pressed
 * the button.
 *
 * Recording the send back into CiviCRM matters as much as the send. A message the CRM does not
 * know about is a message no coordinator can find later, and "did anyone tell her the outing
 * was cancelled?" is exactly the question a village needs answered.
 */
#[AsMessageHandler]
final readonly class SendCiviEmailHandler
{
    public function __construct(
        private MailerInterface $mailer,
        private CiviCrmClientInterface $civi,
        private string $fromAddress,
        private string $fromName,
    ) {
    }

    public function __invoke(SendCiviEmail $message): void
    {
        $to = null !== $message->toName
            ? sprintf('%s <%s>', $message->toName, $message->toEmail)
            : $message->toEmail;

        $this->mailer->send(
            (new Email())
                ->from(sprintf('%s <%s>', $this->fromName, $this->fromAddress))
                ->to($to)
                ->subject($message->subject)
                ->text($message->body),
        );

        if (null === $message->contactId) {
            return;
        }

        /*
         * Logged through the API, not an INSERT.
         *
         * An activity written straight to the tables skips the hooks extensions rely on, the
         * ACLs that decide who may see it, and the change log that says it happened -- and it
         * does so silently, which is the worst way to be wrong.
         */
        $this->civi->call('Activity', 'create', [
            'values' => [
                'activity_type_id:name' => 'Email',
                'subject' => $message->subject,
                'details' => $message->body,
                'status_id:name' => 'Completed',
                'source_contact_id' => $message->contactId,
                'target_contact_id' => [$message->contactId],
            ],
        ]);
    }
}
