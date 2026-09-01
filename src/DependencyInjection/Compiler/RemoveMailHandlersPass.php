<?php

declare(strict_types=1);

namespace Survos\CivicrmBundle\DependencyInjection\Compiler;

use Survos\CivicrmBundle\MessageHandler\ExportReadyHandler;
use Survos\CivicrmBundle\MessageHandler\SendCiviEmailHandler;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Drop the mail handlers when there is no mailer to hand them.
 *
 * `interface_exists(MailerInterface::class)` is not the same question. It asks whether the class
 * can be autoloaded; autowiring needs the `mailer` *service*, which FrameworkBundle only
 * registers when `framework.mailer` is configured. symfony/mailer arrives as a transitive
 * dependency in plenty of projects that never configure it, and in those the container would
 * fail to compile with an error about our handler -- an error about a bundle the user has not
 * touched, for a feature they are not using.
 *
 * That question can only be answered after every extension has loaded, which is what a compiler
 * pass is for.
 */
final class RemoveMailHandlersPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($this->hasMailer($container)) {
            return;
        }

        foreach ([ExportReadyHandler::class, SendCiviEmailHandler::class] as $id) {
            $container->removeDefinition($id);
        }
    }

    private function hasMailer(ContainerBuilder $container): bool
    {
        foreach (['mailer', 'mailer.mailer', MailerInterface::class] as $id) {
            if ($container->hasDefinition($id) || $container->hasAlias($id)) {
                return true;
            }
        }

        return false;
    }
}
