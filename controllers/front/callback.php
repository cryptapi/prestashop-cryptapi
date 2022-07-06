<?php

class CryptAPICallbackModuleFrontController extends ModuleFrontController
{

    public function postProcess()
    {
        require_once _PS_MODULE_DIR_ . 'cryptapi/lib/CryptAPIHelper.php';

        $callback = $_REQUEST;

        $orderId = (int)$callback['order'];

        $order = new Order($orderId);

        $metaData = json_decode(cryptAPI::getPaymentResponse($orderId), true);

        $paid = $order->getCurrentState() === Configuration::get('PS_OS_PAYMENT') ? true : false;

        if ($paid || $order->getCurrentOrderState()->id === (int)Configuration::get('PS_OS_CANCELED') || $callback['nonce'] !== $metaData['cryptapi_nonce']) {
            die("*ok*");
        }

        $disableConversion = Configuration::get('disable_conversion') === 1 ? true : false;

        $qrCodeSize = Configuration::get('qrcode_size');

        $paid = floatval($callback['value_coin']);

        $minTx = floatval($metaData['cryptapi_min']);

        $historyDb = $metaData['cryptapi_history'];

        if (empty($historyDb[$callback['uuid']])) {
            $fiat_conversion = CryptAPIHelper::get_conversion($metaData['cryptapi_currency'], Currency::getDefaultCurrency()->iso_code, $paid, $disableConversion);

            $historyDb[$callback['uuid']] = [
                'timestamp' => time(),
                'value_paid' => CryptAPIHelper::sig_fig($paid, 6),
                'value_paid_fiat' => $fiat_conversion,
                'pending' => $callback['pending']
            ];
        } else {
            $historyDb[$callback['uuid']]['pending'] = $callback['pending'];
        }

        cryptapi::updatePaymentResponse($orderId, 'cryptapi_history', $historyDb);

        $metaData = json_decode(cryptAPI::getPaymentResponse($orderId), true);

        $historyDb = $metaData['cryptapi_history'];

        $order->addOrderPayment(
            '0',
            $this->module->displayName,
            $callback['coin'] . ': txid_in: ' . $callback['txid_in'],
        );

        $calc = cryptapi::calcOrder($historyDb, $metaData['cryptapi_total'], $metaData['cryptapi_total_fiat']);

        $remaining = $calc['remaining'];
        $remainingPending = $calc['remaining_pending'];

        if ($remainingPending <= 0) {
            if ($remaining <= 0) {
                $history = new OrderHistory();
                $history->id_order = (int)$callback['order'];
                $history->changeIdOrderState((int)Configuration::get('PS_OS_PAYMENT'), $history->id_order, false);
                $history->addWithemail();
                $history->save();
            }
            die("*ok*");
        }

        if ($remainingPending < $minTx) {
            cryptAPI::updatePaymentResponse($orderId, 'cryptapi_qr_code_value', CryptAPIHelper::get_static_qrcode($metaData['cryptapi_address'], $metaData['cryptapi_currency'], $minTx, $qrCodeSize)['qr_code']);
        } else {
            cryptAPI::updatePaymentResponse($orderId, 'cryptapi_qr_code_value', CryptAPIHelper::get_static_qrcode($metaData['cryptapi_address'], $metaData['cryptapi_currency'], $remainingPending, $qrCodeSize)['qr_code']);
        }

        die("*ok*");
    }
}