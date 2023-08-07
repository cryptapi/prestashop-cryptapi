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
class CryptAPICallbackModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        require_once _PS_MODULE_DIR_ . 'cryptapi/lib/CryptAPIHelper.php';

        $callback = $_REQUEST;

        $orderId = (int) $callback['order'];

        $order = new Order($orderId);

        $metaData = json_decode(cryptAPI::getPaymentResponse($orderId), true);

        $paid = $order->getCurrentState() === Configuration::get('PS_OS_PAYMENT') ? true : false;

        if ($callback['coin'] !== $metaData['cryptapi_currency']) {
                exit('*ok*');
        }

        if ($paid || $order->getCurrentOrderState()->id === (int) Configuration::get('PS_OS_CANCELED') || $callback['nonce'] !== $metaData['cryptapi_nonce']) {
            exit('*ok*');
        }

        $disableConversion = Configuration::get('cryptapi_disable_conversion') === 1 ? true : false;

        $qrCodeSize = Configuration::get('cryptapi_qrcode_size');

        $paid = (float) $callback['value_coin'];

        $minTx = (float) $metaData['cryptapi_min'];

        $historyDb = $metaData['cryptapi_history'];

        if (empty($historyDb[$callback['uuid']])) {
            $fiat_conversion = CryptAPIHelper::get_conversion($metaData['cryptapi_currency'], Currency::getDefaultCurrency()->iso_code, $paid, $disableConversion);

            $historyDb[$callback['uuid']] = [
                'timestamp' => time(),
                'value_paid' => CryptAPIHelper::sig_fig($paid, 6),
                'value_paid_fiat' => $fiat_conversion,
                'pending' => $callback['pending'],
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
                $history->id_order = (int) $callback['order'];
                $history->changeIdOrderState((int) Configuration::get('PS_OS_PAYMENT'), $history->id_order, false);
                $history->addWithemail();
                $history->save();
            }
            exit('*ok*');
        }

        if ($remainingPending < $minTx) {
            cryptAPI::updatePaymentResponse($orderId, 'cryptapi_qr_code_value', CryptAPIHelper::get_static_qrcode($metaData['cryptapi_address'], $metaData['cryptapi_currency'], $minTx, $qrCodeSize)['qr_code']);
        } else {
            cryptAPI::updatePaymentResponse($orderId, 'cryptapi_qr_code_value', CryptAPIHelper::get_static_qrcode($metaData['cryptapi_address'], $metaData['cryptapi_currency'], $remainingPending, $qrCodeSize)['qr_code']);
        }

        exit('*ok*');
    }
}
