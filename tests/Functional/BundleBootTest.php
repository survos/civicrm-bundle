<?php

declare(strict_types=1);

namespace Survos\CivicrmBundle\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Survos\Civicrm\Client\CiviCrmClient;
use Survos\CivicrmBundle\Command\CiviCrmCommands;
use Survos\Civicrm\Contract\CiviCrmClientInterface;
use Survos\CivicrmBundle\MessageHandler\ExportReadyHandler;
use Survos\CivicrmBundle\MessageHandler\ExportRequestedHandler;
use Survos\CivicrmBundle\MessageHandler\SendCiviEmailHandler;
use Survos\CivicrmBundle\SurvosCivicrmBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

/**
 * Does the bundle install?
 *
 * Unit tests prove the client works when you construct it by hand. This proves the part a user
 * actually experiences: drop the bundle in, set two config values, and get a working service.
 * Container wiring fails at boot, loudly, and only in a real kernel.
 */
final class BundleBootTest extends TestCase
{
    public function testTheContainerBootsAndTheClientIsAutowirable(): void
    {
        $container = $this->boot()->getContainer()->get('test.service_container');

        $civi = $container->get(CiviCrmClientInterface::class);
        self::assertInstanceOf(CiviCrmClient::class, $civi);

        self::assertTrue($container->has(ExportRequestedHandler::class));
        self::assertTrue($container->has(CiviCrmCommands::class));
    }

    public function testTheExportCommandIsRegisteredByItsAttribute(): void
    {
        $app = new \Symfony\Bundle\FrameworkBundle\Console\Application($this->boot());

        foreach (['civicrm:count', 'civicrm:peek', 'civicrm:export'] as $name) {
            self::assertTrue($app->has($name), sprintf('%s should be registered', $name));
        }
    }

    public function testWithoutAConfiguredMailerTheMailHandlersAreDroppedRatherThanBreakingTheBuild(): void
    {
        $container = $this->boot()->getContainer()->get('test.service_container');

        // framework.mailer is explicitly disabled, so there is no `mailer` service, and the
        // handlers must be removed rather than left dangling. The API client still works.
        self::assertFalse($container->has(ExportReadyHandler::class));
        self::assertFalse($container->has(SendCiviEmailHandler::class));
        self::assertTrue($container->has(CiviCrmClientInterface::class));
    }

    public function testWithAConfiguredMailerTheMailHandlersAreRegistered(): void
    {
        $container = $this->boot(mailer: true)->getContainer()->get('test.service_container');

        self::assertTrue($container->has(ExportReadyHandler::class), 'The pass must not be over-eager.');
        self::assertTrue($container->has(SendCiviEmailHandler::class));
    }

    public function testARequiredConfigValueIsActuallyRequired(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/api_key/');

        $this->boot(['base_url' => 'https://example.org'])->getContainer();
    }

    /** @param array<string,mixed>|null $config */
    private function boot(?array $config = null, bool $mailer = false): Kernel
    {
        $kernel = new class($config ?? [
            'base_url' => 'https://rah.example.org',
            'api_key' => 'test-key',
            'from_address' => 'no-reply@example.org',
        ], $mailer) extends Kernel {
            /** @param array<string,mixed> $civiConfig */
            public function __construct(private readonly array $civiConfig, private readonly bool $withMailer)
            {
                parent::__construct('test', true);
            }

            public function registerBundles(): iterable
            {
                return [new FrameworkBundle(), new SurvosCivicrmBundle()];
            }

            public function registerContainerConfiguration(LoaderInterface $loader): void
            {
                $loader->load(function (ContainerBuilder $c): void {
                    $c->loadFromExtension('framework', [
                        'test' => true,
                        'http_method_override' => false,
                        'handle_all_throwables' => true,
                        'php_errors' => ['log' => true],
                        'messenger' => ['transports' => ['async' => 'in-memory://']],
                        // Explicit either way. FrameworkBundle enables the mailer section by
                        // default whenever symfony/mailer is installed, so "no mailer" has to be
                        // stated outright -- otherwise this test asserts what happens to be in
                        // vendor rather than what the compiler pass does.
                        'mailer' => $this->withMailer ? ['dsn' => 'null://null'] : ['enabled' => false],
                    ]);
                    $c->loadFromExtension('survos_civicrm', $this->civiConfig);
                });
            }

            public function getCacheDir(): string
            {
                return sys_get_temp_dir() . '/civi-bundle-test/' . spl_object_hash($this) . '/cache';
            }

            public function getLogDir(): string
            {
                return sys_get_temp_dir() . '/civi-bundle-test/' . spl_object_hash($this) . '/log';
            }
        };

        $kernel->boot();

        return $kernel;
    }
}
