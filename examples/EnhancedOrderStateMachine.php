<?php

namespace Examples;

use Litepie\Flow\StateMachine\AbstractStateMachine;

class EnhancedOrderStateMachine extends AbstractStateMachine
{
    public function transitions(): array
    {
        return [
            'process_payment' => [
                'from' => 'pending',
                'to' => 'paid',
                'label' => 'Process Payment',
                'icon' => 'credit-card',
                'color' => 'success',
                'form' => [
                    'fields' => [
                        'payment_method' => [
                            'type' => 'select',
                            'label' => 'Payment Method',
                            'required' => true,
                            'options' => [
                                'credit_card' => 'Credit Card',
                                'paypal' => 'PayPal'
                            ]
                        ],
                        'amount' => [
                            'type' => 'number',
                            'label' => 'Amount',
                            'required' => true
                        ]
                    ]
                ]
            ],
            'ship_order' => [
                'from' => 'paid',
                'to' => 'shipped',
                'label' => 'Ship Order'
            ]
        ];
    }

    public function actions(): array
    {
        return [
            'add_note' => [
                'states' => '*',
                'label' => 'Add Note',
                'form' => [
                    'fields' => [
                        'note' => [
                            'type' => 'textarea',
                            'required' => true
                        ]
                    ]
                ]
            ]
        ];
    }

    public function stateLabels(): array
    {
        return [
            'pending' => 'Pending Payment',
            'paid' => 'Payment Confirmed',
            'shipped' => 'Shipped'
        ];
    }
}
