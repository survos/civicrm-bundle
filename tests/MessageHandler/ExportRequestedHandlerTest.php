<?php

declare(strict_types=1);

namespace Survos\CivicrmBundle\Tests\MessageHandler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Survos\Civicrm\Contract\CiviCrmClientInterface;
use Survos\CivicrmBundle\Message\ExportReady;
use Survos\CivicrmBundle\Message\ExportRequested;
use Survos\CivicrmBundle\MessageHandler\ExportRequestedHandler;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[CoversClass(ExportRequestedHandler::class)]
final class ExportRequestedHandlerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/civi-export-test-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            array_map(unlink(...), glob($this->dir.'/*') ?: []);
            rmdir($this->dir);
        }
    }

    public function testItBuildsAZipAndAnnouncesItAsASeparateMessage(): void
    {
        $dispatched = [];
        $handler = new ExportRequestedHandler(
            civi: $this->civi([
                ['id' => 1, 'display_name' => 'Ada Lovelace', 'email' => 'ada@example.org'],
                ['id' => 2, 'display_name' => 'Grace Hopper', 'email' => 'grace@example.org'],
            ]),
            bus: $this->bus($dispatched),
            exportDir: $this->dir,
            downloadBaseUrl: '/exports',
        );

        $handler(new ExportRequested('Contact', [], 'coordinator@example.org'));

        // One message out, and it is ExportReady rather than a mail send -- the split that lets
        // an SMTP failure retry without rebuilding the file.
        self::assertCount(1, $dispatched);
        $ready = $dispatched[0];
        self::assertInstanceOf(ExportReady::class, $ready);
        self::assertSame('coordinator@example.org', $ready->notifyEmail);
        self::assertSame(2, $ready->rowCount);
        self::assertSame('Contact', $ready->entity);
        self::assertStringStartsWith('/exports/contact-', $ready->downloadUrl);

        $zipPath = $this->dir.'/'.basename($ready->downloadUrl);
        self::assertFileExists($zipPath, 'The link must point at a file that exists.');

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($zipPath));
        self::assertSame(1, $zip->numFiles);
        $csv = $zip->getFromIndex(0);
        $zip->close();

        self::assertStringContainsString('display_name', $csv, 'Header row comes from the first record.');
        self::assertStringContainsString('Ada Lovelace', $csv);
        self::assertStringContainsString('Grace Hopper', $csv);

        self::assertSame([], glob($this->dir.'/*.csv'), 'The loose CSV is cleaned up after zipping.');
    }

    public function testJoinedFieldsAreFlattenedRatherThanBreakingTheCsv(): void
    {
        $dispatched = [];
        $handler = new ExportRequestedHandler(
            civi: $this->civi([[
                'id' => 1,
                'display_name' => 'Ada Lovelace',
                'phones' => [['phone' => '540-555-0100'], ['phone' => '540-555-0101']],
                'is_deceased' => false,
                'middle_name' => null,
            ]]),
            bus: $this->bus($dispatched),
            exportDir: $this->dir,
            downloadBaseUrl: '/exports',
        );

        $handler(new ExportRequested('Contact', [], 'coordinator@example.org'));

        $zip = new \ZipArchive();
        $zip->open($this->dir.'/'.basename($dispatched[0]->downloadUrl));
        $csv = $zip->getFromIndex(0);
        $zip->close();

        self::assertStringContainsString('540-555-0100', $csv, 'A nested array becomes JSON, not "Array".');
        self::assertStringNotContainsString('Array', $csv);
        self::assertSame(2, substr_count(trim($csv), "\n") + 1, 'Header plus one row -- no stray newlines.');
    }

    /** @param list<array<string,mixed>> $rows */
    private function civi(array $rows): CiviCrmClientInterface
    {
        return new class($rows) implements CiviCrmClientInterface {
            /** @param list<array<string,mixed>> $rows */
            public function __construct(private array $rows)
            {
            }

            public function call(string $entity, string $action, array $params = []): array
            {
                return $this->rows;
            }

            public function first(string $entity, string $action, array $params = []): ?array
            {
                return $this->rows[0] ?? null;
            }

            public function count(string $entity, array $params = []): int
            {
                return \count($this->rows);
            }

            public function all(string $entity, array $params = [], int $pageSize = 500): \Generator
            {
                yield from $this->rows;
            }
        };
    }

    /** @param list<object> $dispatched */
    private function bus(array &$dispatched): MessageBusInterface
    {
        return new class($dispatched) implements MessageBusInterface {
            /** @param list<object> $dispatched */
            public function __construct(private array &$dispatched)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->dispatched[] = $message;

                return new Envelope($message);
            }
        };
    }
}
