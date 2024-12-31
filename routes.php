<?php 
use Illuminate\Support\Facades\Route;

Route::post('/tripay/webhook', [App\Extensions\Gateways\Tripay\Tripay::class, 'webhook'])->name('webhook');
