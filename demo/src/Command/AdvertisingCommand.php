<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\AI\Platform\Message\Content\ImageUrl;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand('app:ads', 'Generate programmer-targeted ad copy using OpenAI (sync or native batch)')]
final class AdvertisingCommand
{
    private const string SYSTEM_PROMPT = 'Write punchy, witty advertising copy for software developers. Use developer culture and technical references. Keep it under three sentences.';

    public function __construct(
        private readonly PlatformInterface $platform,
        private readonly Filesystem $filesystem,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Number of products to process (0 = all)')] int $limit = 2,
        #[Option('Submit a native asynchronous OpenAI batch')] bool $batch = false,
        #[Option('Model to use')] string $model = 'gpt-4o-mini',
    ): int {
        if ($limit < 0) {
            $io->error('The limit must be zero or positive.');

            return Command::INVALID;
        }

        $data = json_decode(file_get_contents($this->projectDir.'/data/products.json'), true, flags: JSON_THROW_ON_ERROR);
        $products = $limit > 0 ? array_slice($data['products'], 0, $limit) : $data['products'];
        if ([] === $products) {
            $io->error('No products to process.');

            return Command::INVALID;
        }

        $inputs = [];
        foreach ($products as $product) {
            $inputs['product_'.$product['id']] = new MessageBag(
                new SystemMessage(self::SYSTEM_PROMPT),
                new UserMessage(
                    new Text(sprintf("Product: %s (%s, $%.2f)\nDescription: %s", $product['title'], $product['category'], $product['price'], $product['description'])),
                    new ImageUrl($product['thumbnail'], 'low'),
                ),
            );
        }

        if (!$batch) {
            foreach ($inputs as $id => $input) {
                $io->writeln($id.': '.$this->platform->invoke($model, $input)->asText());
            }

            return Command::SUCCESS;
        }

        // The same MessageBags go through the bridge's normalizer. Symfony handles JSONL upload.
        $handle = $this->platform->invoke($model, $inputs, ['batch' => true])->asJob();
        $path = $this->projectDir.'/var/batches/'.rawurlencode($handle->getId()).'.json';
        // Print the complete handle before writing, so a storage failure does not lose the job.
        $io->writeln($handle->toString());
        $this->filesystem->dumpFile($path, $handle->toString()."\n");
        $io->success(sprintf('Submitted %d products. Saved job handle to %s', count($inputs), $path));
        $io->note('Fetch later with: bin/console app:fetch-batch '.escapeshellarg($path));

        return Command::SUCCESS;
    }
}
