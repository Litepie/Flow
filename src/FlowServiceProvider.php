<?php

namespace Litepie\Flow;

use Illuminate\Support\ServiceProvider;
use Litepie\Flow\Contracts\WorkflowManagerContract;
use Litepie\Flow\Contracts\StateManagerContract;
use Litepie\Flow\Workflows\WorkflowManager;
use Litepie\Flow\States\StateManager;

use Litepie\Flow\Commands\ProcessPendingTransitions;

class FlowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/flow.php', 'flow');

        $this->app->singleton(WorkflowManagerContract::class, WorkflowManager::class);
        $this->app->singleton(StateManagerContract::class, StateManager::class);
        
        $this->app->alias(WorkflowManagerContract::class, 'flow.workflows');
        $this->app->alias(StateManagerContract::class, 'flow.states');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/flow.php' => config_path('flow.php'),
            ], 'flow-config');

            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'flow-migrations');

            // Register commands
            $this->commands([
                ProcessPendingTransitions::class,
            ]);
        }

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        
        $this->registerWorkflows();
    }

    protected function registerWorkflows(): void
    {
        $workflows = config('flow.workflows', []);
        $workflowManager = $this->app->make(WorkflowManagerContract::class);

        foreach ($workflows as $name => $config) {
            if (isset($config['class'])) {
                $method = $config['method'] ?? 'create';
                $workflow = $config['class']::$method($config);
                $workflowManager->register($name, $workflow);
            }
        }
    }
}
