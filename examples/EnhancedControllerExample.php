<?php

namespace Examples;

use Examples\EnhancedOrderModel;
use Illuminate\Http\Request;

class EnhancedControllerExample
{
    public function processPayment(Request $request, $orderId)
    {
        $order = EnhancedOrderModel::findOrFail($orderId);
        $order->stateMachine('state')->transitionTo('process_payment', [
            'payment_method' => $request->input('payment_method'),
            'amount' => $request->input('amount'),
        ]);
        return response()->json(['status' => 'Payment processed']);
    }

    public function scheduleDelivery($orderId)
    {
        $order = EnhancedOrderModel::findOrFail($orderId);
        $order->scheduleTransition('delivered', now()->addDays(3));
        return response()->json(['status' => 'Delivery scheduled']);
    }
}
