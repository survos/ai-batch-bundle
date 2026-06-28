<?php
declare(strict_types=1);

namespace Tacman\AiBatch;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Async batch AI processing for Symfony.
 *
 * Proposed for symfony/ai — see BatchCapablePlatformInterface.
 *
 * Quick start:
 *   1. Register OpenAiBatchClient (inject OPENAI_API_KEY)
 *   2. Run bin/console messenger:consume scheduler_default  ← polls every 2 min
 *   3. Use AiBatchBuilder::build() + submit() to queue work
 *   4. Results arrive in ApplyBatchResultsMessage handler
 */
class TacmanAiBatchBundle extends AbstractBundle
{
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services()->defaults()->autowire()->autoconfigure();

        $services->set(\Tacman\AiBatch\Service\OpenAiBatchClient::class)
            ->arg('$apiKey', '%env(OPENAI_API_KEY)%')
            ->tag('tacman.ai_batch.client');

        $services->set(\Tacman\AiBatch\Service\AnthropicBatchClient::class)
            ->arg('$apiKey', '%env(default::ANTHROPIC_API_KEY)%')
            ->tag('tacman.ai_batch.client');

        $services->alias(\Tacman\AiBatch\Contract\BatchCapablePlatformInterface::class, \Tacman\AiBatch\Service\OpenAiBatchClient::class);

        $services->set(\Tacman\AiBatch\Service\AiBatchBuilder::class);
        $services->set(\Tacman\AiBatch\Scheduler\PollBatchesTask::class);

        // Register the bundle's console commands so `ai:batch:*` are available when the bundle is
        // consumed by another app (the ai-batch app picked these up via its own App\ autowiring,
        // which is why the missing registration only surfaced in consumer apps like md).
        $services->load('Tacman\\AiBatch\\Command\\', \dirname(__DIR__) . '/src/Command/');

        if (class_exists(\Survos\TablerBundle\Event\MenuEvent::class)) {
            $services->set(\Tacman\AiBatch\Menu\AiBatchMenuSubscriber::class)
                ->autowire()
                ->autoconfigure();
        }
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Register AiBatch entity if Doctrine is available
        if ($builder->hasExtension('doctrine')) {
            $builder->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => [
                        'TacmanAiBatch' => [
                            'is_bundle' => false,
                            'type'      => 'attribute',
                            'dir'       => \dirname(__DIR__) . '/src/Entity',
                            'prefix'    => 'Tacman\\AiBatch\\Entity',
                            'alias'     => 'TacmanAiBatch',
                        ],
                    ],
                ],
            ]);
        }
    }
}
