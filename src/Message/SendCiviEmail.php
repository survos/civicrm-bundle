<?php

declare(strict_types=1);

namespace Survos\CivicrmBundle\Message;

/**
 * Send one email, later.
 *
 * CiviCRM sends transactional mail synchronously through PEAR Mail, inside the request that
 * triggered it -- so a slow SMTP server is a slow page, and an SMTP server that is down is an
 * error in front of whoever pressed the button. Dispatching this instead returns immediately
 * and lets a worker do the waiting, with retries the user never sees.
 */
final readonly class SendCiviEmail
{
    /**
     * @param array<string,mixed> $context template variables
     */
    public function __construct(
        public string $toEmail,
        public string $subject,
        public string $body,
        public ?string $toName = null,
        public ?int $contactId = null,
        public array $context = [],
    ) {
    }
}
