<?php

declare(strict_types=1);

namespace Survos\CivicrmBundle\Message;

/**
 * The export finished; tell the person who asked.
 *
 * Separate from ExportRequested on purpose. Building the file and telling somebody about it fail
 * in different ways and deserve their own retries: a transient SMTP outage should not rebuild a
 * 40MB zip, and a failed export should not send a link to a file that is not there.
 */
final readonly class ExportReady
{
    public function __construct(
        public string $downloadUrl,
        public string $notifyEmail,
        public int $rowCount,
        public string $entity,
        public ?\DateTimeImmutable $expiresAt = null,
    ) {
    }
}
