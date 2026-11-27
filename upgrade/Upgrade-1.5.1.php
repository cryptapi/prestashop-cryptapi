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

/**
 * Shops that kept an old default checkout title get the new one, "Pay with
 * CryptAPI": an empty title falls back to it. Custom titles stay as they are.
 */
function upgrade_module_1_5_1($module)
{
    $oldDefaults = ['Cryptocurrency', 'Pay with cryptocurrency'];

    return Db::getInstance()->update(
        'configuration',
        ['value' => ''],
        "name = 'cryptapi_checkout_title' AND value IN ('" . implode("', '", array_map('pSQL', $oldDefaults)) . "')"
    );
}
