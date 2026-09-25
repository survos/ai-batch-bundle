<?php

declare(strict_types=1);

namespace Tacman\AiBatch\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Tacman\AiBatch\TacmanAiBatchBundle;

final class BundleRegistrationTest extends TestCase
{
    public function testCommandsAreRegisteredByKit(): void
    {
        $builder = new ContainerBuilder();
        (new TacmanAiBatchBundle())->loadExtension([], $this->configurator($builder), $builder);

        foreach (glob(dirname(__DIR__).'/src/Command/*.php') as $file) {
            self::assertTrue($builder->hasDefinition('Tacman\\AiBatch\\Command\\'.basename($file, '.php')));
        }
    }

    public function testDoctrineRemainsOptional(): void
    {
        $builder = new ContainerBuilder();
        (new TacmanAiBatchBundle())->prependExtension($this->configurator($builder), $builder);

        self::assertSame([], $builder->getExtensionConfig('doctrine'));
    }

    public function testDoctrineMappingKeepsItsExistingNameAndAlias(): void
    {
        $builder = new ContainerBuilder();
        $builder->registerExtension(new class extends Extension {
            public function getAlias(): string
            {
                return 'doctrine';
            }

            public function load(array $configs, ContainerBuilder $container): void
            {
            }
        });
        (new TacmanAiBatchBundle())->prependExtension($this->configurator($builder), $builder);

        $mapping = $builder->getExtensionConfig('doctrine')[0]['orm']['mappings']['TacmanAiBatch'];
        self::assertSame('TacmanAiBatch', $mapping['alias']);
        self::assertSame('Tacman\\AiBatch\\Entity', $mapping['prefix']);
        self::assertSame(dirname(__DIR__).'/src/Entity', $mapping['dir']);
    }

    private function configurator(ContainerBuilder $builder): ContainerConfigurator
    {
        $instanceof = [];

        return new ContainerConfigurator($builder, new PhpFileLoader($builder, new FileLocator()), $instanceof, __DIR__, __FILE__);
    }
}
