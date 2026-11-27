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

        $pendingEmail = null;
        $retry = false;

        try {
            $rows = $db->executeS(
                'SELECT `response` FROM `' . _DB_PREFIX_ . 'cryptapi_order` WHERE order_id=' . $orderId . ' LIMIT 1 FOR UPDATE'
            );
            $row = !empty($rows) ? $rows[0] : null;

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

            // Verify the per-order nonce we embedded in the callback URL at
            // checkout. CryptAPI echoes it back unchanged; a callback whose nonce
            // doesn't match the stored one is rejected. Constant-time compare, and
            // reject when no nonce is stored so an empty/absent nonce can never
            // satisfy the comparison. This is a second gate on top of the RSA
            // signature check above.
            $expectedNonce = isset($metaData['cryptapi_nonce']) ? (string) $metaData['cryptapi_nonce'] : '';
            $givenNonce = isset($callback['nonce']) ? (string) $callback['nonce'] : '';

            if ($expectedNonce === '' || !hash_equals($expectedNonce, $givenNonce)) {
                PrestaShopLogger::addLog(
                    'CryptAPI: callback nonce mismatch (order=' . $orderId . ')',
                    3,
                    null,
                    'Order',
                    $orderId,
                    true
                );
                $db->execute('ROLLBACK');
                header('HTTP/1.1 401 Unauthorized');
                exit('*invalid nonce*');
            }

            $order = new Order($orderId);

            // Treat every terminal / post-paid state as "already handled" so a
            // replayed valid callback can never re-finalize an order that has
            // moved past payment (paid, canceled or refunded). Re-driving
            // changeIdOrderState(PS_OS_PAYMENT) on a refunded order would wrongly
            // flip it back to PAID.
            $currentState = (int) $order->getCurrentState();
            $orderPaid = $currentState === (int) Configuration::get('PS_OS_PAYMENT');
            $orderCanceled = $currentState === (int) Configuration::get('PS_OS_CANCELED');
            $orderRefunded = $currentState === (int) Configuration::get('PS_OS_REFUND');

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

            if ($orderPaid || $orderCanceled || $orderRefunded) {
                $db->execute('ROLLBACK');
                exit('*ok*');
            }

            $disableConversion = cryptapi::isConversionDisabled();
            $qrCodeSize = Configuration::get('cryptapi_qrcode_size');
            $paid = (string) $callback['value_coin'];
            // Older orders stored the minimum as a float, which comes back as
            // "8.0E-5" below 0.0001 and makes bcmath throw.
            $minTx = CryptAPIHelper::positive_amount(isset($metaData['cryptapi_min']) ? $metaData['cryptapi_min'] : null, 8);
            if ($minTx === null) {
                $minTx = '0';
            }
            $historyDb = isset($metaData['cryptapi_history']) ? $metaData['cryptapi_history'] : [];

            $isNewUuid = empty($historyDb[$callback['uuid']]);

            if ($isNewUuid) {
                // In the order's currency, like cryptapi_total_fiat and
                // addOrderPayment() below (1.5.0 used the shop default).
                $fiat_conversion = CryptAPIHelper::get_conversion(
                    $metaData['cryptapi_currency'],
                    cryptapi::orderCurrencyCode($order),
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

            // A failed rate refresh in 1.5.0 and earlier saved an empty total,
            // bcmath reads "" as 0, and the next callback (even a pending one
            // at the coin minimum) finalized the order. Never work out payment
            // state from an invalid total.
            $cryptoTotal = CryptAPIHelper::positive_amount(isset($metaData['cryptapi_total']) ? $metaData['cryptapi_total'] : null);

            // Confirmed callbacks try one re-quote. Pending ones don't: the
            // next refresh repairs the total.
            if ($cryptoTotal === null && $callback['pending'] === '0') {
                $cryptoTotal = cryptapi::requoteOrder($order, $metaData);

                if ($cryptoTotal !== null) {
                    $metaData['cryptapi_total'] = $cryptoTotal;
                    unset($metaData['cryptapi_total_unavailable']);
                }
            }

            $shouldFinalize = false;

            if ($cryptoTotal === null) {
                // Record the payment but leave the order waiting. A confirmed
                // callback gets a 503 after the commit, so the provider sends
                // it again; by then the total may be back.
                $retry = $callback['pending'] === '0';

                if (empty($metaData['cryptapi_total_unavailable'])) {
                    PrestaShopLogger::addLog(
                        'CryptAPI: crypto total unavailable; payment recorded, order not finalized yet (txid_in=' . substr((string) $callback['txid_in'], 0, 80) . ')',
                        3,
                        null,
                        'Order',
                        $orderId,
                        true
                    );
                    $metaData['cryptapi_total_unavailable'] = 1;
                }
            } else {
                $calc = cryptapi::calcOrder($historyDb, $cryptoTotal, $metaData['cryptapi_total_fiat']);
                $remaining = $calc['remaining'];
                $remainingPending = $calc['remaining_pending'];

                $shouldFinalize = bccomp($remainingPending, '0', 8) <= 0 && bccomp($remaining, '0', 8) <= 0;
            }

            if ($cryptoTotal !== null && !$shouldFinalize) {
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
                // Record the actual credited amount (fiat value in the order
                // currency, the same figure stored in cryptapi_history) instead
                // of a literal 0 so Order::total_paid_real reflects real payments.
                $creditedFiat = (string) $historyDb[$callback['uuid']]['value_paid_fiat'];
                $order->addOrderPayment(
                    $creditedFiat,
                    $this->module->displayName,
                    $callback['coin'] . ': txid_in: ' . $callback['txid_in']
                );
            }

            if ($shouldFinalize) {
                $history = new OrderHistory();
                $history->id_order = $orderId;
                // true: link the payments added above to the new invoice. With
                // false, PrestaShop adds a second payment for the full total.
                $history->changeIdOrderState((int) Configuration::get('PS_OS_PAYMENT'), $history->id_order, true);
                $history->add();

                $pendingEmail = $history;

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

        if ($pendingEmail !== null && isset($order)) {
            try {
                $pendingEmail->sendEmail($order);
            } catch (Exception $e) {
                PrestaShopLogger::addLog(
                    'CryptAPI: order state saved but confirmation email failed: ' . $e->getMessage(),
                    2,
                    null,
                    'Order',
                    $orderId,
                    true
                );
            }
        }

        if ($retry) {
            header('HTTP/1.1 503 Service Unavailable');
            exit('*retry*');
        }

        exit('*ok*');
    }
}
