<?php
declare(strict_types=1);

namespace Tacman\AiBatch;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Survos\Kit\AbstractSurvosBundle;
use Survos\Kit\SurvosKitBundle;
use Survos\Kit\Traits\HasDoctrineEntities;
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;

/**
 * Async batch AI processing for Symfony.
 *
 * Persistence and orchestration around Symfony AI's native jobs.
 * The legacy clients remain available for existing provider jobs and archives.
 *
 * Quick start:
 *   1. Register OpenAiBatchClient (inject OPENAI_API_KEY)
 *   2. Run bin/console messenger:consume scheduler_default  ← polls every 2 min
 *   3. Use AiBatchBuilder::build() + submit() to queue work
 *   4. Results arrive in ApplyBatchResultsMessage handler
 */
#[RequiredBundle(SurvosKitBundle::class)]
// Symfony\Component\HttpKernel\Bundle\Bundle <-- Flex auto-registration marker (see Survos\Kit\AbstractSurvosBundle)
class TacmanAiBatchBundle extends AbstractSurvosBundle
{
    use HasDoctrineEntities {
        prependDoctrineMapping as private prependBatchDoctrineMapping;
    }

    protected function prependDoctrineMapping(ContainerBuilder $builder): void
    {
        if ($builder->hasExtension('doctrine')) {
            $this->prependBatchDoctrineMapping($builder);
        }
    }

    protected function doctrineMappingName(): string
    {
        return 'TacmanAiBatch';
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::loadExtension($config, $container, $builder);

        $services = $container->services()->defaults()->autowire()->autoconfigure();

        $services->set(\Tacman\AiBatch\Service\OpenAiBatchClient::class)
            ->arg('$apiKey', '%env(OPENAI_API_KEY)%')
            ->tag('tacman.ai_batch.client', ['provider' => 'openai']);

        $services->set(\Tacman\AiBatch\Service\AnthropicBatchClient::class)
            ->arg('$apiKey', '%env(string:default::ANTHROPIC_API_KEY)%')
            ->tag('tacman.ai_batch.client', ['provider' => 'anthropic']);

        $services->set(\Tacman\AiBatch\Service\MistralBatchClient::class)
            ->arg('$apiKey', '%env(string:default::MISTRAL_API_KEY)%')
            ->tag('tacman.ai_batch.client', ['provider' => 'mistral']);

        // provider name (AiBatch.provider) → client
        $services->set(\Tacman\AiBatch\Service\BatchClients::class);

        $services->alias(\Tacman\AiBatch\Contract\BatchCapablePlatformInterface::class, \Tacman\AiBatch\Service\OpenAiBatchClient::class);

        $services->set(\Tacman\AiBatch\Service\AiBatchBuilder::class);
        $services->set('tacman.ai_batch.native_openai_platform', \Symfony\AI\Platform\Platform::class)
            ->factory([\Symfony\AI\Platform\Bridge\OpenAi\Factory::class, 'createPlatform'])
            ->args(['%env(OPENAI_API_KEY)%']);
        $services->set('tacman.ai_batch.native_openai_job_client', \Symfony\AI\Platform\Bridge\OpenAi\Batch\JobClient::class)
            ->factory([\Symfony\AI\Platform\Bridge\OpenAi\Factory::class, 'createJobClient'])
            ->args(['%env(OPENAI_API_KEY)%']);
        $services->set(\Tacman\AiBatch\Service\NativeOpenAiBatchService::class)
            ->arg('$platform', new \Symfony\Component\DependencyInjection\Reference('tacman.ai_batch.native_openai_platform'))
            ->arg('$client', new \Symfony\Component\DependencyInjection\Reference('tacman.ai_batch.native_openai_job_client'));
        $services->set(\Tacman\AiBatch\Scheduler\PollBatchesTask::class);

        if (class_exists(\Survos\TablerBundle\Event\MenuEvent::class)) {
            $services->set(\Tacman\AiBatch\Menu\AiBatchMenuSubscriber::class)
                ->autowire()
                ->autoconfigure();
        }
    }
}
