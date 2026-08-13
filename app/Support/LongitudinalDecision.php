<?php

namespace App\Support;

/** Explainable output from the Time/Words longitudinal evaluator. */
final readonly class LongitudinalDecision
{
    /**
     * @param  array<string, int|float|string|null>  $context
     */
    public function __construct(
        public ?string $reason,
        public string $state,
        public array $context = [],
    ) {}

    public function isPending(): bool
    {
        return $this->reason !== null;
    }
}
