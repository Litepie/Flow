<?php

namespace Litepie\Flow\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Litepie\Flow\Workflows\Workflow create(string $name, array $config = [])
 * @method static \Litepie\Flow\Workflows\Workflow get(string $name)
 * @method static bool has(string $name)
 * @method static array all()
 * @method static void register(string $name, \Litepie\Flow\Workflows\Workflow $workflow)
 */
class Flow extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Litepie\Flow\Contracts\WorkflowManagerContract::class;
    }
}
