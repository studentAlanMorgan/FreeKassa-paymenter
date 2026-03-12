<?php

namespace Paymenter\Extensions\Gateways\FreeKassa;

use App\Classes\Extension\Gateway;
use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FreeKassa extends Gateway
{
    public function boot()
    {
        if (file_exists(__DIR__ . '/routes.php')) {
            require __DIR__ . '/routes.php';
        }
    }

    public function getMetadata()
    {
        return [
            'display_name' => 'FreeKassa',
            'version'      => '3.0.0',
            'author'       => 'BHVPS',
            'website'      => 'https://freekassa.net',
        ];
    }

    public function getConfig($values = [])
    {
        return [
            [
                'name'     => 'merchant_id',
                'label'    => 'Merchant ID',
                'type'     => 'text',
                'required' => true,
            ],
            [
                'name'     => 'secret_word_1',
                'label'    => 'Secret Word 1 (Payment)',
                'type'     => 'text',
                'required' => true,
            ],
            [
                'name'     => 'secret_word_2',
                'label'    => 'Secret Word 2 (Notification)',
                'type'     => 'text',
                'required' => true,
            ],
            [
                'name'     => 'currency',
                'label'    => 'Currency (RUB, EUR, USD)',
                'type'     => 'text',
                'required' => true,
                'default'  => 'EUR',
            ],
        ];
    }

    public function pay(Invoice $invoice, $total)
    {
        $merchant_id = trim($this->config('merchant_id'));
        $secret_1 = trim($this->config('secret_word_1'));
        $currency = trim($this->config('currency'));
        if (!$currency) $currency = 'EUR';

        $amount = number_format($total, 2, '.', '');
        $order_id = $invoice->id;

        $sign = md5($merchant_id . ':' . $amount . ':' . $secret_1 . ':' . $currency . ':' . $order_id);

        $params = [
            'm'        => $merchant_id,
            'oa'       => $amount,
            'o'        => $order_id,
            's'        => $sign,
            'currency' => $currency,
            'lang'     => 'en',
            'em'       => $invoice->user->email ?? '',
        ];

        return 'https://pay.freekassa.net/?' . http_build_query($params);
    }

    public function webhook(Request $request)
    {
        // --- CLOUDFLARE FIX START ---
        // Мы не блокируем запрос по IP, но логируем реальный IP для отладки
        $real_ip = $request->header('CF-Connecting-IP') ?? $request->ip();
        
        Log::info('FreeKassa Webhook Received', [
            'source_ip' => $real_ip,
            'data' => $request->all()
        ]);
        // --- CLOUDFLARE FIX END ---

        $merchant_id = $request->input('MERCHANT_ID');
        $amount = $request->input('AMOUNT');
        $order_id = $request->input('MERCHANT_ORDER_ID');
        $sign_received = $request->input('SIGN');

        // Проверка на наличие данных
        if (!$merchant_id || !$amount || !$sign_received) {
            // Возвращаем нормальный JSON или текст ошибки, а не die()
            return response('Error: Missing parameters', 400);
        }

        $secret_2 = trim($this->config('secret_word_2'));

        // Генерация подписи
        // md5(merchant_id:amount:secret_word_2:merchant_order_id)
        $sign_calc = md5($merchant_id . ':' . $amount . ':' . $secret_2 . ':' . $order_id);

        // Сверка подписи
        if ($sign_received !== $sign_calc) {
            Log::error('FreeKassa Signature Error', [
                'expected' => $sign_calc,
                'received' => $sign_received
            ]);
            // Возвращаем корректную ошибку, но НЕ "hacking attempt"
            return response('Error: Wrong Signature', 400);
        }

        try {
            $tx_id = $request->input('intid') ?? $order_id;
            
            // Зачисляем платеж
            ExtensionHelper::addPayment(
                $invoice_id = $order_id,
                $gateway_name = 'FreeKassa',
                $amount = $amount,
                $fee = 0,
                $transaction_id = $tx_id
            );

            Log::info("FreeKassa Payment Success for Order #$order_id");
            return 'YES';

        } catch (\Exception $e) {
            Log::error('FreeKassa Processing Error: ' . $e->getMessage());
            return response('Internal Error', 500);
        }
    }

}
