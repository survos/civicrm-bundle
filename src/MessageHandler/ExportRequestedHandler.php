<?php

declare(strict_types=1);

namespace Survos\CivicrmBundle\MessageHandler;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Survos\Civicrm\Contract\CiviCrmClientInterface;
use Survos\CivicrmBundle\Message\ExportReady;
use Survos\CivicrmBundle\Message\ExportRequested;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Build an export, then hand off to whoever announces it.
 *
 * The shape is the point. CiviCRM's own long-running work happens either in the browser -- an
 * AJAX progress bar that cannot survive a closed tab -- or in `ShutdownWorker`, which flushes
 * the response and then keeps working in the same PHP process for a fixed thirty seconds. Both
 * tie the work to a request. If nobody visits a page, nothing happens; if the export takes
 * longer than the budget, it is simply cut off.
 *
 * Here the request ends when the message is dispatched, and a worker that is already running
 * does the work. It takes as long as it takes, it retries on its own, and a failure lands in a
 * failure transport where somebody can see it rather than vanishing into a shutdown handler
 * nobody is watching.
 */
#[AsMessageHandler]
final readonly class ExportRequestedHandler
{
    public function __construct(
        private CiviCrmClientInterface $civi,
        private MessageBusInterface $bus,
        private string $exportDir,
        private string $downloadBaseUrl,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(ExportRequested $message): void
    {
        if (!is_dir($this->exportDir) && !mkdir($this->exportDir, 0o750, true) && !is_dir($this->exportDir)) {
            throw new \RuntimeException(sprintf('Cannot create export directory %s', $this->exportDir));
        }

        $stem = sprintf('%s-%s', strtolower($message->entity), bin2hex(random_bytes(8)));
        $csvPath = $this->exportDir . '/' . $stem . '.csv';
        $zipPath = $this->exportDir . '/' . $stem . '.zip';

        $handle = fopen($csvPath, 'w');
        if (false === $handle) {
            throw new \RuntimeException(sprintf('Cannot write %s', $csvPath));
        }

        $rows = 0;
        $headerWritten = false;

        /*
         * Streamed, not accumulated.
         *
         * all() pages through the API and yields, so peak memory is one page rather than one
         * export. That is the difference between a worker that handles a 700-row village and
         * one that handles a federation without anybody having to think about it.
         */
        foreach ($this->civi->all($message->entity, $message->params) as $row) {
            $flat = self::flatten($row);

            if (!$headerWritten) {
                // escape: '' is both the fix for PHP 8.4's deprecation and the more correct
                // behaviour -- PHP's default backslash escaping is not in RFC 4180 and produces
                // cells that Excel and pandas read back wrong.
                fputcsv($handle, array_keys($flat), escape: '');
                $headerWritten = true;
            }
            fputcsv($handle, array_values($flat), escape: '');
            ++$rows;
        }
        fclose($handle);

        $zip = new \ZipArchive();
        if (true !== $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            throw new \RuntimeException(sprintf('Cannot create %s', $zipPath));
        }
        $zip->addFile($csvPath, basename($csvPath));
        $zip->close();
        unlink($csvPath);

        $this->logger->info('CiviCRM export built', [
            'entity' => $message->entity,
            'rows' => $rows,
            'file' => basename($zipPath),
        ]);

        // Announcing it is a separate message with its own retries: a transient SMTP failure
        // must not rebuild the export, and a failed export must not send a link to nothing.
        $this->bus->dispatch(new ExportReady(
            downloadUrl: rtrim($this->downloadBaseUrl, '/') . '/' . basename($zipPath),
            notifyEmail: $message->notifyEmail,
            rowCount: $rows,
            entity: $message->entity,
            expiresAt: new \DateTimeImmutable('+7 days'),
        ));
    }

    /**
     * APIv4 returns nested values for joined fields; a CSV cell cannot hold an array.
     *
     * @param array<string,mixed> $row
     *
     * @return array<string,string>
     */
    private static function flatten(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            $out[(string) $key] = match (true) {
                null === $value => '',
                \is_bool($value) => $value ? '1' : '0',
                \is_scalar($value) => (string) $value,
                default => json_encode($value, \JSON_UNESCAPED_SLASHES) ?: '',
            };
        }

        return $out;
    }
}
