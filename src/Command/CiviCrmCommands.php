<?php

declare(strict_types=1);

namespace Survos\CivicrmBundle\Command;

use Survos\Civicrm\Contract\CiviCrmClientInterface;
use Survos\CivicrmBundle\Message\ExportRequested;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Console access to CiviCRM, for the things a person does at a terminal.
 *
 * Method-level commands on one service, per house style, so the client is injected once and the
 * commands stay thin enough to read.
 */
final readonly class CiviCrmCommands
{
    public function __construct(
        private CiviCrmClientInterface $civi,
        private MessageBusInterface $bus,
    ) {
    }

    /**
     * Count records of an entity.
     */
    #[AsCommand('civicrm:count', 'Count records of a CiviCRM entity')]
    public function count(
        SymfonyStyle $io,
        #[Argument('APIv4 entity, e.g. Contact')] string $entity,
    ): int {
        $io->writeln(sprintf('%s: %s', $entity, number_format($this->civi->count($entity))));

        return 0;
    }

    /**
     * Show a few records, to see what a field is actually called.
     */
    #[AsCommand('civicrm:peek', 'Show the first few records of an entity')]
    public function peek(
        SymfonyStyle $io,
        #[Argument('APIv4 entity, e.g. Contact')] string $entity,
        #[Option('How many rows')] int $limit = 5,
    ): int {
        $rows = $this->civi->call($entity, 'get', ['limit' => $limit]);
        if ([] === $rows) {
            $io->warning('No records.');

            return 0;
        }

        $headers = array_keys(reset($rows));
        $io->table($headers, array_map(
            static fn (array $r): array => array_map(
                static fn ($v): string => (\is_scalar($v) || null === $v) ? (string) $v : (json_encode($v) ?: ''),
                array_values($r),
            ),
            $rows,
        ));

        return 0;
    }

    /**
     * Ask for an export, and stop waiting for it.
     *
     * The command returns as soon as the message is on the bus. A worker builds the file and
     * emails a link when it is done -- which is the whole argument for doing this with Messenger
     * rather than in the request that asked.
     */
    #[AsCommand('civicrm:export', 'Queue an export and email a link when it is ready')]
    public function export(
        SymfonyStyle $io,
        #[Argument('APIv4 entity to export')] string $entity,
        #[Argument('Where to send the link')] string $email,
    ): int {
        $this->bus->dispatch(new ExportRequested(
            entity: $entity,
            params: [],
            notifyEmail: $email,
        ));

        $io->success(sprintf('Export of %s queued. %s will get a link when it is ready.', $entity, $email));

        return 0;
    }
}
