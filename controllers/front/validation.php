<?php
/**
 * 2022 CryptAPI
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to info@cryptapi.io so we can send you a copy immediately.
 *
 * @author CryptAPI <info@cryptapi.io>
 * @copyright  2022 CryptAPI
 * @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */
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
            exit($this->module->l('This payment method is not available.', 'validation'));
        }

        // CSRF: only accept the customer's own POSTed form carrying a valid token.
        // On a missing/invalid token, redirect back to checkout and create no order.
        if (Tools::strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $token = (string) Tools::getValue('cryptapi_token');
        if (empty($token) || !hash_equals(Tools::getToken('cryptapi_validation'), $token)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $selected = (string) Tools::getValue('cryptapi_coin');
        if ($selected === 'none') {
            exit($this->module->l('Please select a cryptocurrency.', 'validation'));
        }

        // Whitelist: reject any coin that is not part of the merchant's enabled set.
        if (!cryptapi::isEnabledCoin($selected)) {
            exit($this->module->l('Please select a cryptocurrency.', 'validation'));
        }

        $customer = new Customer($cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $addr = Configuration::get(cryptapi::coinAddressConfigKey($selected));

        $sessionFee = $this->context->cookie->cryptapi_fee;

        $fee = !empty($sessionFee) ? $sessionFee : 0;

        $total = (float) $cart->getOrderTotal(true, Cart::BOTH) + $fee;
        $currency = $this->context->currency;

        $disableConversion = cryptapi::isConversionDisabled();
        $info = CryptAPIHelper::get_info($selected);
        $minTx = (float) $info->minimum_transaction_coin;

        $cryptoTotal = CryptAPIHelper::sig_fig(CryptAPIHelper::get_conversion($currency->iso_code, $selected, $total, $disableConversion), 6);

        if ($cryptoTotal < $minTx) {
            exit($this->module->l('Value too low, minimum is.' . $minTx, 'validation'));
        }

        $apiKey = Configuration::get('cryptapi_api_key');

        if (empty($addr) && empty($apiKey)) {
            exit($this->module->l('There\'s was an error with this payment. Please try again.', 'validation'));
        }

        // Actually create order in prestashop
        $this->module->validateOrder(
            (int) $cart->id,
            (int) Configuration::get('CRYPTAPI_WAITING'),
            $total,
            $this->module->displayName,
            null,
            [],
            (int) $currency->id,
            false,
            $customer->secure_key
        );

        $qrCodeSize = Configuration::get('cryptapi_qrcode_size');

        $orderId = $this->module->currentOrder;

        // Per-order unguessable secret bound to this callback URL. CryptAPI echoes
        // it back unchanged in every callback, so callback.php can reject any
        // callback whose nonce it doesn't recognise. This is defence-in-depth on
        // top of the RSA signature check — without it the callback URL is just the
        // sequential order id and an attacker who guessed it could race callbacks
        // (e.g. with their own address_out) if signature verification were ever
        // bypassed. See https://docs.blockbee.io — "Harden your callback with a secret".
        $nonce = cryptapi::generateNonce(32);

        $callbackUrl = $this->context->link->getModuleLink('cryptapi', 'callback', ['order' => $orderId, 'nonce' => $nonce], true);

        $api = new CryptAPIHelper($selected, $addr, $apiKey, $callbackUrl, [], true);

        $addressIn = $api->get_address();

        if (empty($addressIn)) {
            exit($this->module->l('There\'s was an error with this payment. Please try again.', 'validation'));
        }

        $qrCodeDataValue = $api->get_qrcode($cryptoTotal, $qrCodeSize);
        $qrCodeData = $api->get_qrcode('', $qrCodeSize);
        $paymentURL = $this->context->link->getModuleLink('cryptapi', 'success', ['order_id' => $this->module->currentOrder, 'key' => $customer->secure_key], true);

        $paymentData = [
            'cryptapi_address' => $addressIn,
            'cryptapi_total' => $cryptoTotal,
            'cryptapi_total_fiat' => $total,
            'cryptapi_currency' => $selected,
            'cryptapi_qr_code_value' => $qrCodeDataValue['qr_code'],
            'cryptapi_qr_code' => $qrCodeData['qr_code'],
            'cryptapi_last_price_update' => time(),
            'cryptapi_min' => $minTx,
            'cryptapi_fee' => $fee,
            'cryptapi_order_created' => time(),
            'cryptapi_history' => [],
            'cryptapi_payment_url' => $paymentURL,
            'cryptapi_nonce' => $nonce,
        ];

        cryptAPI::addPaymentResponse($orderId, json_encode($paymentData));

        $this->context->smarty->assign([
            'params' => $_REQUEST,
        ]);

        cryptapi::sendMail($orderId);

        Tools::redirect($paymentURL);
    }
}
