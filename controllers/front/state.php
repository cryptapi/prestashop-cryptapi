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
class CryptAPIStateModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        require_once _PS_MODULE_DIR_ . 'cryptapi/lib/CryptAPIHelper.php';

        if (empty($_REQUEST['order_id'])) {
            exit($this->module->l('Order not found.', 'error', 'en'));
        }

        $orderId = (int) $_REQUEST['order_id'];
        $providedKey = isset($_REQUEST['key']) ? (string) $_REQUEST['key'] : '';

        $order = new Order($orderId);

        if (!cryptapi::authorizeOrderAccess($order, $providedKey)) {
            header('HTTP/1.1 403 Forbidden');
            exit($this->module->l('Order not found.', 'error', 'en'));
        }

        $responseJson = cryptapi::getPaymentResponse($orderId);
        $metaData = $responseJson !== null ? json_decode($responseJson, true) : null;
        if (!is_array($metaData)) {
            header('HTTP/1.1 404 Not Found');
            exit($this->module->l('Order not found.', 'error', 'en'));
        }
        $historyDb = isset($metaData['cryptapi_history']) ? $metaData['cryptapi_history'] : [];

        $showMinFee = 0;

        $calc = cryptapi::calcOrder($historyDb, $metaData['cryptapi_total'], $metaData['cryptapi_total_fiat']);

        $alreadyPaid = $calc['already_paid'];
        $alreadyPaidFiat = CryptAPIHelper::sig_fig($calc['already_paid_fiat'], 2);

        $min_tx = (string) $metaData['cryptapi_min'];

        $remainingPending = $calc['remaining_pending'];
        $remainingFiat = CryptAPIHelper::sig_fig($calc['remaining_fiat'], 2);

        $cryptapiPending = 0;

        $paid = (int) $order->getCurrentState() === (int) Configuration::get('PS_OS_PAYMENT') ? 1 : 0;

        if (bccomp($remainingPending, '0', 8) <= 0 && !$paid) {
            $cryptapiPending = 1;
        }

        $counterCalc = (int) $metaData['cryptapi_last_price_update'] + (int) Configuration::get('cryptapi_refresh_value_interval') - time();

        if ($counterCalc < 0 && !$paid) {
            cryptapi::processOrderTick([
                'id_order' => $order->id,
                'total_paid' => $order->total_paid,
                'current_state' => $order->getCurrentState(),
            ]);
        }

        if (bccomp($remainingPending, $min_tx, 8) <= 0 && bccomp($remainingPending, '0', 8) > 0) {
            $remainingPending = $min_tx;
            $showMinFee = 1;
        }

        if ($paid) {
            $remainingFiat = '0';
            $remainingPending = '0';
        }

        $params = [
            'is_paid' => $paid,
            'is_pending' => $cryptapiPending,
            'qr_code_value' => $metaData['cryptapi_qr_code_value'],
            'canceled' => (int) $order->getCurrentOrderState()->id === (int) Configuration::get('PS_OS_CANCELED') ? 1 : 0,
            'coin' => strtoupper($metaData['cryptapi_currency']),
            'show_min_fee' => $showMinFee,
            'order_history' => $historyDb,
            'counter' => (string) $counterCalc,
            'crypto_total' => (string) $metaData['cryptapi_total'],
            'already_paid' => $alreadyPaid,
            'remaining' => $remainingPending,
            'fiat_remaining' => $remainingFiat,
            'already_paid_fiat' => $alreadyPaidFiat,
            'fiat_symbol' => Currency::getDefaultCurrency()->symbol,
        ];

        exit(json_encode($params));
    }
}
