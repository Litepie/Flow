<?php

namespace Litepie\Flow\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Litepie\Flow\Contracts\Workflowable;

class WorkflowTransitioned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Workflowable $model,
        public string $fromState,
        public string $toState,
        public array $context = []
    ) {}
}
