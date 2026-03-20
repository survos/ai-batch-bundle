<?php
declare(strict_types=1);

namespace Tacman\AiBatch\Message;

/** Dispatched when a batch completes — triggers result application. */
final class ApplyBatchResultsMessage
{
    public function __construct(
        public readonly int $aiBatchId,
    ) {}
}
