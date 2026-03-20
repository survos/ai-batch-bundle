<?php
declare(strict_types=1);

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Message\Content\ImageUrl;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tacman\AiBatch\Entity\AiBatch;
use Tacman\AiBatch\Model\BatchRequest;
use Tacman\AiBatch\Service\AiBatchBuilder;

/**
 * Demo command: generate programmer-targeted ad copy for dummyjson.com products.
 *
 * Data: Products loaded from data/products.json (originally from dummyjson.com)
 *
 * Synchronous (default — uses symfony/ai PlatformInterface):
 *   bin/console app:advertising --limit=2
 *
 * Batch mode (50% cheaper, async — submits to OpenAI Batch API):
 *   bin/console app:advertising --batch
 *   bin/console app:advertising --batch --limit=10
 */
#[AsCommand('app:ads', 'Generate programmer-targeted ad copy using OpenAI (sync or batch)')]
final class AdvertisingCommand
{
    private const SYSTEM_PROMPT = <<<PROMPT
You are a copywriter who specializes in marketing products to software developers and programmers.
Write punchy, witty advertising copy that speaks to the developer mindset:
references to technical concepts, developer culture, and the joys/pains of programming are welcome.
Keep it under 3 sentences. Be creative and funny.
PROMPT;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PlatformInterface       $platform,
        private readonly AiBatchBuilder         $batchBuilder,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Number of products to process (0 = all 194)')] int $limit = 0,
        #[Option('Use OpenAI Batch API (50% cheaper, results in ~10 min)')] ?bool $batch = null,
        #[Option('Model to use')] string $model = 'gpt-4o-mini',
    ): int {
        $io->title('🛍️  Programmer Ad Copy Generator');

        // Load products from local JSON (originally from dummyjson.com)
        $jsonPath = __DIR__ . '/../../data/products.json';
        $data = json_decode(file_get_contents($jsonPath), true);
        $products = $data['products'];

        if ($limit > 0) {
            $products = array_slice($products, 0, $limit);
        }

        $total = count($products);
        $io->text(sprintf('Loaded %d products from data/products.json (source: dummyjson.com)', $total));

        if ($batch) {
            return $this->runBatch($io, $products, $model);
        }

        return $this->runSync($io, $products, $model);
    }

    // ── Synchronous mode ─────────────────────────────────────────────────────

    private function runSync(SymfonyStyle $io, array $products, string $model): int
    {
        $io->section(sprintf('Synchronous mode — calling OpenAI %d times', count($products)));
        $io->note('For large sets, use --batch for 50% cost reduction and higher rate limits.');
        $io->newLine();

        foreach ($products as $product) {
            $userPrompt = $this->userPrompt($product);

            $response = $this->platform->invoke($model,
                new MessageBag(
                    new SystemMessage(self::SYSTEM_PROMPT),
                    new UserMessage(
                        new Text($userPrompt),
                        new ImageUrl($product['thumbnail'], 'low'),
                    ),
                )
            );

            $copy = $response->asText();

            $io->writeln(sprintf('<info>%s</info> ($%.2f)', $product['title'], $product['price']));
            $io->writeln("  <comment>{$copy}</comment>");
            $io->newLine();
        }

        return Command::SUCCESS;
    }

    // ── Batch mode ────────────────────────────────────────────────────────────

    private function runBatch(SymfonyStyle $io, array $products, string $model): int
    {
        $io->section('Batch mode — submitting to OpenAI Batch API');

        $requestFactory = fn(array $product) => new BatchRequest(
            customId:     'product_' . $product['id'],
            systemPrompt: self::SYSTEM_PROMPT,
            userPrompt:   $this->userPrompt($product),
            model:        $model,
            imageUrl:     $product['thumbnail'],
            options:      ['max_tokens' => 150],
        );

        // Build the batch (writes temp JSONL)
        $aiBatch = $this->batchBuilder->build(
            datasetKey:     'dummyjson/products',
            task:           'advertising_copy',
            records:        $products,
            requestFactory: $requestFactory,
            meta:           ['model' => $model, 'target_audience' => 'programmers'],
        );

        // Submit to OpenAI
        $aiBatch = $this->batchBuilder->submit($aiBatch);

        // Persist so fetch-batch can find it
        $this->em->persist($aiBatch);
        $this->em->flush();

        $io->success(sprintf(
            '%d products submitted to OpenAI Batch API (50%% cost discount applies!).',
            $aiBatch->requestCount
        ));

        $io->table(
            ['Field', 'Value'],
            [
                ['Local batch ID',   $aiBatch->id],
                ['Provider batch ID', $aiBatch->providerBatchId],
                ['Status',           $aiBatch->status],
                ['Requests',         $aiBatch->requestCount],
                ['Est. cost',        sprintf('$%.4f', $aiBatch->requestCount * 0.0004)],
                ['vs sync cost',     sprintf('$%.4f (you save $%.4f)', $aiBatch->requestCount * 0.0008, $aiBatch->requestCount * 0.0004)],
            ]
        );

        $io->note([
            'Results will be ready in ~10 minutes (up to 24h max).',
            'Poll for results with:',
            sprintf('  bin/console app:fetch-batch %d', $aiBatch->id),
        ]);

        return Command::SUCCESS;
    }

    private function userPrompt(array $product): string
    {
        return sprintf(
            'Product: %s (category: %s, price: $%.2f)' . "\n" .
            'Description: %s' . "\n\n" .
            'Write programmer-targeted ad copy for this product.',
            $product['title'],
            $product['category'],
            $product['price'],
            $product['description'],
        );
    }
}
