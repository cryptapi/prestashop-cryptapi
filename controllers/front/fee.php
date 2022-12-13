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
class CryptAPIFeeModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        require_once _PS_MODULE_DIR_ . 'cryptapi/lib/CryptAPIHelper.php';

        $totalFee = Configuration::get('cryptapi_fee_order_percentage');

        if (empty($totalFee)) {
            exit(json_encode([
                'fee' => 0,
                'total' => 0,
            ]));
        }

        $objCart = new Cart($this->context->cart->id);

        $total = $objCart->getOrderTotal(true, Cart::BOTH);

        $feeOrder = $total * $totalFee;

        $selected = $_REQUEST['cryptapi_coin'];

        if ($selected === 'none') {
            $this->context->cookie->cryptapi_fee = round(CryptAPIHelper::sig_fig($feeOrder, 6), 2);
            exit(json_encode([
                'fee' => round(CryptAPIHelper::sig_fig($feeOrder, 6), 2) . ' ' . Currency::getDefaultCurrency()->symbol,
                'total' => round(CryptAPIHelper::sig_fig($total + $feeOrder, 6), 2) . ' ' . Currency::getDefaultCurrency()->symbol,
            ]));
        }

        if (!empty($selected) && $selected != 'none' && !empty(Configuration::get('cryptapi_add_blockchain_fee'))) {
            $est = CryptAPIHelper::get_estimate($selected);

            $feeOrder += (float) $est->{Currency::getDefaultCurrency()->iso_code};
        }
        $this->context->cookie->cryptapi_fee = round(CryptAPIHelper::sig_fig($feeOrder, 6), 2);

        exit(json_encode([
            'fee' => round(CryptAPIHelper::sig_fig($feeOrder, 6), 2) . ' ' . Currency::getDefaultCurrency()->symbol,
            'total' => round(CryptAPIHelper::sig_fig($total + $feeOrder, 6), 2) . ' ' . Currency::getDefaultCurrency()->symbol,
        ]));
    }
}
