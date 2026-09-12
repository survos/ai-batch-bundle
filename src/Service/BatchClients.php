<?php
declare(strict_types=1);

namespace Tacman\AiBatch\Service;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Tacman\AiBatch\Contract\BatchCapablePlatformInterface;

/**
 * The batch client for a provider name ('openai', 'anthropic', 'mistral') -- what an AiBatch row
 * records in `provider`, so polling and applying never hard-code one client.
 */
final class BatchClients
{
    public function __construct(
        #[AutowireLocator('tacman.ai_batch.client', indexAttribute: 'provider')]
        private readonly ContainerInterface $clients,
    ) {}

    public function has(string $provider): bool
    {
        return $this->clients->has($provider);
    }

    public function get(string $provider): BatchCapablePlatformInterface
    {
        if (!$this->clients->has($provider)) {
            throw new \InvalidArgumentException(sprintf('No batch client for provider "%s".', $provider));
        }

        return $this->clients->get($provider);
    }
}
