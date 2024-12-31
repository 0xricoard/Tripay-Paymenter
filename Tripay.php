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
     * @param int $total
     * @param array $products
     * @param int $invoiceId
     * @return string
     */
    public function pay($total, $products, $invoiceId)
    {
        // Check if the checkout URL is already cached
        $cacheKey = "tripay_checkout_url_$invoiceId";
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $url = 'https://tripay.co.id/api/transaction/create';
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

        $signature = hash_hmac('sha256', $merchantCode . $invoiceId . number_format($total, 0, '', ''), $privateKey);

        // Log data to be sent to Tripay
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

        // Send request to Tripay API
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
            $checkoutUrl = $response->json()['data']['checkout_url'];

            // Cache the checkout URL with an expiration time (e.g., 1 hour)
            Cache::put($cacheKey, $checkoutUrl, 3600); // 3600 seconds = 1 hour
            return $checkoutUrl;
        } else {
            Log::error('Tripay Payment Error', ['response' => $response->body()]);
            return false;
        }
    }

    public function webhook(Request $request)
    {
        $privateKey = ExtensionHelper::getConfig('Tripay', 'private_key');

        // Log all parameters received from the webhook request
        Log::debug('Tripay Webhook All Data', $request->all());

        // Ambil seluruh konten JSON dari request
        $json = $request->getContent();
        $signature = $request->header('X-Callback-Signature');

        // Hitung tanda tangan yang benar menggunakan seluruh konten JSON
        $calculatedSignature = hash_hmac('sha256', $json, $privateKey);

        // Log data untuk debug
        Log::debug('Tripay Webhook Signature Verification', [
            'received_signature' => $signature,
            'calculated_signature' => $calculatedSignature,
        ]);

        if ($signature !== $calculatedSignature) {
            Log::error('Invalid signature', [
                'received_signature' => $signature,
                'calculated_signature' => $calculatedSignature,
            ]);
            return response()->json(['success' => false, 'message' => 'Invalid signature'], 400);
        }

        $data = json_decode($json, true);

        if (JSON_ERROR_NONE !== json_last_error()) {
            Log::error('Invalid JSON data', ['error' => json_last_error_msg()]);
            return response()->json(['success' => false, 'message' => 'Invalid JSON data'], 400);
        }

        $merchantRef = $data['merchant_ref'] ?? null;
        $status = $data['status'] ?? null;

        if (!$merchantRef || !$status) {
            Log::error('Missing parameters', $data);
            return response()->json(['success' => false, 'message' => 'Missing parameters'], 400);
        }

        if ($status === 'PAID') {
            ExtensionHelper::paymentDone($merchantRef, 'Tripay');
            return response()->json(['success' => true]);
        } elseif ($status === 'EXPIRED' || $status === 'FAILED') {
            return response()->json(['success' => true]);
        } else {
            return response()->json(['success' => false, 'message' => 'Invalid status'], 400);
        }
    }
}