<?php

class CryptAPICronjobModuleFrontController extends ModuleFrontController
{

    public function initContent()
    {
        $nonce = $_REQUEST['nonce'];
        $saved_nonce = Configuration::get('cronjob_nonce');

        // In case the Nonce is missing from the url, it redirects to the home page and dies
        if ($nonce !== $saved_nonce) {
            Tools::redirect(_PS_BASE_URL_ . __PS_BASE_URI__);

        }

        cryptapi::cryptapiCronjob();
        die('*ok*');
    }
}