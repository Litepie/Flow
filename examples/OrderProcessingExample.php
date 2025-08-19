<?php

namespace Litepie\Flow\examples;

use Litepie\Flow\Workflows\Workflow;
use Litepie\Flow\States\State;
use Litepie\Flow\Transitions\Transition;

class OrderProcessingExample
{
    public static function create(): Workflow
    {
        $workflow = new Workflow('order_processing', 'Order Processing Workflow');

        $pending = new State('pending', 'Pending', true);
        $processing = new State('processing', 'Processing');
        $shipped = new State('shipped', 'Shipped');
        $delivered = new State('delivered', 'Delivered', false, true);

        $workflow->addState($pending)
                 ->addState($processing)
                 ->addState($shipped)
                 ->addState($delivered);

        $processTransition = new Transition('pending', 'processing', 'process');
        $shipTransition = new Transition('processing', 'shipped', 'ship');
        $deliverTransition = new Transition('shipped', 'delivered', 'deliver');

        $workflow->addTransition($processTransition)
                 ->addTransition($shipTransition)
                 ->addTransition($deliverTransition);

        return $workflow;
    }
}
