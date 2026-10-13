<?php

declare(strict_types=1);

namespace Survos\CivicrmBundle;

use Survos\Civicrm\Client\CiviCrmClient;
use Survos\Civicrm\Contract\CiviCrmClientInterface;
use Survos\CivicrmBundle\DependencyInjection\Compiler\RemoveMailHandlersPass;
use Survos\CivicrmBundle\MessageHandler\ExportReadyHandler;
use Survos\CivicrmBundle\MessageHandler\ExportRequestedHandler;
use Survos\CivicrmBundle\MessageHandler\SendCiviEmailHandler;
use Survos\Kit\AbstractSurvosBundle;
use Survos\Kit\SurvosKitBundle;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;

#[RequiredBundle(SurvosKitBundle::class)]
// Symfony\Component\HttpKernel\Bundle\Bundle <-- Flex auto-registration marker (see Survos\Kit\AbstractSurvosBundle)
final class SurvosCivicrmBundle extends AbstractSurvosBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('base_url')
                    ->info('Site root, e.g. https://rah.villagedesk.org -- not the API path.')
                    ->isRequired()
                ->end()
                ->scalarNode('api_key')
                    ->info('CiviCRM API key, sent as X-Civi-Auth: Bearer. Keep it out of URLs.')
                    ->isRequired()
                ->end()
                ->scalarNode('from_address')->defaultNull()->end()
                ->scalarNode('from_name')->defaultValue('VillageDesk')->end()
                ->scalarNode('export_dir')->defaultValue('%kernel.project_dir%/var/exports')->end()
                ->scalarNode('download_base_url')->defaultValue('/exports')->end()
                ->integerNode('max_attempts')
                    ->info('Attempts for read actions only; writes are never retried.')
                    ->defaultValue(3)->min(1)
                ->end()
                ->integerNode('base_delay_ms')
                    ->info('First backoff step, doubling with full jitter.')
                    ->defaultValue(200)->min(0)
                ->end()
            ->end();
    }

    /** @param array<string,mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::loadExtension($config, $container, $builder);

        $services = $container->services()->defaults()->autowire()->autoconfigure();

        $services->set(CiviCrmClient::class)
            ->arg('$http', new Reference('http_client'))
            ->arg('$baseUrl', $config['base_url'])
            ->arg('$apiKey', $config['api_key'])
            ->arg('$logger', new Reference('logger'))
            ->arg('$maxAttempts', $config['max_attempts'])
            ->arg('$baseDelayMs', $config['base_delay_ms']);

        $services->alias(CiviCrmClientInterface::class, CiviCrmClient::class);

        $services->set(ExportRequestedHandler::class)
            ->arg('$exportDir', $config['export_dir'])
            ->arg('$downloadBaseUrl', $config['download_base_url']);

        // Two separate guards, for two separate failures. This one covers symfony/mailer not
        // being installed at all, where merely naming the handler class would break autowiring.
        // RemoveMailHandlersPass covers the commoner case: the package is present (often
        // transitively) but `framework.mailer` was never configured, so there is no service.
        if (interface_exists(\Symfony\Component\Mailer\MailerInterface::class)) {
            $services->set(ExportReadyHandler::class)
                ->arg('$fromAddress', $config['from_address'] ?? 'no-reply@localhost')
                ->arg('$fromName', $config['from_name']);

            $services->set(SendCiviEmailHandler::class)
                ->arg('$fromAddress', $config['from_address'] ?? 'no-reply@localhost')
                ->arg('$fromName', $config['from_name']);
        }
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Priority 10, not the default 0. Passes of equal priority run in bundle-registration
        // order, and FrameworkBundle registers first -- so at priority 0 MessengerPass would
        // already have collected the handler into its locator, and removing the definition
        // afterwards leaves a dangling reference rather than a clean absence.
        $container->addCompilerPass(new RemoveMailHandlersPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 10);
    }
}
