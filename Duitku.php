<?php

namespace App\Extensions\Gateways\Duitku;

use App\Classes\Extensions\Gateway;
use App\Helpers\ExtensionHelper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

class Duitku extends Gateway
{
    /**
     * Get the extension metadata
     * 
     * @return array
     */
    public function getMetadata()
    {
        return [
            'display_name' => 'Duitku',
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
                'name'         => 'merchant_code',
                'friendlyName' => 'Merchant Code',
                'type'        => 'text',
                'required'    => true,
            ],
            [
                'name'         => 'api_key',
                'friendlyName' => 'API Key',
                'type'        => 'text',
                'required'    => true,
            ],
            [
                'name'         => 'callback_url',
                'friendlyName' => 'Callback URL',
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
     * @param int $orderId
     * @return string
     */
    public function pay($total, $products, $orderId)
    {
        $url = 'https://sandbox.duitku.com/webapi/api/merchant/v2/inquiry';
        $merchantCode = ExtensionHelper::getConfig('Duitku', 'merchant_code');
        $apiKey = ExtensionHelper::getConfig('Duitku', 'api_key');
        $callbackUrl = ExtensionHelper::getConfig('Duitku', 'callback_url');
        $returnUrl = route('clients.invoice.show', $orderId);

        $orderItems = array_map(function($product) {
            return [
                'name' => $product->name,
                'price' => $product->price,
                'quantity' => $product->quantity,
            ];
        }, $products);

        $params = [
            'merchantCode' => $merchantCode,
            'paymentAmount' => $total,
            'merchantOrderId' => $orderId,
            'productDetails' => json_encode($orderItems),
            'additionalParam' => '',
            'merchantUserInfo' => auth()->user()->email,
            'email' => auth()->user()->email,
            'callbackUrl' => $callbackUrl,
            'returnUrl' => $returnUrl,
            'signature' => hash('sha256', $merchantCode . $orderId . $total . $apiKey),
        ];

        // Send request to Duitku API
        $response = Http::post($url, $params);

        // Check if the response is successful
        if ($response->successful()) {
            $responseData = $response->json();
            if ($responseData['statusCode'] == 200) {
                return $responseData['paymentUrl']; // URL to redirect the user for payment
            } else {
                Log::error('Duitku Payment Error', ['response' => $responseData]);
                return false;
            }
        } else {
            Log::error('Duitku Payment Error', ['response' => $response->body()]);
            return false;
        }
    }

    public function webhook(Request $request)
    {
        $apiKey = ExtensionHelper::getConfig('Duitku', 'api_key');

        // Ambil seluruh konten JSON dari request
        $json = $request->getContent();
        $data = json_decode($json, true);

        if (JSON_ERROR_NONE !== json_last_error()) {
            Log::error('Invalid JSON data', ['error' => json_last_error_msg()]);
            return response()->json(['success' => false, 'message' => 'Invalid JSON data'], 400);
        }

        $merchantOrderId = $data['merchantOrderId'] ?? null;
        $statusCode = $data['statusCode'] ?? null;

        // Hitung tanda tangan yang benar
        $signature = $data['signature'] ?? '';
        $calculatedSignature = hash('sha256', $merchantOrderId . $statusCode . $apiKey);

        // Log data untuk debug
        Log::debug('Duitku Webhook Data', [
            'merchantOrderId' => $merchantOrderId,
            'statusCode' => $statusCode,
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

        if ($statusCode === '00') { // '00' code for successful payment
            ExtensionHelper::paymentDone($merchantOrderId, 'Duitku');
            return response()->json(['success' => true]);
        } elseif (in_array($statusCode, ['01', '02'])) { // Example: '01' for expired, '02' for failed
            return response()->json(['success' => true]);
        } else {
            return response()->json(['success' => false, 'message' => 'Invalid status'], 400);
        }
    }
}
