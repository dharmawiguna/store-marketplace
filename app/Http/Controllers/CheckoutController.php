<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

use App\Cart;
use App\Transaction;
use App\TransactionDetail;

use Exception;

use Midtrans\Snap;
use Midtrans\Config;
use Midtrans\Notification;


class CheckoutController extends Controller
{
    public function process(Request $request)
    {
    	//save user data
    	$user = Auth::user();
    	$user->update($request->except('total_price'));

    	//proses checkout
    	$code = 'STORE-' . mt_rand(000000,999999);
    	$carts = Cart::with(['product','user'])
    			->where('users_id', Auth::user()->id)
    			->get();

    	// dd(Auth::user()->id);

    	// Transaction create
    	$transaction = Transaction::create([
    		'users_id' => Auth::user()->id,
    		'insurance_price' => 0,
    		'shipping_price' => 0,
    		'total_price' => $request->total_price,
    		'transaction_status' => 'PENDING',
    		'code' => $code,
    	]);

    	foreach ($carts as $cart ) {
    		$trx = 'TRX-' . mt_rand(000000,999999);

    		TransactionDetail::create([
	    		'transactions_id' => $transaction->id,
	    		'products_id' => $cart->product->id,
	    		'price' => $cart->product->price,
	    		'shipping_status' => 'PENDING',
	    		'resi' => '',
	    		'code' => $trx,
    		]);
    	}

    	//delete cart
    	Cart::with(['product', 'user'])
    		->where('users_id', Auth::user()->id)->delete();

    	//Konigurasi Midtrans
    	Config::$serverKey = config('services.midtrans.serverKey');		
		Config::$isProduction = config('services.midtrans.isProduction');
		Config::$isSanitized = config('services.midtrans.isSanitized');
		Config::$is3ds = config('services.midtrans.is3ds');

		//create array for send to midtrans
		$midtrans = [
			'transaction_details' => [
				'order_id' => $code,
				'gross_amount' => (int) $request->total_price,
			],

			'customer_details' => [
				'first_name' => Auth::user()->name,
				'email' => Auth::user()->email,
			],
			'enabled_payments' => [
				'gopay', 'bank_transfer', 'permata_va'
			],
			'vtweb' => [],
		];

		try {
		  // Get Snap Payment Page URL
		  $paymentUrl = Snap::createTransaction($midtrans)->redirect_url;
		  
		  // Redirect to Snap Payment Page
		  return redirect($paymentUrl);
		}
		catch (Exception $e) {
		  echo $e->getMessage();
		}

    }

    public function callback(Request $request)
    {
    	//set configurasi Midtrans
    	Config::$serverKey = config('services.midtrans.serverKey');
    	Config::$isProduction = config('services.midtrans.isProduction');
    	Config::$isSanitized = config('services.midtrans.isSanitized');
    	Config::$is3ds = config('services.midtrans.is3ds');

    	//instance midtrans notification
    	$notification = new Notification();

    	//assign ke variable untuk memudahkan koding
    	$status = $notification->transaction_status;
    	$type = $notification->payment_type;
    	$fraud = $notification->fraud_status;
    	$order_id = $notification->order_id;

    	//cari transaksi berdasarkan id
    	$transaction = Transaction::findOrFail($order_id);

    	//handle notifitacion status
    	if ($status == 'capture') {
    		if ($type == 'credit_card') {
    			if ($fraud == 'challenge') {
    				$transaction->status = 'PENDING';
    			}
    			else{
    				$transaction->status = 'SUCCESS';
    			}
    		}
    	}

    	else if($status == 'settlement'){
    		$transaction->status = 'SUCCESS';
    	}

    	else if($status == 'pending'){
    		$transaction->status = 'PENDING';
    	}

    	else if($status == 'deny'){
    		$transaction->status = 'CANCELLED';
    	}

    	else if($status == 'expired'){
    		$transaction->status = 'CANCELLED';
    	}

    	else if($status == 'cancel'){
    		$transaction->status = 'CANCELLED';
    	}

    	//simpat transaksi
    	$transcation->save();

    	
    }
}
