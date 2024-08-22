<?php

namespace App\Http\Controllers;

use Srmklive\PayPal\Services\PayPal as PayPalClient;
use Illuminate\Http\Request;
use App\Models\Cart;
use App\Models\Product;

class PaypalController extends Controller
{
    public function payment()
    {
        $cart = Cart::where('user_id', auth()->user()->id)
                    ->where('order_id', null)
                    ->get()->toArray();

        $items = array_map(function ($item) {
            $product = Product::find($item['product_id']);
            return [
                'name' => $product->title,
                'unit_amount' => [
                    'currency_code' => 'USD',
                    'value' => $item['price'],
                ],
                'quantity' => $item['quantity'],
            ];
        }, $cart);

        $total = array_reduce($items, function ($carry, $item) {
            return $carry + ($item['unit_amount']['value'] * $item['quantity']);
        }, 0);

        $provider = new PayPalClient;
        $provider->setApiCredentials(config('paypal'));
        $provider->setAccessToken($provider->getAccessToken());

        $order = $provider->createOrder([
            'intent' => 'CAPTURE',
            'purchase_units' => [
                [
                    'amount' => [
                        'currency_code' => 'USD',
                        'value' => $total,
                    ],
                    'items' => $items,
                ],
            ],
            'application_context' => [
                'return_url' => route('payment.success'),
                'cancel_url' => route('payment.cancel'),
            ],
        ]);

        return redirect($order['links'][1]['href']); // Redirect to PayPal
    }

    public function cancel()
    {
        return redirect()->route('home')->with('error', 'Payment was canceled.');
    }

    public function success(Request $request)
    {
        $provider = new PayPalClient;
        $provider->setApiCredentials(config('paypal'));
        $provider->setAccessToken($provider->getAccessToken());

        $response = $provider->capturePaymentOrder($request->query('token'));

        if ($response['status'] === 'COMPLETED') {
            // Handle post-payment actions (e.g., update order status, send receipt)
            return redirect()->route('home')->with('success', 'Payment successful!');
        }

        return redirect()->route('home')->with('error', 'Payment failed.');
    }
}
