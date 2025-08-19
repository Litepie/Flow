<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Workflow Configuration
    |--------------------------------------------------------------------------
    |
    | This option controls the default workflow configuration for the Flow
    | package. You can configure default behaviors and settings here.
    |
    */
    'default_workflow' => env('FLOW_DEFAULT_WORKFLOW', null),

    /*
    |--------------------------------------------------------------------------
    | Workflow Storage
    |--------------------------------------------------------------------------
    |
    | Configure how workflows and their states are stored. You can choose
    | between database storage or file-based configurations.
    |
    */
    'storage' => [
        'driver' => env('FLOW_STORAGE_DRIVER', 'database'),
        'table_prefix' => env('FLOW_TABLE_PREFIX', 'flow_'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Event Configuration
    |--------------------------------------------------------------------------
    |
    | Configure event handling for workflow transitions.
    |
    */
    'events' => [
        'enabled' => env('FLOW_EVENTS_ENABLED', true),
        'queue' => env('FLOW_EVENTS_QUEUE', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Registered Workflows
    |--------------------------------------------------------------------------
    |
    | Register your workflows here. Each workflow should have a unique name
    | and can include configuration for states, transitions, and actions.
    |
    */
    'workflows' => [
        // Example workflow configuration
        // 'order_processing' => [
        //     'class' => App\Workflows\OrderProcessingWorkflow::class,
        //     'states' => [
        //         'pending' => ['label' => 'Pending', 'initial' => true],
        //         'processing' => ['label' => 'Processing'],
        //         'shipped' => ['label' => 'Shipped'],
        //         'delivered' => ['label' => 'Delivered', 'final' => true],
        //     ],
        //     'transitions' => [
        //         ['from' => 'pending', 'to' => 'processing', 'event' => 'process'],
        //         ['from' => 'processing', 'to' => 'shipped', 'event' => 'ship'],
        //         ['from' => 'shipped', 'to' => 'delivered', 'event' => 'deliver'],
        //     ],
        // ],
    ],
];
