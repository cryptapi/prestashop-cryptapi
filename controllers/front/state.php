<?php

class CryptAPIStateModuleFrontController extends ModuleFrontController
{

    public function initContent()
    {
        require_once _PS_MODULE_DIR_ . 'cryptapi/lib/CryptAPIHelper.php';

        if (empty($_REQUEST['order_id']) || empty($_REQUEST['nonce'])) {
            die($this->module->l('Order not found.', 'error'));
        }

        $orderId = $_REQUEST['order_id'];
        $nonce = $_REQUEST['nonce'];

        try {
            $metaData = json_decode(cryptapi::getPaymentResponse($orderId), true);
            $historyDb = $metaData['cryptapi_history'];
        } catch (Exception $e) {
            die($this->module->l('Order not found.', 'error'));
        }

        if (empty($nonce) || $nonce !== $metaData['cryptapi_nonce']) {
            die($this->module->l('Order not found.', 'error'));
        }

        $order = new Order((int)$orderId);

        $showMinFee = 0;

        $calc = cryptapi::calcOrder($historyDb, $metaData['cryptapi_total'], $metaData['cryptapi_total_fiat']);

        $alreadyPaid = $calc['already_paid'];
        $alreadyPaidFiat = $calc['already_paid_fiat'];

        $min_tx = floatval($metaData['cryptapi_min']);

        $remainingPending = $calc['remaining_pending'];
        $remainingFiat = $calc['remaining_fiat'];

        $cryptapiPending = 0;

        $paid = $order->getCurrentState() === Configuration::get('PS_OS_PAYMENT') ? 1 : 0;

        if ($remainingPending <= 0 && !$paid) {
            $cryptapiPending = 1;
        }

        $counterCalc = (int)$metaData['cryptapi_last_price_update'] + (int)Configuration::get('refresh_value_interval') - time();

        if ($counterCalc <= 0 && $paid) {
            cryptapi::cryptapiCronjob();
        }

        if ($remainingPending <= $min_tx && $remainingPending > 0) {
            $remainingPending = $min_tx;
            $showMinFee = 1;
        }

        $params = array(
            'is_paid' => $paid,
            'is_pending' => $cryptapiPending,
            'qr_code_value' => $metaData['cryptapi_qr_code_value'],
            'canceled' => $order->getCurrentOrderState()->id === (int)Configuration::get('PS_OS_CANCELED') ? 1 : 0,
            'coin' => strtoupper($metaData['cryptapi_currency']),
            'show_min_fee' => $showMinFee,
            'order_history' => $historyDb,
            'counter' => (string)$counterCalc,
            'crypto_total' => floatval($metaData['cryptapi_total']),
            'already_paid' => $alreadyPaid,
            'remaining' => $remainingPending <= 0 ? 0 : $remainingPending,
            'fiat_remaining' => $remainingFiat <= 0 ? 0 : $remainingFiat,
            'already_paid_fiat' => floatval($alreadyPaidFiat) <= 0 ? 0 : floatval($alreadyPaidFiat),
            'fiat_symbol' => Currency::getDefaultCurrency()->symbol,
        );

        die(json_encode($params));
    }
}