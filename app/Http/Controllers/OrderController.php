<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Shipping;
use App\Models\User;
use PDF;
use Notification;
use Illuminate\Support\Str;
use App\Notifications\StatusNotification;

class OrderController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $orders = Order::latest()->paginate(10);
        return view('backend.order.index', compact('orders'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $this->validate($request, [
            'first_name' => 'required|string',
            'last_name'  => 'required|string',
            'address1'   => 'required|string',
            'address2'   => 'nullable|string',
            'coupon'     => 'nullable|numeric',
            'phone'      => 'required|numeric',
            'post_code'  => 'nullable|string',
            'email'      => 'required|string|email',
        ]);

        $cart = Cart::where('user_id', auth()->id())->whereNull('order_id')->first();

        if (!$cart) {
            return back()->with('error', 'Cart is Empty!');
        }

        $order = new Order();
        $orderData = $request->all();
        $orderData['order_number'] = 'ORD-' . strtoupper(Str::random(10));
        $orderData['user_id'] = auth()->id();
        $orderData['shipping_id'] = $request->shipping;

        $shippingPrice = Shipping::find($orderData['shipping_id'])->value('price');
        $orderData['sub_total'] = Helper::totalCartPrice();
        $orderData['quantity'] = Helper::cartCount();

        if (session('coupon')) {
            $orderData['coupon'] = session('coupon')['value'];
        }

        $orderData['total_amount'] = Helper::totalCartPrice() + ($shippingPrice ?? 0) - ($orderData['coupon'] ?? 0);
        $orderData['payment_method'] = $request->input('payment_method', 'cod');
        $orderData['payment_status'] = $orderData['payment_method'] == 'cod' ? 'Unpaid' : 'Paid';

        $order->fill($orderData);
        $order->save();

        Cart::where('user_id', auth()->id())->whereNull('order_id')->update(['order_id' => $order->id]);

        $admin = User::where('role', 'admin')->first();
        $notificationDetails = [
            'title' => 'New Order Received',
            'actionURL' => route('order.show', $order->id),
            'fas' => 'fa-file-alt',
        ];
        Notification::send($admin, new StatusNotification($notificationDetails));

        if ($orderData['payment_method'] == 'paypal') {
            return redirect()->route('payment')->with(['id' => $order->id]);
        }

        session()->forget(['cart', 'coupon']);
        return redirect()->route('home')->with('success', 'Your product order has been placed. Thank you for shopping with us.');
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $order = Order::findOrFail($id);
        return view('backend.order.show', compact('order'));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $order = Order::findOrFail($id);
        return view('backend.order.edit', compact('order'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $order = Order::findOrFail($id);
        $this->validate($request, [
            'status' => 'required|in:new,process,delivered,cancel',
        ]);

        $order->fill($request->all())->save();

        if ($request->status == 'delivered') {
            foreach ($order->cart as $cartItem) {
                $product = $cartItem->product;
                $product->decrement('stock', $cartItem->quantity);
            }
        }

        return redirect()->route('order.index')->with('success', 'Order successfully updated');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $order = Order::findOrFail($id);
        $order->delete();

        return redirect()->route('order.index')->with('success', 'Order successfully deleted');
    }

    /**
     * Track the order.
     *
     * @return \Illuminate\Http\Response
     */
    public function orderTrack()
    {
        return view('frontend.pages.order-track');
    }

    /**
     * Track the product order.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function productTrackOrder(Request $request)
    {
        $order = Order::where('user_id', auth()->id())->where('order_number', $request->order_number)->first();

        if (!$order) {
            return back()->with('error', 'Invalid order number. Please try again!');
        }

        $statusMessages = [
            'new' => 'Your order has been placed.',
            'process' => 'Your order is currently processing.',
            'delivered' => 'Your order has been delivered. Thank you for shopping with us.',
            'cancel' => 'Sorry, your order has been canceled.',
        ];

        return redirect()->route('home')->with('success', $statusMessages[$order->status] ?? 'Order status not found');
    }

    /**
     * Generate PDF for an order.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function pdf(Request $request)
    {
        $order = Order::findOrFail($request->id);
        $fileName = $order->order_number . '-' . $order->first_name . '.pdf';

        $pdf = PDF::loadView('backend.order.pdf', compact('order'));
        return $pdf->download($fileName);
    }

    /**
     * Get income chart data.
     *
     * @return array
     */
    public function incomeChart()
    {
        $year = now()->year;
        $orders = Order::with('cart_info')
            ->whereYear('created_at', $year)
            ->where('status', 'delivered')
            ->get()
            ->groupBy(function ($date) {
                return \Carbon\Carbon::parse($date->created_at)->format('m');
            });

        $incomeData = array_fill(1, 12, 0);

        foreach ($orders as $month => $orderGroup) {
            $totalAmount = $orderGroup->sum(function ($order) {
                return $order->cart_info->sum('amount');
            });
            $incomeData[intval($month)] = number_format($totalAmount, 2, '.', '');
        }

        $formattedData = [];
        foreach (range(1, 12) as $month) {
            $monthName = date('F', mktime(0, 0, 0, $month, 1));
            $formattedData[$monthName] = $incomeData[$month];
        }

        return $formattedData;
    }
}
