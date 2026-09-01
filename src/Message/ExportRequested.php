<?php

declare(strict_types=1);

namespace Survos\CivicrmBundle\Message;

/**
 * Somebody asked for an export.
 *
 * The request returns as soon as this is dispatched. Nobody watches a spinner, nobody loses the
 * job by closing the tab, and the work is not bounded by how long a web server is willing to
 * wait -- which is the failure CiviCRM's browser-based queue runner is explicitly documented as
 * having: "There is no way to safely re-connect with the queue" if the browser goes away.
 *
 * @param string $entity APIv4 entity to export
 */
final readonly class ExportRequested
{
    /**
     * @param array<string,mixed> $params APIv4 params: where, select, orderBy
     */
    public function __construct(
        public string $entity,
        public array $params,
        public string $notifyEmail,
        public ?int $requestedByContactId = null,
        public string $format = 'csv',
    ) {
    }
}
