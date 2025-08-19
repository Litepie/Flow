<?php

namespace Litepie\Flow\tests\Unit;

use PHPUnit\Framework\TestCase;
use Litepie\Flow\Workflows\Workflow;

class WorkflowTest extends TestCase
{
    public function test_workflow_properties()
    {
        $workflow = new Workflow('order', 'Order Workflow');
        $this->assertEquals('order', $workflow->name);
        $this->assertEquals('Order Workflow', $workflow->label);
    }
}
