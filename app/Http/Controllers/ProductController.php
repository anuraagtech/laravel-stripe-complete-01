<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use Stripe\StripeClient;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ProductController extends Controller
{
  public function index(Request $request)
  {
    $products = Product::all();
    // print_r(compact('products'));
    // dd($products);
    return view('product.index', compact('products'));
  }

  public function checkout()  // post method
  {
    // dd('Hello');
    $stripe = new StripeClient(env('STRIPE_SECRET_KEY'));

    $products = Product::all();
    $lineItems = [];
    $totalPrice = 0;
    foreach ($products as $product) {
      $totalPrice += $product->price;
      $lineItems[] = [
        'price_data' => [
          'currency' => 'inr',
          'product_data' => [
            'name' => $product->name,
            // 'images'=> [$product->image]
          ],
          'unit_amount' => $product->price * 100,
        ],
        'quantity' => 1,
      ];
    }

    $checkout_session = $stripe->checkout->sessions->create([
      'line_items' => $lineItems,
      'mode' => 'payment',
      // absolute url is required, because the redirect will happen from stripe domain to our domain
      // while anyone can access the success url, a session id is required to implement proper accessibility
      'success_url' => route('checkout.success', [], true) . "?session_id={CHECKOUT_SESSION_ID}",
      'cancel_url' => route('checkout.cancel', [], true),
    ]);

    $order = new Order();
    $order->status = 'unpaid';
    $order->total_price = $totalPrice;
    $order->session_id = $checkout_session->id;
    $order->save();


    return redirect($checkout_session->url);
  }

  public function success()
  {

    // while anyone can access the success url, a session id is required to implement proper accessibility

    // $sessionId = $request->get('session_id ');
    $stripe = new StripeClient(env('STRIPE_SECRET_KEY'));
    // $session = $stripe->checkout->sessions->retrieve($_GET['session_id']);
    // print_r($session->customer_details->toArray());
    // dd($session->customer_details);
    // if (!$session) {
    //   throw new NotFoundHttpException;
    // }
    // $customer = $stripe->customers->retrieve($session->customer_details);
    // $customer = $session->customer_details->toArray();
    // print_r($customer);
    // dd($customer);

    // some codes provided for php in the stripe documentation is not working in laravel way

    try {

      $session = $stripe->checkout->sessions->retrieve($_GET['session_id']);
      $customer = $session->customer_details->toArray();

      // before a success page is shown, and order can be marked as paid...
      // if flow breaks for some reason, (order is unpaid in our db but succeded in stripe server) stripe supports webhooks for this case
      // webhooks are http requests sent to our server for certain type of events
      // when customer pays the invoice then stripe sends the webhook to us, then we can update the customer
      // https://dashboard.stripe.com/test/webhooks/create
      // for staging or production server - use 'Add an endpoint' -or- test in local environment using stripe cli
      // https://stripe.com/docs/stripe-cli 
      // webhook request method should be post

      // ------------- below code will run after webhook request ------------------

      // after a success page is shown, order is marked as paid, then further refresh of success page is handled here
      // $order = Order::where('session_id', $_GET['session_id'])->where('status', 'unpaid')->first(); \\ if no webhook used

      // --- success page further refresh to be handled ---
      $order = Order::where('session_id', $_GET['session_id'])->first(); //\\ if webhook is used - status will we paid
      if (!$order) {
        throw new NotFoundHttpException();
      }
      if ($order->status === 'unpaid') {
        $order->status = 'paid';
        $order->save();
      }
  

      return view('product.checkout-success')->with($customer);
    } catch (\Exception $e) {
      throw new NotFoundHttpException();
    }
  }

  public function cancel()
  {
  }

  public function webhook()
  {
    // to listen to stripe webhooks, sent to us - when payment events takes place - for checkout process
    // https://docs.google.com/document/d/1gN9u9omTU-Ns1afQ4p-Fh8W759J-g00w
    // after settiing up stripe cli locally, we can get webhook requests for triggered events in our localhost/webhook route

    // ----------------- from stripe docs --------------------------
    // The library needs to be configured with your account's secret key.
    // Ensure the key is kept out of any version control system you might be using.
    $stripe = new StripeClient(env('STRIPE_SECRET_KEY'));

    // This is your Stripe CLI webhook secret for testing your endpoint locally.
    $endpoint_secret = env('STRIPE_ENDPOINT_SECRET');

    $payload = @file_get_contents('php://input');
    // \\ sig_header requires time in local to be correct 
    $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
    $event = null;

    try {
      $event = \Stripe\Webhook::constructEvent(
        $payload,
        $sig_header,
        $endpoint_secret
      );
    } catch (\UnexpectedValueException $e) {
      // // Invalid payload
      // http_response_code(400);
      // exit();
      return response('', 400);
    } catch (\Stripe\Exception\SignatureVerificationException $e) {
      // // Invalid signature
      // http_response_code(400);
      // exit();
      return response('', 400);
    }

    // Handle the event \\ can be used for and event type 
    //\\ "checkout.session.completed" happens - on success & before redirect to success page
    //\\ we can use this webhook to mark our order paid , if flow breaks after successfull payment
    switch ($event->type) {
      case 'checkout.session.completed':
        $paymentIntent = $event->data->object;
        // "checkout.session.completed" - session id is returned - we can use this to handle any flow break - on successful payment
        $sessionId = $paymentIntent->id; 
        //\\ by default - first webhook makes request and then redirect happens

        $order = Order::where('session_id', $sessionId)->first();
        if ($order && $order->status === 'unpaid') {
          $order->status = 'paid';
          $order->save();
          // success page refresh to be handled
          // send email to customer
        }

        // ... handle other event types
      default:
        echo 'Received unknown event type ' . $event->type;
    }

    // http_response_code(200);
    return response('', 200);
  }
}
