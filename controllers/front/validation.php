<?php

class CryptAPIValidationModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        require_once _PS_MODULE_DIR_ . 'cryptapi/lib/CryptAPIHelper.php';

        $cart = $this->context->cart;
        if ($cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0 || !$this->module->active) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        // Check that this payment option is still available in case the customer changed his address just before the end of the checkout process
        $authorized = false;
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] == 'cryptapi') {
                $authorized = true;
                break;
            }
        }

        if (!$authorized) {
            die($this->module->l('This payment method is not available.', 'validation'));
        }

        $selected = $_REQUEST['cryptapi_coin'];
        if ($selected == 'none') {
            die($this->module->l('Please select a cryptocurrency.', 'validation'));
        }

        $customer = new Customer($cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $addr = Configuration::get($selected . '_address');

        $fee = $_REQUEST['cryptapi_fee'];

        $total = (float)$cart->getOrderTotal(true, Cart::BOTH) + $fee;
        $currency = $this->context->currency;

        $disableConversion = Configuration::get('disable_conversion') === '0' ? false : true;
        $info = CryptAPIHelper::get_info($selected);
        $minTx = floatval($info->minimum_transaction_coin);

        $cryptoTotal = CryptAPIHelper::get_conversion($currency->iso_code, $selected, $total, $disableConversion);

        if ($cryptoTotal < $minTx) {
            die($this->module->l('Value too low, minimum is.', 'validation')) . $minTx;
        }

        $apiKey = Configuration::get('api_key');

        if (empty($addr) && empty($apiKey)) {
            die($this->module->l('There\'s was an error with this payment. Please try again.', 'validation'));
        }

        // Actually create order in prestashop
        $this->module->validateOrder(
            (int)$cart->id,
            (int)Configuration::get('CRYPTAPI_WAITING'),
            $total,
            $this->module->displayName,
            NULL,
            [],
            (int)$currency->id,
            false,
            $customer->secure_key
        );

        $qrCodeSize = Configuration::get('qrcode_size');

        $nonce = cryptapi::generateNonce();
        $orderId = $this->module->currentOrder;

        $callbackUrl = _PS_BASE_URL_ . __PS_BASE_URI__ . 'module/cryptapi/callback?order=' . $orderId . '&nonce=' . $nonce;


        $api = new CryptAPIHelper($selected, $addr, $apiKey, $callbackUrl, [], true);

        // This gives error
        $addressIn = $api->get_address();

        if (empty($addressIn)) {
            die($this->module->l('There\'s was an error with this payment. Please try again.', 'validation'));
        }

        $qrCodeDataValue = $api->get_qrcode($cryptoTotal, $qrCodeSize);
        $qrCodeData = $api->get_qrcode('', $qrCodeSize);

        $paymentData = [
            'cryptapi_nonce' => $nonce,
            'cryptapi_address' => $addressIn,
            'cryptapi_total' => CryptAPIHelper::sig_fig($cryptoTotal, 6),
            'cryptapi_total_fiat' => $total,
            'cryptapi_currency' => $selected,
            'cryptapi_qr_code_value' => $qrCodeDataValue['qr_code'],
            'cryptapi_qr_code' => $qrCodeData['qr_code'],
            'cryptapi_last_price_update' => time(),
            'cryptapi_min' => $minTx,
            'cryptapi_fee' => $fee,
            'cryptapi_order_created' => time(),
            'cryptapi_history' => [],
        ];

        cryptAPI::addPaymentResponse($orderId, json_encode($paymentData));

        $this->context->smarty->assign([
            'params' => $_REQUEST,
        ]);

        Tools::redirectLink(_PS_BASE_URL_ . __PS_BASE_URI__ . 'module/cryptapi/success?order_id=' . $this->module->currentOrder . '&nonce=' . $nonce);
    }
}
