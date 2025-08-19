<?php

namespace Litepie\Flow\Commands;

use Illuminate\Console\Command;
use Litepie\Flow\Models\PendingTransition;

class ProcessPendingTransitions extends Command
{
    protected $signature = 'flow:process-pending-transitions';
    protected $description = 'Process all scheduled pending transitions.';

    public function handle()
    {
        $pending = PendingTransition::where('status', 'pending')
            ->where('scheduled_for', '<=', now())
            ->get();
        foreach ($pending as $transition) {
            // ... process transition logic ...
            $transition->status = 'processed';
            $transition->save();
        }
        $this->info('Processed ' . $pending->count() . ' pending transitions.');
    }
}
