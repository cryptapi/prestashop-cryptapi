<?php
/*
* 2007-2015 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author PrestaShop SA <contact@prestashop.com>
*  @copyright  2007-2015 PrestaShop SA
*  @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/


if (!defined('_PS_VERSION_')) {
    exit;
}

class cryptapi extends PaymentModule
{
    protected $_html = '';
    protected $_postErrors = array();

    public $details;
    public $owner;
    public $address;
    public $extra_mail_vars;

    const CRYPTAPI_WAITING = 'CRYPTAPI_WAITING';

    public function __construct()
    {

        $this->name = 'cryptapi';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.0';
        $this->ps_versions_compliancy = array('min' => '1.7', 'max' => '1.7.9.99');
        $this->author = 'CryptAPI';
        $this->controllers = array('state', 'validation', 'callback', 'success', 'cronjob', 'fee');
        $this->is_eu_compatible = 1;

        $this->currencies = true;
        $this->currencies_mode = 'checkbox';

        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('CryptAPI Payment Gateway for PrestaShop');
        $this->description = $this->l('Accept cryptocurrency payments on your PrestaShop website');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?');

        if (!count(Currency::checkPaymentCurrencies($this->id))) {
            $this->warning = $this->l('No currency has been set for this module.');
        }

        require 'lib/CryptAPIHelper.php';
    }

    public function install()
    {

        $db = Db::getInstance();
        if (!parent::install() ||
            !$this->registerHook('paymentOptions') ||
            !$this->registerHook('paymentReturn') ||
            !$this->registerHook('displayAdminOrderTabOrder') ||
            !$this->registerHook('displayAdminOrderTabContent') ||
            !$this->registerHook('displayAdminOrderTabLink')
        ) {
            return false;
        }

        if (!$this->addOrderState()) {
            return false;
        }

        $db->Execute("CREATE TABLE IF NOT EXISTS `" . _DB_PREFIX_ . "cryptapi_order`(
        `order_id` INT NOT NULL,
        `response` TEXT
        )");

        $db->Execute("CREATE TABLE IF NOT EXISTS `" . _DB_PREFIX_ . "cryptapi_coins`(
        `id` int NOT NULL PRIMARY KEY,
        `coins` TEXT )");

        return true;
    }

    public function uninstall()
    {

        /*
        Db::getInstance()->Execute("DROP TABLE IF EXISTS `" . _DB_PREFIX_ . "cryptapi_order`");
        Db::getInstance()->Execute("DROP TABLE IF EXISTS `" . _DB_PREFIX_ . "cryptapi_coins`");
        Db::getInstance()->Execute("DELETE FROM `" . _DB_PREFIX_ . "order_state_lang` WHERE `id_order_state`=" . Configuration::get('CRYPTAPI_WAITING'));
        Db::getInstance()->Execute("DELETE FROM `" . _DB_PREFIX_ . "order_state` WHERE `module_name`='cryptapi'");
        */
        parent::uninstall() &&
        $this->unregisterHook('paymentOptions') &&
        $this->unregisterHook('paymentReturn') &&
        $this->unregisterHook('displayAdminOrderTabOrder') &&
        $this->unregisterHook('displayAdminOrderTabLink') &&
        $this->unregisterHook('displayAdminOrderTabContent') &&
        Configuration::updateValue('active', 0);
        return true;
    }

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submit' . $this->name)) {
            $db = Db::getInstance();
            $save_coins = array();

            $active = (string)Tools::getValue('active');
            $title = (string)Tools::getValue('checkout_title');
            $api_key = (string)Tools::getValue('api_key');
            $show_branding = (string)Tools::getValue('show_branding');
            $add_blockchain_fee = (string)Tools::getValue('add_blockchain_fee');
            $fee_order_percentage = (string)Tools::getValue('fee_order_percentage');
            $qrcode_default = (string)Tools::getValue('qrcode_default');
            $qrcode_setting = (string)Tools::getValue('qrcode_setting');
            $color_scheme = (string)Tools::getValue('color_scheme');
            $disable_conversion = (string)Tools::getValue('disable_conversion');
            $qrcode_size = (string)Tools::getValue('qrcode_size');
            $coins_cache = (string)Tools::getValue('coins_cache');
            $refresh_value_interval = (string)Tools::getValue('refresh_value_interval');
            $order_cancelation_timeout = (string)Tools::getValue('order_cancelation_timeout');
            $cronjob_nonce = Tools::getValue('cronjob_nonce');
            $coins = Tools::getValue('coins');

            if (empty($title) || !Validate::isString($title)) {
                $output = $this->displayError($this->l('Invalid Configuration value'));
                return $output;
            }

            if (empty($coins_cache) || !Validate::isString($coins_cache)) {
                $output = $this->displayError($this->l('Invalid Configuration value'));
                return $output;
            }

            if (empty($qrcode_size) || !Validate::isInt($qrcode_size)) {
                $output = $this->displayError($this->l('Invalid Configuration value. Qr Code Size must be a number.'));
                return $output;
            }

            if (empty($coins)) {
                $output = $this->displayError($this->l('Invalid Configuration value. Please select the cryptocurrencies you want to enable.'));
                return $output;
            }

            if ($color_scheme != 'light' && $color_scheme != 'dark' && $color_scheme != 'auto') {
                $output = $this->displayError($this->l('Invalid Configuration value'));
                return $output;
            }

            foreach ($coins as $selected_coin) {
                $save_coins[] = $selected_coin;
            }

            foreach (json_decode($coins_cache) as $ticker => $coin) {

                // Saving the currency to the dabatase ready to be used by the checkout form

                $cryptocurrency_value = (string)Tools::getValue($ticker . '_address');

                if (!empty($cryptocurrency_value) && !Validate::isString($cryptocurrency_value)) {
                    $output = $this->displayError($this->l('Invalid Cryptocurrency Address'));
                    return $output;
                }

                Configuration::updateValue($ticker . '_address', $cryptocurrency_value);
            }

            // Update the database table with the selected currencies. If row doesn't exist, create new
            if (empty($db->getRow("SELECT * FROM `" . _DB_PREFIX_ . "cryptapi_coins` WHERE id=1"))) {
                $db->Execute("INSERT INTO `" . _DB_PREFIX_ . "cryptapi_coins` (`id`, `coins`) VALUES (1, '" . json_encode($save_coins) . "')");
            } else {
                $db->Execute("UPDATE `" . _DB_PREFIX_ . "cryptapi_coins` SET coins='" . json_encode($save_coins) . "' WHERE id=1");
            }

            Configuration::updateValue('active', $active);
            Configuration::updateValue('checkout_title', $title);
            Configuration::updateValue('api_key', $api_key);
            Configuration::updateValue('add_blockchain_fee', $add_blockchain_fee);
            Configuration::updateValue('fee_order_percentage', $fee_order_percentage);
            Configuration::updateValue('show_branding', $show_branding);
            Configuration::updateValue('qrcode_default', $qrcode_default);
            Configuration::updateValue('qrcode_setting', $qrcode_setting);
            Configuration::updateValue('color_scheme', $color_scheme);
            Configuration::updateValue('qrcode_size', $qrcode_size);
            Configuration::updateValue('refresh_value_interval', $refresh_value_interval);
            Configuration::updateValue('order_cancelation_timeout', $order_cancelation_timeout);
            Configuration::updateValue('disable_conversion', $disable_conversion);
            Configuration::updateValue('coins_cache', $coins_cache);
            Configuration::updateValue('cronjob_nonce', $cronjob_nonce);
            $output = $this->displayConfirmation($this->l('Settings updated'));
        }

        // display any message, then the form
        return $output . $this->displayForm();
    }

    public function displayForm()
    {
        $db = Db::getInstance();

        $cryptocurrencies_api = CryptAPIHelper::get_supported_coins();
        $cryptocurrencies = array();

        foreach ($cryptocurrencies_api as $ticker => $coin) {
            $cryptocurrencies[] = array(
                'id_option' => $ticker,
                'name' => $coin,
            );
        }

        $default_nonce = empty(Tools::getValue('cronjob_nonce', Configuration::get('cronjob_nonce'))) ? cryptapi::generateNonce() : Tools::getValue('cronjob_nonce', Configuration::get('cronjob_nonce'));

        $form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Settings'),
                ),
                'input' => array(
                    array(
                        'type' => 'select',
                        'label' => $this->l('Activate:'),
                        'desc' => $this->l('This enables CryptAPI Payment Gateway'),
                        'name' => 'active',
                        'required' => true,
                        'options' => array(
                            'query' => array(
                                array(
                                    'id_option' => 0,
                                    'name' => $this->trans('No', [], 'Admin.Global')
                                ),
                                array(
                                    'id_option' => 1,
                                    'name' => $this->trans('Yes', [], 'Admin.Global')
                                ),
                            ),
                            'id' => 'id_option',
                            'name' => 'name',
                        )
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Title'),
                        'name' => 'checkout_title',
                        'size' => 20,
                        'required' => true,
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Add the blockchain fee to the order:'),
                        'desc' => $this->l('This will add an estimation of the blockchain fee to the order value'),
                        'name' => 'add_blockchain_fee',
                        'options' => array(
                            'query' => array(
                                array(
                                    'id_option' => 0,
                                    'name' => $this->trans('No', [], 'Admin.Global')
                                ),
                                array(
                                    'id_option' => 1,
                                    'name' => $this->trans('Yes', [], 'Admin.Global')
                                ),
                            ),
                            'id' => 'id_option',
                            'name' => 'name',
                        )
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Service fee manager:'),
                        // 'desc' => $this->l('This will add an estimation of the blockchain fee to the order value'),
                        'name' => 'fee_order_percentage',
                        'options' => array(
                            'query' => array(
                                array(
                                    'id_option' => '0.05',
                                    'name' => $this->trans('5%')
                                ),
                                array(
                                    'id_option' => '0.048',
                                    'name' => $this->trans('4.8%')
                                ),
                                array(
                                    'id_option' => '0.045',
                                    'name' => $this->trans('4.5%')
                                ),
                                array(
                                    'id_option' => '0.045',
                                    'name' => $this->trans('4.5%')
                                ),
                                array(
                                    'id_option' => '0.042',
                                    'name' => $this->trans('4.2%')
                                ),
                                array(
                                    'id_option' => '0.04',
                                    'name' => $this->trans('4%')
                                ),
                                array(
                                    'id_option' => '0.038',
                                    'name' => $this->trans('3.8%')
                                ),
                                array(
                                    'id_option' => '0.035',
                                    'name' => $this->trans('3.5%')
                                ),
                                array(
                                    'id_option' => '0.032',
                                    'name' => $this->trans('3.2%')
                                ),
                                array(
                                    'id_option' => '0.03',
                                    'name' => $this->trans('3%')
                                ),
                                array(
                                    'id_option' => '0.028',
                                    'name' => $this->trans('2.8%')
                                ),
                                array(
                                    'id_option' => '0.025',
                                    'name' => $this->trans('2.5%')
                                ),
                                array(
                                    'id_option' => '0.022',
                                    'name' => $this->trans('2.2%')
                                ),
                                array(
                                    'id_option' => '0.02',
                                    'name' => $this->trans('2%')
                                ),
                                array(
                                    'id_option' => '0.018',
                                    'name' => $this->trans('1.8%')
                                ),
                                array(
                                    'id_option' => '0.015',
                                    'name' => $this->trans('1.5%')
                                ),
                                array(
                                    'id_option' => '0.012',
                                    'name' => $this->trans('1.2%')
                                ),
                                array(
                                    'id_option' => '0.01',
                                    'name' => $this->trans('1%')
                                ),
                                array(
                                    'id_option' => '0.0090',
                                    'name' => $this->trans('0.90%')
                                ),
                                array(
                                    'id_option' => '0.0085',
                                    'name' => $this->trans('0.85%')
                                ),
                                array(
                                    'id_option' => '0.0080',
                                    'name' => $this->trans('0.80%')
                                ),
                                array(
                                    'id_option' => '0.0075',
                                    'name' => $this->trans('0.75%')
                                ),
                                array(
                                    'id_option' => '0.0070',
                                    'name' => $this->trans('0.70%')
                                ),
                                array(
                                    'id_option' => '0.0065',
                                    'name' => $this->trans('0.65%')
                                ),
                                array(
                                    'id_option' => '0.0060',
                                    'name' => $this->trans('0.60%')
                                ),
                                array(
                                    'id_option' => '0.0055',
                                    'name' => $this->trans('0.55%')
                                ),
                                array(
                                    'id_option' => '0.0050',
                                    'name' => $this->trans('0.50%')
                                ),
                                array(
                                    'id_option' => '0.0040',
                                    'name' => $this->trans('0.40%')
                                ),
                                array(
                                    'id_option' => '0.0030',
                                    'name' => $this->trans('0.30%')
                                ),
                                array(
                                    'id_option' => '0.0025',
                                    'name' => $this->trans('0.25%')
                                ),
                                array(
                                    'id_option' => '0',
                                    'name' => $this->trans('0%')
                                ),
                            ),
                            'id' => 'id_option',
                            'name' => 'name',
                        )
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Show CryptAPI branding:'),
                        'desc' => $this->l('Show CryptAPI logo and credits below the QR code'),
                        'name' => 'show_branding',
                        'options' => array(
                            'query' => array(
                                array(
                                    'id_option' => 1,
                                    'name' => $this->trans('Yes', [], 'Admin.Global')
                                ),
                                array(
                                    'id_option' => 0,
                                    'name' => $this->trans('No', [], 'Admin.Global')
                                ),
                            ),
                            'id' => 'id_option',
                            'name' => 'name',
                        )
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('QR Code to show:'),
                        'name' => 'qrcode_setting',
                        'options' => array(
                            'query' => array(
                                array(
                                    'id_option' => 'without_ammount',
                                    'name' => 'Default Without Ammount'
                                ),
                                array(
                                    'id_option' => 'ammount',
                                    'name' => 'Default Ammount'
                                ),
                                array(
                                    'id_option' => 'hide_without_ammount',
                                    'name' => 'Hide Without Ammount'
                                ),
                                array(
                                    'id_option' => 'hide_ammount',
                                    'name' => 'Hide Ammount'
                                ),
                            ),
                            'id' => 'id_option',
                            'name' => 'name',
                        )
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('QR Code by default:'),
                        'desc' => $this->l('Show the QR Code by default'),
                        'name' => 'qrcode_default',
                        'options' => array(
                            'query' => array(
                                array(
                                    'id_option' => 1,
                                    'name' => $this->trans('Yes', [], 'Admin.Global')
                                ),
                                array(
                                    'id_option' => 0,
                                    'name' => $this->trans('No', [], 'Admin.Global')
                                ),
                            ),
                            'id' => 'id_option',
                            'name' => 'name',
                        )
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('QR Code size'),
                        'name' => 'qrcode_size',
                        'required' => true,
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Color Scheme:'),
                        'desc' => $this->l('Selects the color scheme of the plugin to match your website (Light, Dark and Auto to automatically detect it)'),
                        'required' => true,
                        'name' => 'color_scheme',
                        'options' => array(
                            'query' => array(
                                array(
                                    'id_option' => 'light',
                                    'name' => 'Light'
                                ),
                                array(
                                    'id_option' => 'dark',
                                    'name' => 'Dark'
                                ),
                                array(
                                    'id_option' => 'auto',
                                    'name' => 'Auto'
                                ),
                            ),
                            'id' => 'id_option',
                            'name' => 'name',
                        )
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Refresh converted value:'),
                        'desc' => "The system will automatically update the conversion value of the invoices (with real-time data), every X minutes.<br> This feature is helpful whenever a customer takes long time to pay a generated invoice and the selected crypto a volatile coin/token (not stable coin). <br> <strong style='color: #f44336;'>Warning:</strong> Setting this setting to none might create conversion issues, as we advise you to keep it at 5 minutes.</strong><br/><strong>Do not forget to set up the cronjob in your server</strong>",
                        'required' => true,
                        'name' => 'refresh_value_interval',
                        'options' => array(
                            'query' => array(
                                array(
                                    'id_option' => '0',
                                    'name' => 'Never'
                                ),
                                array(
                                    'id_option' => '300',
                                    'name' => 'Every 5 Minutes'
                                ),
                                array(
                                    'id_option' => '600',
                                    'name' => 'Every 10 Minutes'
                                ),
                                array(
                                    'id_option' => '900',
                                    'name' => 'Every 15 Minutes'
                                ),
                                array(
                                    'id_option' => '1800',
                                    'name' => 'Every 30 Minutes'
                                ),
                                array(
                                    'id_option' => '2700',
                                    'name' => 'Every 45 Minutes'
                                ),
                                array(
                                    'id_option' => '3600',
                                    'name' => 'Every 60 Minutes'
                                ),
                            ),
                            'id' => 'id_option',
                            'name' => 'name',
                        )
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Order cancelation timeout:'),
                        'desc' => "Selects the ammount of time the user has to  pay for the order.<br>When this time is over, order will be marked as 'Cancelled' and every paid value will be ignored.<br><strong>Notice:</strong> If the user still sends money to the generated address, value will still be redirected to you.<br><strong style='color: #f44336;'>Warning: </strong>We do not advice more than 1 Hour.",
                        'required' => true,
                        'name' => 'order_cancelation_timeout',
                        'options' => array(
                            'query' => array(
                                array(
                                    'id_option' => '0',
                                    'name' => 'Never'
                                ),
                                array(
                                    'id_option' => '3600',
                                    'name' => '1 Hour'
                                ),
                                array(
                                    'id_option' => '21600',
                                    'name' => '6 Hours'
                                ),
                                array(
                                    'id_option' => '43200',
                                    'name' => '12 Hours'
                                ),
                                array(
                                    'id_option' => '64800',
                                    'name' => '18 Hours'
                                ),
                                array(
                                    'id_option' => '86400',
                                    'name' => '24 Hours'
                                ),
                            ),
                            'id' => 'id_option',
                            'name' => 'name',
                        )
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Disable price conversion:'),
                        'desc' => $this->l('Attention: This option will disable the price conversion for ALL cryptocurrencies! If you check this, pricing will not be converted from the currency of your shop to the cryptocurrency selected by the user, and users will be requested to pay the same value as shown on your shop, regardless of the cryptocurrency selected'),
                        'name' => 'disable_conversion',
                        'options' => array(
                            'query' => array(
                                array(
                                    'id_option' => 0,
                                    'name' => $this->trans('No', [], 'Admin.Global')
                                ),
                                array(
                                    'id_option' => 1,
                                    'name' => $this->trans('Yes', [], 'Admin.Global')
                                ),
                            ),
                            'id' => 'id_option',
                            'name' => 'name',
                        )
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('API Keys'),
                        'name' => 'api_key',
                        'size' => 100,
                        'required' => false,
                        'desc' => 'Insert here your CryptAPI Pro API Key. You can get one here: <a href="https://pro.cryptapi.io/" target="_blank">https://pro.cryptapi.io/</a>. <strong>This field is optional.</strong>',
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Select cryptocurrencies'),
                        'desc' => 'Please select the cryptocurrencies you wish to enable. CTRL + Mouse click to select more than one.<br/><br/><strong>Notice: </strong>If you are using CryptAPI Pro you can choose if setting the receiving addresses here bellow or in your CryptAPI Pro settings page.<br/>- In order to set the addresses on plugin settings, you need to select “Address Override” while creating the API key.<br/>- In order to set the addresses on CryptAPI Pro settings, you need to NOT select “Address Override” while creating the API key.',
                        'name' => 'coins',
                        'multiple' => true,
                        'options' => array(
                            'query' => $cryptocurrencies,
                            'id' => 'id_option',
                            'name' => 'name',
                        ),
                    ),
                    array(
                        'type' => 'hidden',
                        'name' => 'coins_cache'
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                    'class' => 'btn btn-default pull-right',
                ),
            )
        );

        foreach ($cryptocurrencies_api as $ticker => $coin) {
            $form['form']['input'][] = array(
                'type' => 'text',
                'label' => $coin . ' Address',
                'name' => $ticker . '_address',
                'size' => 20,
            );
        }

        $form['form']['input'][] = array(
            'type' => 'text',
            'label' => $this->l('Cronjob Nonce'),
            'desc' => 'Add this string to your cronjob URL when creating the cronjob in your system.<br>The request should look like this: <a href="' . _PS_BASE_URL_ . __PS_BASE_URI__ . 'module/cryptapi/cronjob?nonce=' . $default_nonce . '" target="_blank">' . _PS_BASE_URL_ . __PS_BASE_URI__ . 'module/cryptapi/cronjob?nonce=' . $default_nonce . '</a>',
            'name' => 'cronjob_nonce',
            'required' => true,
        );

        $helper = new HelperForm();


        // Module, token and currentIndex
        $helper->table = $this->table;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&' . http_build_query(['configure' => $this->name]);
        $helper->submit_action = 'submit' . $this->name;

        // Default language
        $helper->default_form_language = (int)Configuration::get('PS_LANG_DEFAULT');

        // Creating this array to preselect the currency
        $coins_db = $db->getRow("SELECT * FROM `" . _DB_PREFIX_ . "cryptapi_coins` WHERE id=1");

        // Load current value into the form
        $helper->fields_value['active'] = empty(Tools::getValue('active', Configuration::get('active'))) ? 0 : Tools::getValue('active', Configuration::get('active'));
        $helper->fields_value['checkout_title'] = empty(Tools::getValue('checkout_title', Configuration::get('checkout_title'))) ? 'Cryptocurrency' : Tools::getValue('checkout_title', Configuration::get('checkout_title'));
        $helper->fields_value['qrcode_size'] = empty(Tools::getValue('qrcode_size', Configuration::get('qrcode_size'))) ? '300' : Tools::getValue('qrcode_size', Configuration::get('qrcode_size'));
        $helper->fields_value['api_key'] = Tools::getValue('api_key', Configuration::get('api_key'));
        $helper->fields_value['add_blockchain_fee'] = empty(Tools::getValue('add_blockchain_fee', Configuration::get('add_blockchain_fee'))) ? 1 : Tools::getValue('add_blockchain_fee', Configuration::get('add_blockchain_fee'));
        $helper->fields_value['fee_order_percentage'] = empty(Tools::getValue('fee_order_percentage', Configuration::get('fee_order_percentage'))) && Tools::getValue('fee_order_percentage', Configuration::get('fee_order_percentage')) !== 0 ? '0.01' : Tools::getValue('fee_order_percentage', Configuration::get('fee_order_percentage'));
        $helper->fields_value['show_branding'] = empty(Tools::getValue('show_branding', Configuration::get('show_branding'))) ? 1 : Tools::getValue('show_branding', Configuration::get('show_branding'));
        $helper->fields_value['qrcode_default'] = empty(Tools::getValue('qrcode_default', Configuration::get('qrcode_default'))) ? 1 : Tools::getValue('qrcode_default', Configuration::get('qrcode_default'));
        $helper->fields_value['color_scheme'] = Tools::getValue('color_scheme', Configuration::get('color_scheme'));
        $helper->fields_value['refresh_value_interval'] = empty(Tools::getValue('refresh_value_interval', Configuration::get('refresh_value_interval'))) && Tools::getValue('refresh_value_interval', Configuration::get('refresh_value_interval')) !== '0' ? '300' : Tools::getValue('refresh_value_interval', Configuration::get('refresh_value_interval'));
        $helper->fields_value['order_cancelation_timeout'] = empty(Tools::getValue('order_cancelation_timeout', Configuration::get('order_cancelation_timeout'))) && Tools::getValue('order_cancelation_timeout', Configuration::get('order_cancelation_timeout')) !== '0' ? '3600' : Tools::getValue('order_cancelation_timeout', Configuration::get('order_cancelation_timeout'));
        $helper->fields_value['disable_conversion'] = Tools::getValue('disable_conversion', Configuration::get('disable_conversion'));
        $helper->fields_value['qrcode_setting'] = Tools::getValue('qrcode_setting', Configuration::get('qrcode_setting'));
        $helper->fields_value['coins_cache'] = json_encode($cryptocurrencies_api);
        $helper->fields_value['cronjob_nonce'] = $default_nonce;

        if (empty($coins_db)) {
            $helper->fields_value['coins[]'] = '';
        } else {
            $helper->fields_value['coins[]'] = json_decode($coins_db['coins'], true);
        }

        foreach ($cryptocurrencies_api as $ticker => $coin) {
            $helper->fields_value[$ticker . '_address'] = Tools::getValue($ticker . '_address', Configuration::get($ticker . '_address'));
        }

        return $helper->generateForm([$form]);
    }

    public function hookPaymentOptions($params)
    {

        if (empty(Configuration::get('active'))) {
            return false;
        }

        if (empty(json_decode(Db::getInstance()->getRow("SELECT * FROM `" . _DB_PREFIX_ . "cryptapi_coins` WHERE id=1")['coins'], true))) {
            return false;
        }

        if (!$this->checkCurrency($params['cart'])) {
            return false;
        }

        $payment_options = [
            $this->getEmbeddedPaymentOption(),
        ];

        return $payment_options;
    }

    public function checkCurrency($cart)
    {
        $currency_order = new Currency($cart->id_currency);
        $currencies_module = $this->getCurrency($cart->id_currency);

        if (is_array($currencies_module)) {
            foreach ($currencies_module as $currency_module) {
                if ($currency_order->id == $currency_module['id_currency']) {
                    return true;
                }
            }
        }
        return false;
    }

    public function getEmbeddedPaymentOption()
    {
        if (!Configuration::get('active')) {
            return false;
        }
        $coins = array();

        $selected = json_decode(Db::getInstance()->getRow("SELECT * FROM `" . _DB_PREFIX_ . "cryptapi_coins` WHERE id=1")['coins'], true);

        foreach (json_decode(Configuration::get('coins_cache'), true) as $ticker => $coin) {
            foreach ($selected as $selected_coin) {
                if ($ticker == $selected_coin) {
                    if (!empty(Configuration::get($ticker . '_address')) || !empty(Configuration::get('api_key'))) {
                        $coins[] = array(
                            'ticker' => $ticker,
                            'coin' => $coin
                        );
                    }
                }
            }
        }

        $embeddedOption = new \PrestaShop\PrestaShop\Core\Payment\PaymentOption();
        $embeddedOption->setCallToActionText(Configuration::get('checkout_title'))->setForm($this->generatePaymentForm($coins));

        return $embeddedOption;
    }

    protected function generatePaymentForm($coins)
    {
        $this->context->smarty->assign([
            'action' => $this->context->link->getModuleLink($this->name, 'validation', array(), true),
            'cryptocurrencies' => $coins,
            'fee' => $this->context->link->getModuleLink($this->name, 'fee', array(), true),
            'js_dir' => Media::getJSPath(_PS_MODULE_DIR_ . '/cryptapi/views/js/cryptapi_cart.js'),
        ]);

        return $this->context->smarty->fetch('module:cryptapi/views/templates/front/payment_form.tpl');
    }

    public function addOrderState()
    {
        if (!Configuration::get(self::CRYPTAPI_WAITING) || !Validate::isLoadedObject(new OrderState(Configuration::get(self::CRYPTAPI_WAITING)))) {
            $order_state = new OrderState();
            $order_state->name = array();
            foreach (Language::getLanguages() as $language) {
                switch (Tools::strtolower($language['iso_code'])) {
                    case 'fr':
                        $order_state->name[$language['id_lang']] = pSQL('En attente de paiement CryptAPI');
                        break;
                    case 'es':
                        $order_state->name[$language['id_lang']] = pSQL('Esperando pago CryptAPI');
                        break;
                    case 'de':
                        $order_state->name[$language['id_lang']] = pSQL('Warten auf CryptAPI-Zahlung');
                        break;
                    case 'nl':
                        $order_state->name[$language['id_lang']] = pSQL('Wachten op CryptAPI-betaling');
                        break;
                    case 'it':
                        $order_state->name[$language['id_lang']] = pSQL('In attesa del pagamento CryptAPI');
                        break;
                    case 'pt':
                        $order_state->name[$language['id_lang']] = pSQL('Aguardando o pagamento da CryptAPI');
                        break;

                    default:
                        $order_state->name[$language['id_lang']] = pSQL('Waiting for CryptAPI payment');
                        break;
                }
            }
            $order_state->invoice = false;
            $order_state->send_email = false;
            $order_state->logable = false;
            $order_state->color = '#258ecd';
            $order_state->module_name = $this->name;
            if ($order_state->add()) {
                $source = __DIR__ . '/cryptapi_payment.png';
                $destination = _PS_ROOT_DIR_ . '/img/os/' . (int)$order_state->id . '.png';
                copy($source, $destination);
            }

            Configuration::updateValue(self::CRYPTAPI_WAITING, $order_state->id);
        }
        return true;
    }

    public static function addPaymentResponse($order_id, $params)
    {
        $db = Db::getInstance();

        $db->Execute("INSERT INTO `" . _DB_PREFIX_ . "cryptapi_order` (`order_id`, `response`) VALUES (" . $order_id . ", '" . $params . "')");
    }

    public static function getPaymentResponse($orderId)
    {
        return Db::getInstance()->getRow("SELECT * FROM `" . _DB_PREFIX_ . "cryptapi_order` WHERE order_id=" . $orderId)['response'];
    }

    public static function updatePaymentResponse($order_id, $param, $value)
    {
        $metaData = cryptapi::getPaymentResponse($order_id);
        if (!empty($metaData)) {
            $metaData = json_decode($metaData, true);
            $metaData[$param] = $value;
            $paymentData = json_encode($metaData);

            $db = Db::getInstance();
            $db->Execute("UPDATE `" . _DB_PREFIX_ . "cryptapi_order` SET response='" . $paymentData . "' WHERE order_id=" . $order_id);
        }
    }

    public static function getAllOrders()
    {
        // Get only CryptAPI Payments that are waiting for payment
        $orders = Db::getInstance()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'orders` WHERE module="cryptapi" AND current_state=' . Configuration::get(self::CRYPTAPI_WAITING));

        return $orders;
    }

    public static function cryptapiCronjob()
    {

        $order_timeout = intval(Configuration::get('order_cancelation_timeout'));
        $value_refresh = intval(Configuration::get('refresh_value_interval'));

        if ($order_timeout === 0 && $value_refresh === 0) {
            die();
        }

        $orders = cryptapi::getAllOrders();

        if (!empty($orders)) {
            $currency = strtolower(Currency::getDefaultCurrency()->iso_code);

            foreach ($orders as $order) {
                $orderId = $order['id_order'];
                $disableConversion = Configuration::get('disable_conversion');
                $qrCodeSize = Configuration::get('qrcode_size');

                $metaData = json_decode(cryptapi::getPaymentResponse($orderId), true);

                if (!empty($metaData['cryptapi_last_price_update'])) {

                    $last_price_update = $metaData['cryptapi_last_price_update'];

                    $historyDb = $metaData['cryptapi_history'];

                    $min_tx = floatval($metaData['cryptapi_min']);

                    $calc = cryptapi::calcOrder($historyDb, $metaData['cryptapi_total'], $metaData['cryptapi_total_fiat']);

                    $remaining = $calc['remaining'];
                    $remaining_pending = $calc['remaining_pending'];
                    $already_paid = $calc['already_paid'];

                    if ($value_refresh !== 0 && $last_price_update + $value_refresh <= time()) {

                        if ($remaining === $remaining_pending) {
                            $cryptapi_coin = $metaData['cryptapi_currency'];

                            $crypto_total = CryptAPIHelper::sig_fig(CryptAPIHelper::get_conversion($currency, $cryptapi_coin, $order['total_paid'], $disableConversion), 6);

                            cryptapi::updatePaymentResponse($orderId, 'cryptapi_total', $crypto_total);

                            $metaData = json_decode(cryptapi::getPaymentResponse($orderId), true);

                            $calc_cron = cryptapi::calcOrder($historyDb, $metaData['cryptapi_total'], $metaData['cryptapi_total_fiat']);

                            $crypto_remaining_total = $calc_cron['remaining_pending'];

                            if ($remaining_pending <= $min_tx && $remaining_pending > 0) {
                                $qr_code_data_value = CryptAPIHelper::get_static_qrcode($metaData['cryptapi_address'], $cryptapi_coin, $min_tx, $qrCodeSize);
                            } else {
                                $qr_code_data_value = CryptAPIHelper::get_static_qrcode($metaData['cryptapi_address'], $cryptapi_coin, $crypto_remaining_total, $qrCodeSize);
                            }

                            cryptapi::updatePaymentResponse($orderId, 'cryptapi_qr_code_value', $qr_code_data_value['qr_code']);
                        }

                        cryptapi::updatePaymentResponse($orderId, 'cryptapi_last_price_update', time());
                    }

                    if ($order_timeout !== 0 && ($metaData['cryptapi_order_created'] + $order_timeout) <= time() && $already_paid <= 0) {
                        $history = new OrderHistory();
                        $history->id_order = $orderId;
                        $history->changeIdOrderState((int)Configuration::get('PS_OS_CANCELED'), $history->id_order, false);
                        $history->addWithemail();
                        $history->save();
                    }

                }
            }
        }

    }

    public static function generateNonce($len = 32)
    {
        $data = str_split('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789');

        $nonce = [];
        for ($i = 0; $i < $len; $i++) {
            $nonce[] = $data[mt_rand(0, sizeof($data) - 1)];
        }

        return implode('', $nonce);
    }

    public static function calcOrder($history, $total, $total_fiat)
    {
        $already_paid = 0;
        $already_paid_fiat = 0;
        $remaining = $total;
        $remaining_pending = $total;
        $remaining_fiat = $total_fiat;

        if (!empty($history)) {
            foreach ($history as $uuid => $item) {
                if ((int)$item['pending'] === 0) {
                    $remaining = bcsub(CryptAPIHelper::sig_fig($remaining, 6), $item['value_paid'], 8);
                }

                $remaining_pending = bcsub(CryptAPIHelper::sig_fig($remaining_pending, 6), $item['value_paid'], 8);
                $remaining_fiat = bcsub(CryptAPIHelper::sig_fig($remaining_fiat, 6), $item['value_paid_fiat'], 8);

                $already_paid = bcadd(CryptAPIHelper::sig_fig($already_paid, 6), $item['value_paid'], 8);
                $already_paid_fiat = bcadd(CryptAPIHelper::sig_fig($already_paid_fiat, 6), $item['value_paid_fiat'], 8);
            }
        }

        return [
            'already_paid' => floatval($already_paid),
            'already_paid_fiat' => floatval($already_paid_fiat),
            'remaining' => floatval($remaining),
            'remaining_pending' => floatval($remaining_pending),
            'remaining_fiat' => floatval($remaining_fiat)
        ];
    }

    public function hookDisplayAdminOrderTabContent($params)
    {
        if (version_compare(_PS_VERSION_, '1.7.7.4', '>=')) {
            $order = new Order($params['id_order']);
        } else {
            $order = $params['order'];
        }

        if ($order->module != 'cryptapi') {
            return;
        }

        $metaData = json_decode(cryptapi::getPaymentResponse($order->id), true);
        $history = $metaData['cryptapi_history'];

        unset($metaData['cryptapi_history']);

        $this->context->smarty->assign(array(
            'meta_data' => $metaData,
            'history' => $history,
        ));

        return $this->display(__FILE__, 'views/templates/back/payment_tab_content.tpl');
    }


    public function hookDisplayAdminOrderTabOrder($params)
    {
        if (version_compare(_PS_VERSION_, '1.7.7.4', '>=')) {
            $order = new Order($params['id_order']);
        } else {
            $order = $params['order'];
        }

        if ($order->module != 'cryptapi') {
            return;
        }

        return $this->display(__FILE__, 'views/templates/back/payment_tab.tpl');
    }

    public function hookDisplayAdminOrderTabLink($params)
    {
        return $this->hookDisplayAdminOrderTabOrder($params);
    }
}
