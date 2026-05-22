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

        if (!CryptAPIHelper::verify_callback_signature()) {
            PrestaShopLogger::addLog(
                'CryptAPI: callback signature verification failed (order=' . (isset($_GET['order']) ? (int) $_GET['order'] : 'n/a') . ')',
                3,
                null,
                'Order',
                isset($_GET['order']) ? (int) $_GET['order'] : null,
                true
            );
            header('HTTP/1.1 401 Unauthorized');
            exit('*invalid signature*');
        }

        $callback = $_GET;
        $orderIdLog = isset($callback['order']) ? (int) $callback['order'] : null;

        $required = ['order', 'coin', 'uuid', 'value_coin', 'pending', 'txid_in'];
        foreach ($required as $field) {
            if (!isset($callback[$field]) || $callback[$field] === '') {
                PrestaShopLogger::addLog(
                    'CryptAPI: callback missing required field "' . $field . '"',
                    2,
                    null,
                    'Order',
                    $orderIdLog,
                    true
                );
                header('HTTP/1.1 400 Bad Request');
                exit('*missing field*');
            }
        }

        if (!is_numeric($callback['value_coin']) || (float) $callback['value_coin'] <= 0) {
            PrestaShopLogger::addLog(
                'CryptAPI: callback invalid value_coin (received: ' . substr((string) $callback['value_coin'], 0, 32) . ')',
                2,
                null,
                'Order',
                $orderIdLog,
                true
            );
            header('HTTP/1.1 400 Bad Request');
            exit('*invalid value_coin*');
        }

        if (!in_array((string) $callback['pending'], ['0', '1'], true)) {
            PrestaShopLogger::addLog(
                'CryptAPI: callback invalid pending value',
                2,
                null,
                'Order',
                $orderIdLog,
                true
            );
            header('HTTP/1.1 400 Bad Request');
            exit('*invalid pending*');
        }

        $orderId = (int) $callback['order'];

        $db = Db::getInstance();
        $db->execute('START TRANSACTION');

        try {
            $row = $db->getRow(
                'SELECT `response` FROM `' . _DB_PREFIX_ . 'cryptapi_order` WHERE order_id=' . $orderId . ' FOR UPDATE'
            );

            if (empty($row) || empty($row['response'])) {
                PrestaShopLogger::addLog(
                    'CryptAPI: callback for unknown order (no cryptapi_order row)',
                    2,
                    null,
                    'Order',
                    $orderId,
                    true
                );
                $db->execute('ROLLBACK');
                exit('*ok*');
            }

            $metaData = json_decode($row['response'], true);
            if (!is_array($metaData)) {
                PrestaShopLogger::addLog(
                    'CryptAPI: cryptapi_order.response is not valid JSON',
                    3,
                    null,
                    'Order',
                    $orderId,
                    true
                );
                $db->execute('ROLLBACK');
                exit('*ok*');
            }

            $order = new Order($orderId);

            $orderPaid = (int) $order->getCurrentState() === (int) Configuration::get('PS_OS_PAYMENT');
            $orderCanceled = (int) $order->getCurrentOrderState()->id === (int) Configuration::get('PS_OS_CANCELED');

            if ($callback['coin'] !== $metaData['cryptapi_currency']) {
                PrestaShopLogger::addLog(
                    'CryptAPI: callback coin mismatch (expected=' . $metaData['cryptapi_currency'] . ' received=' . substr((string) $callback['coin'], 0, 16) . ')',
                    2,
                    null,
                    'Order',
                    $orderId,
                    true
                );
                $db->execute('ROLLBACK');
                exit('*ok*');
            }

            if ($orderPaid || $orderCanceled) {
                $db->execute('ROLLBACK');
                exit('*ok*');
            }

            $disableConversion = cryptapi::isConversionDisabled();
            $qrCodeSize = Configuration::get('cryptapi_qrcode_size');
            $paid = (string) $callback['value_coin'];
            $minTx = (string) $metaData['cryptapi_min'];
            $historyDb = isset($metaData['cryptapi_history']) ? $metaData['cryptapi_history'] : [];

            $isNewUuid = empty($historyDb[$callback['uuid']]);

            if ($isNewUuid) {
                $fiat_conversion = CryptAPIHelper::get_conversion(
                    $metaData['cryptapi_currency'],
                    Currency::getDefaultCurrency()->iso_code,
                    $paid,
                    $disableConversion
                );

                $historyDb[$callback['uuid']] = [
                    'timestamp' => time(),
                    'value_paid' => CryptAPIHelper::sig_fig($paid, 6),
                    'value_paid_fiat' => $fiat_conversion,
                    'pending' => $callback['pending'],
                ];
            } else {
                $historyDb[$callback['uuid']]['pending'] = $callback['pending'];
            }

            $metaData['cryptapi_history'] = $historyDb;

            $calc = cryptapi::calcOrder($historyDb, $metaData['cryptapi_total'], $metaData['cryptapi_total_fiat']);
            $remaining = $calc['remaining'];
            $remainingPending = $calc['remaining_pending'];

            $shouldFinalize = bccomp($remainingPending, '0', 8) <= 0 && bccomp($remaining, '0', 8) <= 0;

            if (!$shouldFinalize) {
                $qrAmount = bccomp($remainingPending, $minTx, 8) < 0 ? $minTx : $remainingPending;
                $qrResponse = CryptAPIHelper::get_static_qrcode(
                    $metaData['cryptapi_address'],
                    $metaData['cryptapi_currency'],
                    $qrAmount,
                    $qrCodeSize
                );
                if (!empty($qrResponse['qr_code'])) {
                    $metaData['cryptapi_qr_code_value'] = $qrResponse['qr_code'];
                }
            }

            $db->update(
                'cryptapi_order',
                ['response' => json_encode($metaData)],
                'order_id=' . $orderId
            );

            if ($isNewUuid) {
                $order->addOrderPayment(
                    '0',
                    $this->module->displayName,
                    $callback['coin'] . ': txid_in: ' . $callback['txid_in']
                );
            }

            if ($shouldFinalize) {
                $history = new OrderHistory();
                $history->id_order = $orderId;
                $history->changeIdOrderState((int) Configuration::get('PS_OS_PAYMENT'), $history->id_order, false);
                $history->addWithemail();
                $history->save();

                PrestaShopLogger::addLog(
                    'CryptAPI: order finalized as paid (txid_in=' . substr((string) $callback['txid_in'], 0, 80) . ')',
                    1,
                    null,
                    'Order',
                    $orderId,
                    true
                );
            }

            $db->execute('COMMIT');
        } catch (Exception $e) {
            $db->execute('ROLLBACK');
            PrestaShopLogger::addLog(
                'CryptAPI: callback handler exception: ' . $e->getMessage(),
                3,
                null,
                'Order',
                $orderId,
                true
            );
            throw $e;
        }

        exit('*ok*');
    }
}
