<?php
/**
 * 2026 CryptAPI
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 *
 * @author CryptAPI <info@cryptapi.io>
 * @copyright  2026 CryptAPI
 * @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_3_0($module)
{
    $db = Db::getInstance();
    $table = _DB_PREFIX_ . 'cryptapi_order';

    // 1. Add PRIMARY KEY (order_id) to cryptapi_order if missing.
    $hasPk = $db->getRow(
        "SELECT 1 AS x FROM information_schema.statistics "
        . "WHERE table_schema = DATABASE() AND table_name = '" . pSQL($table) . "' "
        . "AND index_name = 'PRIMARY' LIMIT 1"
    );

    if (empty($hasPk)) {
        $dupes = $db->getRow(
            'SELECT order_id, COUNT(*) AS c FROM `' . $table . '` '
            . 'GROUP BY order_id HAVING c > 1 LIMIT 1'
        );

        if (!empty($dupes)) {
            PrestaShopLogger::addLog(
                'CryptAPI upgrade 1.3.0: cannot add PRIMARY KEY on cryptapi_order.order_id '
                . 'because duplicate rows exist (e.g. order_id=' . (int) $dupes['order_id'] . '). '
                . 'Resolve duplicates and retry the upgrade.',
                3
            );
            return false;
        }

        if (!$db->execute('ALTER TABLE `' . $table . '` ADD PRIMARY KEY (`order_id`)')) {
            PrestaShopLogger::addLog(
                'CryptAPI upgrade 1.3.0: ALTER TABLE ADD PRIMARY KEY failed.',
                3
            );
            return false;
        }
    }

    // Note: cryptapi_payment_url in metadata is intentionally left as-is for
    // pre-1.3.0 orders. The runtime uses the absence of `&key=` in that field
    // as the signal that an order predates the secure_key URL requirement, so
    // pre-existing emailed payment links (which can't be retroactively patched
    // in the customer's inbox) continue to work via a legacy-bypass shim in
    // cryptapi::authorizeOrderAccess.

    return true;
}
