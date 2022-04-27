<?php

namespace App\Http\Controllers\Api\V1\Payment\Paystack;

use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Base\Constants\Auth\Role;
use App\Http\Controllers\ApiController;
use App\Models\Payment\UserWalletHistory;
use App\Models\Payment\DriverWalletHistory;
use App\Transformers\Payment\WalletTransformer;
use App\Transformers\Payment\DriverWalletTransformer;
use App\Http\Requests\Payment\AddMoneyToWalletRequest;
use App\Transformers\Payment\UserWalletHistoryTransformer;
use App\Transformers\Payment\DriverWalletHistoryTransformer;
use App\Models\Payment\UserWallet;
use App\Models\Payment\DriverWallet;
use App\Base\Constants\Masters\WalletRemarks;
use App\Base\Constants\Setting\Settings;

/**
 * @group Paystack Payment Gateway
 *
 * Payment-Related Apis
 */
class PaystackController extends ApiController
{

    /**
     * Initialize Payment
     * 
     * 
     * 
     * */
    public function initialize(Request $request){

        $paystack_initialize_url = 'https://api.paystack.co/transaction/initialize';

        if(get_settings(Settings::PAYSTACK_ENVIRONMENT)=='test'){

            $secret_key = get_settings(Settings::PAYSTACK_TEST_SECRET_KEY);

        }else{

            $secret_key = get_settings(Settings::PAYSTACK_PRODUCTION_SECRET_KEY);

        }
        $headers = [
            'Authorization:Bearer '.$secret_key,
            'Content-Type:application/json'
            ];

        $customer_email = auth()->user()->email;

        $amount = $request->amount;

        $query = [
            'email'=> $customer_email,
            'amount'=>$request->amount
            ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $paystack_initialize_url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($query));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $result = curl_exec($ch);


        if ($result) {
            $result = json_decode($result);
            return response()->json($result);
        }

        return $this->respondFailed();



    }

    /**
    * Add money to wallet
    * @bodyParam amount double required  amount entered by user
    * @bodyParam payment_id string required  payment_id from transaction
    * @response {
    "success": true,
    "message": "money_added_successfully",
    "data": {
        "id": "1195a787-ba13-4a74-b56c-c48ba4ca0ca0",
        "user_id": 15,
        "amount_added": 2500,
        "amount_balance": 2500,
        "amount_spent": 0,
        "currency_code": "INR",
        "created_at": "1st Sep 10:45 PM",
        "updated_at": "1st Sep 10:51 PM"
    }
}
    */
    public function addMoneyToWallet(AddMoneyToWalletRequest $request)
    {
        
        $user_currency_code = auth()->user()->countryDetail->currency_code?:env('SYSTEM_DEFAULT_CURRENCY');

        // Convert the amount to USD to any currency
        $converted_amount_array =  convert_currency_to_usd($user_currency_code, $request->input('amount'));

        $converted_amount = $converted_amount_array['converted_amount'];
        $converted_type = $converted_amount_array['converted_type'];

        $conversion = $converted_type.':'.$request->amount.'-'.$converted_amount;
        $transaction_id = $request->payment_id;

            if (access()->hasRole('user')) {
            $wallet_model = new UserWallet();
            $wallet_add_history_model = new UserWalletHistory();
            $user_id = auth()->user()->id;
        } else {
            $wallet_model = new DriverWallet();
            $wallet_add_history_model = new DriverWalletHistory();
            $user_id = auth()->user()->driver->id;
        }

        $user_wallet = $wallet_model::firstOrCreate([
            'user_id'=>$user_id]);
        $user_wallet->amount_added += $request->amount;
        $user_wallet->amount_balance += $request->amount;
        $user_wallet->save();
        $user_wallet->fresh();

        $wallet_add_history_model::create([
            'user_id'=>$user_id,
            'amount'=>$request->amount,
            'transaction_id'=>$transaction_id,
            'conversion'=>$conversion,
            'remarks'=>WalletRemarks::MONEY_DEPOSITED_TO_E_WALLET,
            'is_credit'=>true]);


        if (access()->hasRole(Role::USER)) {
            $result =  fractal($user_wallet, new WalletTransformer);
        } else {
            $result =  fractal($user_wallet, new DriverWalletTransformer);
        }

        return $this->respondSuccess($result, 'money_added_successfully');
    }

    
}