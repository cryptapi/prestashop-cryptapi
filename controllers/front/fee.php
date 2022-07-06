<?php

class CryptAPIFeeModuleFrontController extends ModuleFrontController
{

    public function initContent()
    {
        require_once _PS_MODULE_DIR_ . 'cryptapi/lib/CryptAPIHelper.php';

        $totalFee = Configuration::get('fee_order_percentage');

        if (empty($totalFee)) die(json_encode([
            'fee' => 0,
            'total' => 0
        ]));

        $objCart = new Cart($this->context->cart->id);

        $total = $objCart->getOrderTotal(true, Cart::BOTH);

        $feeOrder = $total * $totalFee;

        $selected = $_REQUEST['cryptapi_coin'];

        if ($selected === 'none') {
            die(json_encode([
                'fee' => round(CryptAPIHelper::sig_fig($feeOrder, 6), 2) . ' ' . Currency::getDefaultCurrency()->symbol,
                'total' => round(floatval(CryptAPIHelper::sig_fig($total + $feeOrder, 6)), 2) . ' ' . Currency::getDefaultCurrency()->symbol
            ]));
        }

        if (!empty($selected) && $selected != 'none' && !empty(Configuration::get('add_blockchain_fee'))) {
            $est = CryptAPIHelper::get_estimate($selected);

            $feeOrder += (float)$est->{Currency::getDefaultCurrency()->iso_code};
        }

        die(json_encode([
            'fee' => round(CryptAPIHelper::sig_fig($feeOrder, 6), 2) . ' ' . Currency::getDefaultCurrency()->symbol,
            'total' => round(floatval(CryptAPIHelper::sig_fig($total + $feeOrder, 6)), 2) . ' ' . Currency::getDefaultCurrency()->symbol
        ]));
    }
}