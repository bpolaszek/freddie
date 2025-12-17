<?php

declare(strict_types=1);

namespace Freddie\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

use function dirname;

/**
 * @codeCoverageIgnore
 */
final class FreddieExtension extends Extension
{
    /**
     * @param array<string, mixed> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator(__DIR__));
        $loader->load(dirname(__DIR__) . '/Resources/config/services.php');
    }
}
