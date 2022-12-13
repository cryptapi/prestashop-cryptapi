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
class CryptAPISuccessModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        $orderId = $_REQUEST['order_id'];

        try {
            $metaData = json_decode(cryptapi::getPaymentResponse($orderId), true);
        } catch (Exception $e) {
            exit($this->module->l('Order not found.', 'success', 'en'));
        }

        $order = new Order((int) $orderId);

        $refresh_value_interval = (int) Configuration::get('cryptapi_refresh_value_interval');
        $order_cancelation_timeout = (int) Configuration::get('cryptapi_order_cancelation_timeout');

        $allowed_to_value = [
            'btc',
            'eth',
            'bch',
            'ltc',
            'miota',
            'xmr',
        ];

        $crypto_allowed_value = false;

        $conversion_timer = ((int) $metaData['cryptapi_last_price_update'] + $refresh_value_interval) - time();
        $date_conversion_timer = date('i:s', $conversion_timer);
        $cancel_timer = $metaData['cryptapi_order_created'] + $order_cancelation_timeout - time();

        if (in_array($metaData['cryptapi_currency'], $allowed_to_value, true)) {
            $crypto_allowed_value = true;
        }

        $this->context->smarty->assign([
            'order_id' => $orderId,
            'color_scheme' => Configuration::get('cryptapi_color_scheme'),
            'currency_symbol' => $this->context->currency->iso_code,
            'total' => (float) $order->total_paid_tax_incl,
            'qrcode_size' => (int) Configuration::get('cryptapi_qrcode_size') + 20,
            'qrcode_default' => Configuration::get('cryptapi_qrcode_default') === '0' ? false : true,
            'show_branding' => Configuration::get('cryptapi_show_branding') === '0' ? false : true,
            'address_in' => $metaData['cryptapi_address'],
            'crypto_value' => $metaData['cryptapi_total'],
            'crypto_coin' => strtoupper($metaData['cryptapi_currency']),
            'qr_code_img_value' => $metaData['cryptapi_qr_code_value'],
            'qr_code_img' => $metaData['cryptapi_qr_code'],
            'qr_code_setting' => Configuration::get('cryptapi_qrcode_setting'),
            'canceled' => $order->getCurrentOrderState()->id !== (int) Configuration::get('PS_OS_CANCELED') ? 1 : 0,
            'module_dir' => Media::getMediaPath(_PS_MODULE_DIR_ . 'cryptapi/'),
            'conversion_timer' => $conversion_timer,
            'date_conversion_timer' => $date_conversion_timer,
            'cancel_timer' => $cancel_timer,
            'crypto_allowed_value' => $crypto_allowed_value,
            'min_tx' => $metaData['cryptapi_min'] . ' ' . strtoupper($metaData['cryptapi_currency']),
            'refresh_value_interval' => $refresh_value_interval,
            'order_cancelation_timeout' => $order_cancelation_timeout,
        ]);

        $this->setTemplate('module:cryptapi/views/templates/front/payment_success.tpl');
    }

    public function setMedia()
    {
        $order_id = $_REQUEST['order_id'];

        $adminajax_link = $this->context->link->getModuleLink('cryptapi', 'state');

        Media::addJsDef([
            'adminajax_link' => $adminajax_link,
            'order_id' => $order_id,
        ]);

        $this->context->controller->registerStylesheet(
            'cryptapi',
            'modules/' . $this->module->name . '/views/css/cryptapi.css',
            [
                'position' => 'top',
                'priority' => 150,
            ]
        );

        $this->context->controller->registerJavascript(
            'cryptapi',
            'modules/' . $this->module->name . '/views/js/cryptapi.js',
            [
                'position' => 'bottom',
                'priority' => 150,
            ]
        );

        return parent::setMedia();
    }
}
