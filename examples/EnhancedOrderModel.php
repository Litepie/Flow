<?php

namespace Examples;

use Illuminate\Database\Eloquent\Model;
use Litepie\Flow\Traits\HasWorkflow;
use Litepie\Flow\Traits\HasStateMachine;
use Litepie\Flow\Contracts\Workflowable;

class EnhancedOrderModel extends Model implements Workflowable
{
    use HasWorkflow, HasStateMachine;

    protected $fillable = ['customer_id', 'total', 'state'];

    protected $stateMachines = [
        'state' => EnhancedOrderStateMachine::class,
    ];

    public function getWorkflowName(): string
    {
        return 'order_processing';
    }

    protected function getWorkflowStateColumn(): string
    {
        return 'state';
    }
}
