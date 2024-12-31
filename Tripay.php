<?php

namespace App\Extensions\Gateways\Tripay;

use App\Classes\Extensions\Gateway;
use App\Helpers\ExtensionHelper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Session;

class Tripay extends Gateway
{
    /**
     * Get the extension metadata
     * 
     * @return array
     */
    public function getMetadata()
    {
        return [
            'display_name' => 'Tripay',
            'version'      => '1.0.0',
            'author'       => '0xricoard',
            'website'      => 'https://servermikro.com',
        ];
    }

    /**
     * Get all the configuration for the extension
     * 
     * @return array
     */
    public function getConfig()
    {
        return [
            [
                'name'         => 'api_key',
                'friendlyName' => 'API Key',
                'type'        => 'text',
                'required'    => true,
            ],
            [
                'name'         => 'private_key',
                'friendlyName' => 'Private Key',
                'type'        => 'text',
                'required'    => true,
            ],
            [
                'name'         => 'merchant_code',
                'friendlyName' => 'Merchant Code',
                'type'        => 'text',
                'required'    => true,
            ],
            [
                'name'         => 'payment_method',
                'friendlyName' => 'Payment Method',
                'type'        => 'text',
                'required'    => true,
            ],
        ];
    }

    /**
     * Get the URL to redirect to
     * 
     * @param int $total - The total amount to be paid
     * @param array $products - The list of products in the order
     * @param int $invoiceId - The ID of the invoice being processed
     * @return string - The URL to redirect the user to for payment
     */
    public function pay($total, $products, $invoiceId)
    {
        // Check if the checkout URL is already cached
        $cacheKey = "tripay_checkout_url_$invoiceId";
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        // Define the API endpoint for creating a transaction
        $url = 'https://tripay.co.id/api/transaction/create';

        // Retrieve configuration values
        $apiKey = ExtensionHelper::getConfig('Tripay', 'api_key');
        $privateKey = ExtensionHelper::getConfig('Tripay', 'private_key');
        $merchantCode = ExtensionHelper::getConfig('Tripay', 'merchant_code');
        $paymentMethod = ExtensionHelper::getConfig('Tripay', 'payment_method');

        // Create an array of order items
        $orderItems = array_map(function($product) {
            return [
                'name' => $product->name,
                'price' => $product->price,
                'quantity' => $product->quantity,
            ];
        }, $products);

        // Generate the signature for the request
        $signature = hash_hmac('sha256', $merchantCode . $invoiceId . number_format($total, 0, '', ''), $privateKey);

        // Log data to be sent to Tripay for debugging purposes
        Log::debug('Tripay Request Data', [
            'method'        => $paymentMethod,
            'merchant_ref'  => $invoiceId,
            'customer_name' => auth()->user()->name,
            'customer_email'=> auth()->user()->email,
            'signature'     => $signature,
            'amount'        => number_format($total, 0, '', ''),
            'order_items'   => $orderItems,
            'return_url'    => route('clients.invoice.show', $invoiceId),
        ]);

        // Send the request to Tripay API
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
        ])->post($url, [
            'method'        => $paymentMethod,
            'merchant_ref'  => $invoiceId,
            'customer_name' => auth()->user()->name,
            'customer_email'=> auth()->user()->email,
            'signature'     => $signature,
            'amount'        => number_format($total, 0, '', ''),
            'order_items'   => $orderItems,
            'return_url'    => route('clients.invoice.show', $invoiceId),
        ]);

        // Check if the response is successful
        if ($response->successful()) {
            // Extract the checkout URL from the response
            $checkoutUrl = $response->json()['data']['checkout_url'];

            // Cache the checkout URL with an expiration time (e.g., 1 hour)
            Cache::put($cacheKey, $checkoutUrl, 3600); // 3600 seconds = 1 hour
            return $checkoutUrl;
        } else {
            // Log the error response for debugging purposes
            Log::error('Tripay Payment Error', ['response' => $response->body()]);
            return false;
        }
    }

    /**
     * Handle the webhook notification from Tripay
     * 
     * @param Request $request - The incoming HTTP request
     * @return Response - The HTTP response
     */
    public function webhook(Request $request)
    {
        // Retrieve the private key from configuration
        $privateKey = ExtensionHelper::getConfig('Tripay', 'private_key');

        // Log all parameters received from the webhook request for debugging purposes
        Log::debug('Tripay Webhook All Data', $request->all());

        // Get the entire JSON content of the request
        $json = $request->getContent();
        // Get the signature from the request headers
        $signature = $request->header('X-Callback-Signature');

        // Calculate the correct signature using the entire JSON content
        $calculatedSignature = hash_hmac('sha256', $json, $privateKey);

        // Log signature verification data for debugging purposes
        Log::debug('Tripay Webhook Signature Verification', [
            'received_signature' => $signature,
            'calculated_signature' => $calculatedSignature,
        ]);

        // Verify if the received signature matches the calculated signature
        if ($signature !== $calculatedSignature) {
            // Log the error if the signatures do not match
            Log::error('Invalid signature', [
                'received_signature' => $signature,
                'calculated_signature' => $calculatedSignature,
            ]);
            return response()->json(['success' => false, 'message' => 'Invalid signature'], 400);
        }

        // Decode the JSON content to an associative array
        $data = json_decode($json, true);

        // Check for JSON decoding errors
        if (JSON_ERROR_NONE !== json_last_error()) {
            Log::error('Invalid JSON data', ['error' => json_last_error_msg()]);
            return response()->json(['success' => false, 'message' => 'Invalid JSON data'], 400);
        }

        // Extract necessary parameters from the decoded data
        $merchantRef = $data['merchant_ref'] ?? null;
        $status = $data['status'] ?? null;

        // Check if required parameters are missing
        if (!$merchantRef || !$status) {
            Log::error('Missing parameters', $data);
            return response()->json(['success' => false, 'message' => 'Missing parameters'], 400);
        }

        // Handle the payment status
        if ($status === 'PAID') {
            // Mark the payment as done
            ExtensionHelper::paymentDone($merchantRef, 'Tripay');
            return response()->json(['success' => true]);
        } elseif ($status === 'EXPIRED' || $status === 'FAILED') {
            // Handle expired or failed payment
            return response()->json(['success' => true]);
        } else {
            return response()->json(['success' => false, 'message' => 'Invalid status'], 400);
        }
    }
}
