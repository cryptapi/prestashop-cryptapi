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
if (!defined('_PS_VERSION_')) {
    exit;
}

class cryptapi extends PaymentModule
{
    protected $_html = '';
    protected $_postErrors = [];

    public $details;
    public $owner;
    public $address;
    public $extra_mail_vars;

    public const CRYPTAPI_WAITING = 'CRYPTAPI_WAITING';

    public function __construct()
    {
        $this->name = 'cryptapi';
        $this->tab = 'payments_gateways';
        $this->version = '1.1.0';
        $this->ps_versions_compliancy = ['min' => '1.7', 'max' => '1.7.9.99'];
        $this->author = 'CryptAPI';
        $this->controllers = ['state', 'validation', 'callback', 'success', 'cronjob', 'fee'];
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->bootstrap = true;
        $this->module_key = 'a410c2de952f0ad1dc7c5225f1d69ffd';

        parent::__construct();

        $this->displayName = $this->l('CryptAPI Payment Gateway for PrestaShop', '', 'en');
        $this->description = $this->l('Accept cryptocurrency payments on your PrestaShop website', '', 'en');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?', '', 'en');

        if (!count(Currency::checkPaymentCurrencies($this->id))) {
            $this->warning = $this->l('No currency has been set for this module.', '', 'en');
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

        $db->Execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'cryptapi_order`(
        `order_id` INT NOT NULL,
        `response` TEXT
        )');

        $db->Execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'cryptapi_coins`(
        `id` int NOT NULL PRIMARY KEY,
        `coins` TEXT )');

        return true;
    }

    public function uninstall()
    {
        parent::uninstall() &&
        $this->unregisterHook('paymentOptions') &&
        $this->unregisterHook('paymentReturn') &&
        $this->unregisterHook('displayAdminOrderTabOrder') &&
        $this->unregisterHook('displayAdminOrderTabLink') &&
        $this->unregisterHook('displayAdminOrderTabContent') &&
        Configuration::updateValue('cryptapi_active', 0);

        return true;
    }

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submit' . $this->name)) {
            $db = Db::getInstance();
            $save_coins = [];

            $active = (string) Tools::getValue('cryptapi_active');
            $title = (string) Tools::getValue('cryptapi_checkout_title');
            $api_key = (string) Tools::getValue('cryptapi_api_key');
            $show_branding = (string) Tools::getValue('cryptapi_show_branding');
            $add_blockchain_fee = (string) Tools::getValue('cryptapi_add_blockchain_fee');
            $fee_order_percentage = (string) Tools::getValue('cryptapi_fee_order_percentage');
            $qrcode_default = (string) Tools::getValue('cryptapi_qrcode_default');
            $qrcode_setting = (string) Tools::getValue('cryptapi_qrcode_setting');
            $color_scheme = (string) Tools::getValue('cryptapi_color_scheme');
            $disable_conversion = (string) Tools::getValue('cryptapi_disable_conversion');
            $qrcode_size = (string) Tools::getValue('cryptapi_qrcode_size');
            $coins_cache = (string) Tools::getValue('cryptapi_coins_cache');
            $refresh_value_interval = (string) Tools::getValue('cryptapi_refresh_value_interval');
            $order_cancelation_timeout = (string) Tools::getValue('cryptapi_order_cancelation_timeout');
            $cronjob_nonce = Tools::getValue('cryptapi_cronjob_nonce');
            $coins = Tools::getValue('cryptapi_coins');

            if (empty($title) || !Validate::isString($title)) {
                $output = $this->displayError($this->l('Invalid Configuration value', '', 'en'));

                return $output;
            }

            if (empty($coins_cache) || !Validate::isString($coins_cache)) {
                $output = $this->displayError($this->l('Invalid Configuration value', '', 'en'));

                return $output;
            }

            if (empty($qrcode_size) || !Validate::isInt($qrcode_size)) {
                $output = $this->displayError($this->l('Invalid Configuration value. Qr Code Size must be a number.', '', 'en'));

                return $output;
            }

            if (empty($coins)) {
                $output = $this->displayError($this->l('Invalid Configuration value. Please select the cryptocurrencies you want to enable.', '', 'en'));

                return $output;
            }

            if ($color_scheme != 'light' && $color_scheme != 'dark' && $color_scheme != 'auto') {
                $output = $this->displayError($this->l('Invalid Configuration value', '', 'en'));

                return $output;
            }

            foreach ($coins as $selected_coin) {
                $save_coins[] = $selected_coin;
            }

            foreach (json_decode($coins_cache) as $ticker => $coin) {
                // Saving the currency to the database ready to be used by the checkout form
                $cryptocurrency_value = (string) Tools::getValue('cryptapi_' . $ticker . '_address');

                if (!empty($cryptocurrency_value) && !Validate::isString($cryptocurrency_value)) {
                    $output = $this->displayError($this->l('Invalid Cryptocurrency Address', '', 'en'));

                    return $output;
                }

                Configuration::updateValue('cryptapi_' . $ticker . '_address', $cryptocurrency_value);
            }

            // Update the database table with the selected currencies. If row doesn't exist, create new
            if (empty($db->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'cryptapi_coins` WHERE id=1'))) {
                $db->Execute('INSERT INTO `' . _DB_PREFIX_ . "cryptapi_coins` (`id`, `coins`) VALUES (1, '" . json_encode($save_coins) . "')");
            } else {
                $db->Execute('UPDATE `' . _DB_PREFIX_ . "cryptapi_coins` SET coins='" . json_encode($save_coins) . "' WHERE id=1");
            }

            Configuration::updateValue('cryptapi_active', $active);
            Configuration::updateValue('cryptapi_checkout_title', $title);
            Configuration::updateValue('cryptapi_api_key', $api_key);
            Configuration::updateValue('cryptapi_add_blockchain_fee', $add_blockchain_fee);
            Configuration::updateValue('cryptapi_fee_order_percentage', $fee_order_percentage);
            Configuration::updateValue('cryptapi_show_branding', $show_branding);
            Configuration::updateValue('cryptapi_qrcode_default', $qrcode_default);
            Configuration::updateValue('cryptapi_qrcode_setting', $qrcode_setting);
            Configuration::updateValue('cryptapi_color_scheme', $color_scheme);
            Configuration::updateValue('cryptapi_qrcode_size', $qrcode_size);
            Configuration::updateValue('cryptapi_refresh_value_interval', $refresh_value_interval);
            Configuration::updateValue('cryptapi_order_cancelation_timeout', $order_cancelation_timeout);
            Configuration::updateValue('cryptapi_disable_conversion', $disable_conversion);
            Configuration::updateValue('cryptapi_coins_cache', $coins_cache);
            Configuration::updateValue('cryptapi_cronjob_nonce', $cronjob_nonce);
            $output = $this->displayConfirmation($this->l('Settings updated', '', 'en'));
        }

        // display any message, then the form
        return $output . $this->displayForm();
    }

    public function displayForm()
    {
        $db = Db::getInstance();

        $cryptocurrencies_api = CryptAPIHelper::get_supported_coins();
        $cryptocurrencies = [];

        foreach ($cryptocurrencies_api as $ticker => $coin) {
            $cryptocurrencies[] = [
                'id_option' => $ticker,
                'name' => $coin,
            ];
        }

        $default_nonce = empty(Tools::getValue('cryptapi_cronjob_nonce', Configuration::get('cryptapi_cronjob_nonce'))) ? cryptapi::generateNonce() : Tools::getValue('cryptapi_cronjob_nonce', Configuration::get('cryptapi_cronjob_nonce'));

        $form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Settings', '', 'en'),
                ],
                'input' => [
                    [
                        'type' => 'select',
                        'label' => $this->l('Activate:', '', 'en'),
                        'desc' => $this->l('This enables CryptAPI Payment Gateway', '', 'en'),
                        'name' => 'cryptapi_active',
                        'required' => true,
                        'options' => [
                            'query' => [
                                [
                                    'id_option' => 0,
                                    'name' => $this->trans('No', [], 'Admin.Global'),
                                ],
                                [
                                    'id_option' => 1,
                                    'name' => $this->trans('Yes', [], 'Admin.Global'),
                                ],
                            ],
                            'id' => 'id_option',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Title', '', 'en'),
                        'name' => 'cryptapi_checkout_title',
                        'size' => 20,
                        'required' => true,
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Add the blockchain fee to the order:', '', 'en'),
                        'desc' => $this->l('This will add an estimation of the blockchain fee to the order value', '', 'en'),
                        'name' => 'cryptapi_add_blockchain_fee',
                        'options' => [
                            'query' => [
                                [
                                    'id_option' => 0,
                                    'name' => $this->trans('No', [], 'Admin.Global'),
                                ],
                                [
                                    'id_option' => 1,
                                    'name' => $this->trans('Yes', [], 'Admin.Global'),
                                ],
                            ],
                            'id' => 'id_option',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Service fee manager:', '', 'en'),
                        'name' => 'cryptapi_fee_order_percentage',
                        'options' => [
                            'query' => [
                                [
                                    'id_option' => '0.05',
                                    'name' => '5%',
                                ],
                                [
                                    'id_option' => '0.048',
                                    'name' => '4.8%',
                                ],
                                [
                                    'id_option' => '0.045',
                                    'name' => '4.5%',
                                ],
                                [
                                    'id_option' => '0.045',
                                    'name' => '4.5%',
                                ],
                                [
                                    'id_option' => '0.042',
                                    'name' => '4.2%',
                                ],
                                [
                                    'id_option' => '0.04',
                                    'name' => '4%',
                                ],
                                [
                                    'id_option' => '0.038',
                                    'name' => '3.8%',
                                ],
                                [
                                    'id_option' => '0.035',
                                    'name' => '3.5%',
                                ],
                                [
                                    'id_option' => '0.032',
                                    'name' => '3.2%',
                                ],
                                [
                                    'id_option' => '0.03',
                                    'name' => '3%',
                                ],
                                [
                                    'id_option' => '0.028',
                                    'name' => '2.8%',
                                ],
                                [
                                    'id_option' => '0.025',
                                    'name' => '2.5%',
                                ],
                                [
                                    'id_option' => '0.022',
                                    'name' => '2.2%',
                                ],
                                [
                                    'id_option' => '0.02',
                                    'name' => '2%',
                                ],
                                [
                                    'id_option' => '0.018',
                                    'name' => '1.8%',
                                ],
                                [
                                    'id_option' => '0.015',
                                    'name' => '1.5%',
                                ],
                                [
                                    'id_option' => '0.012',
                                    'name' => '1.2%',
                                ],
                                [
                                    'id_option' => '0.01',
                                    'name' => '1%',
                                ],
                                [
                                    'id_option' => '0.0090',
                                    'name' => '0.90%',
                                ],
                                [
                                    'id_option' => '0.0085',
                                    'name' => '0.85%',
                                ],
                                [
                                    'id_option' => '0.0080',
                                    'name' => '0.80%',
                                ],
                                [
                                    'id_option' => '0.0075',
                                    'name' => '0.75%',
                                ],
                                [
                                    'id_option' => '0.0070',
                                    'name' => '0.70%',
                                ],
                                [
                                    'id_option' => '0.0065',
                                    'name' => '0.65%',
                                ],
                                [
                                    'id_option' => '0.0060',
                                    'name' => '0.60%',
                                ],
                                [
                                    'id_option' => '0.0055',
                                    'name' => '0.55%',
                                ],
                                [
                                    'id_option' => '0.0050',
                                    'name' => '0.50%',
                                ],
                                [
                                    'id_option' => '0.0040',
                                    'name' => '0.40%',
                                ],
                                [
                                    'id_option' => '0.0030',
                                    'name' => '0.30%',
                                ],
                                [
                                    'id_option' => '0.0025',
                                    'name' => '0.25%',
                                ],
                                [
                                    'id_option' => '0',
                                    'name' => '0%',
                                ],
                            ],
                            'id' => 'id_option',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Show CryptAPI branding:', '', 'en'),
                        'desc' => $this->l('Show CryptAPI logo and credits below the QR code', '', 'en'),
                        'name' => 'cryptapi_show_branding',
                        'options' => [
                            'query' => [
                                [
                                    'id_option' => 1,
                                    'name' => $this->trans('Yes', [], 'Admin.Global'),
                                ],
                                [
                                    'id_option' => 0,
                                    'name' => $this->trans('No', [], 'Admin.Global'),
                                ],
                            ],
                            'id' => 'id_option',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('QR Code to show:', '', 'en'),
                        'name' => 'cryptapi_qrcode_setting',
                        'options' => [
                            'query' => [
                                [
                                    'id_option' => 'without_amount',
                                    'name' => 'Default Without Amount',
                                ],
                                [
                                    'id_option' => 'amount',
                                    'name' => 'Default Amount',
                                ],
                                [
                                    'id_option' => 'hide_without_amount',
                                    'name' => 'Hide Without Amount',
                                ],
                                [
                                    'id_option' => 'hide_amount',
                                    'name' => 'Hide Amount',
                                ],
                            ],
                            'id' => 'id_option',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('QR Code by default:', '', 'en'),
                        'desc' => $this->l('Show the QR Code by default', '', 'en'),
                        'name' => 'cryptapi_qrcode_default',
                        'options' => [
                            'query' => [
                                [
                                    'id_option' => 1,
                                    'name' => $this->trans('Yes', [], 'Admin.Global'),
                                ],
                                [
                                    'id_option' => 0,
                                    'name' => $this->trans('No', [], 'Admin.Global'),
                                ],
                            ],
                            'id' => 'id_option',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('QR Code size', '', 'en'),
                        'name' => 'cryptapi_qrcode_size',
                        'required' => true,
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Color Scheme:', '', 'en'),
                        'desc' => $this->l('Selects the color scheme of the plugin to match your website (Light, Dark and Auto to automatically detect it)', '', 'en'),
                        'required' => true,
                        'name' => 'cryptapi_color_scheme',
                        'options' => [
                            'query' => [
                                [
                                    'id_option' => 'light',
                                    'name' => $this->l('Light', '', 'en'),
                                ],
                                [
                                    'id_option' => 'dark',
                                    'name' => $this->l('Dark', '', 'en'),
                                ],
                                [
                                    'id_option' => 'auto',
                                    'name' => 'Auto',
                                ],
                            ],
                            'id' => 'id_option',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Refresh converted value:', '', 'en'),
                        'desc' => sprintf($this->l('The system will automatically update the conversion value of the invoices (with real-time data), every X minutes. %1$s This feature is helpful whenever a customer takes long time to pay a generated invoice and the selected crypto a volatile coin/token (not stable coin). %1$s %2$s Warning: %3$s Setting this setting to none might create conversion issues, as we advise you to keep it at 5 minutes. %1$s %4$s Do not forget to set up the cronjob in your server %3$s', '', 'en'), '<br/>', '<strong style="color: #f44336;">', '</strong>', '<strong>'),
                        'required' => true,
                        'name' => 'cryptapi_refresh_value_interval',
                        'options' => [
                            'query' => [
                                [
                                    'id_option' => '0',
                                    'name' => $this->l('Never', '', 'en'),
                                ],
                                [
                                    'id_option' => '300',
                                    'name' => $this->l('Every 5 Minutes', '', 'en'),
                                ],
                                [
                                    'id_option' => '600',
                                    'name' => $this->l('Every 10 Minutes', '', 'en'),
                                ],
                                [
                                    'id_option' => '900',
                                    'name' => $this->l('Every 15 Minutes', '', 'en'),
                                ],
                                [
                                    'id_option' => '1800',
                                    'name' => $this->l('Every 30 Minutes', '', 'en'),
                                ],
                                [
                                    'id_option' => '2700',
                                    'name' => $this->l('Every 45 Minutes', '', 'en'),
                                ],
                                [
                                    'id_option' => '3600',
                                    'name' => $this->l('Every 60 Minutes', '', 'en'),
                                ],
                            ],
                            'id' => 'id_option',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Order cancelation timeout:', '', 'en'),
                        'desc' => sprintf($this->l('Selects the amount of time the user has to pay for the order. %1$s When this time is over, order will be marked as "Canceled" and every paid value will be ignored. %1$s %2$s Notice: %3$s If the user still sends money to the generated address. Value will still be redirected to you.', '', 'en'), '<br/>', '<strong>', '</strong>'),
                        'required' => true,
                        'name' => 'cryptapi_order_cancelation_timeout',
                        'options' => [
                            'query' => [
                                [
                                    'id_option' => '0',
                                    'name' => $this->l('Never', '', 'en'),
                                ],
                                [
                                    'id_option' => '3600',
                                    'name' => $this->l('1 Hour', '', 'en'),
                                ],
                                [
                                    'id_option' => '21600',
                                    'name' => $this->l('6 Hours', '', 'en'),
                                ],
                                [
                                    'id_option' => '43200',
                                    'name' => $this->l('12 Hours', '', 'en'),
                                ],
                                [
                                    'id_option' => '64800',
                                    'name' => $this->l('18 Hours', '', 'en'),
                                ],
                                [
                                    'id_option' => '86400',
                                    'name' => $this->l('24 Hours', '', 'en'),
                                ],
                            ],
                            'id' => 'id_option',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Disable price conversion:', '', 'en'),
                        'desc' => $this->l('Attention: This option will disable the price conversion for ALL cryptocurrencies! If you check this, pricing will not be converted from the currency of your shop to the cryptocurrency selected by the user, and users will be requested to pay the same value as shown on your shop, regardless of the cryptocurrency selected', '', 'en'),
                        'name' => 'cryptapi_disable_conversion',
                        'options' => [
                            'query' => [
                                [
                                    'id_option' => 0,
                                    'name' => $this->trans('No', [], 'Admin.Global'),
                                ],
                                [
                                    'id_option' => 1,
                                    'name' => $this->trans('Yes', [], 'Admin.Global'),
                                ],
                            ],
                            'id' => 'id_option',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('API Keys', '', 'en'),
                        'name' => 'cryptapi_api_key',
                        'size' => 100,
                        'required' => false,
                        'desc' => sprintf($this->l('Insert here your BlockBee API Key. You can get one here: %1$s. %2$sThis field is optional.%3$s', '', 'en'), '<a href="https://dash.blockbee.io/" target="_blank">https://dash.blockbee.io/</a>', '<strong>', '</strong>'),
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Select cryptocurrencies', '', 'en'),
                        'desc' => sprintf($this->l('Please select the cryptocurrencies you wish to enable. CTRL + Mouse click to select more than one. %1$s %1$s %2$sNotice: %3$sIf you are using BlockBee you can choose if setting the receiving addresses here bellow or in your BlockBee settings page. %1$s In order to set the addresses on plugin settings, you need to select “Address Override” while creating the API key. %1$s In order to set the addresses on BlockBee settings, you need to NOT select “Address Override” while creating the API key.', '', 'en'), '<br/>', '<strong>', '</strong>'),
                        'name' => 'cryptapi_coins',
                        'multiple' => true,
                        'options' => [
                            'query' => $cryptocurrencies,
                            'id' => 'id_option',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'hidden',
                        'name' => 'cryptapi_coins_cache',
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save', '', 'en'),
                    'class' => 'btn btn-default pull-right',
                ],
            ],
        ];

        foreach ($cryptocurrencies_api as $ticker => $coin) {
            $form['form']['input'][] = [
                'type' => 'text',
                'label' => $coin . ' Address',
                'name' => 'cryptapi_' . $ticker . '_address',
                'size' => 20,
            ];
        }

        $form['form']['input'][] = [
            'type' => 'text',
            'label' => $this->l('Cronjob Nonce', '', 'en'),
            'desc' => sprintf($this->l('Add this string to your cronjob URL when creating the cronjob in your system. %1$s The request should look like this: %2$s', '', 'en'), '<br/>', '<a href="' . _PS_BASE_URL_ . __PS_BASE_URI__ . 'module/cryptapi/cronjob?nonce=' . $default_nonce . '" target="_blank">' . _PS_BASE_URL_ . __PS_BASE_URI__ . 'module/cryptapi/cronjob?nonce=' . $default_nonce . '</a>'),
            'name' => 'cryptapi_cronjob_nonce',
            'required' => true,
        ];

        $helper = new HelperForm();

        // Module, token and currentIndex
        $helper->table = $this->table;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&' . http_build_query(['configure' => $this->name]);
        $helper->submit_action = 'submit' . $this->name;

        // Default language
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');

        // Creating this array to preselect the currency
        $coins_db = $db->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'cryptapi_coins` WHERE id=1');

        // Load current value into the form
        $helper->fields_value['cryptapi_active'] = empty(Tools::getValue('cryptapi_active', Configuration::get('cryptapi_active'))) ? 0 : Tools::getValue('cryptapi_active', Configuration::get('cryptapi_active'));
        $helper->fields_value['cryptapi_checkout_title'] = empty(Tools::getValue('cryptapi_checkout_title', Configuration::get('cryptapi_checkout_title'))) ? 'Cryptocurrency' : Tools::getValue('cryptapi_checkout_title', Configuration::get('cryptapi_checkout_title'));
        $helper->fields_value['cryptapi_qrcode_size'] = empty(Tools::getValue('cryptapi_qrcode_size', Configuration::get('cryptapi_qrcode_size'))) ? '300' : Tools::getValue('cryptapi_qrcode_size', Configuration::get('cryptapi_qrcode_size'));
        $helper->fields_value['cryptapi_api_key'] = Tools::getValue('cryptapi_api_key', Configuration::get('cryptapi_api_key'));
        $helper->fields_value['cryptapi_add_blockchain_fee'] = empty(Tools::getValue('cryptapi_add_blockchain_fee', Configuration::get('cryptapi_add_blockchain_fee'))) ? 1 : Tools::getValue('cryptapi_add_blockchain_fee', Configuration::get('cryptapi_add_blockchain_fee'));
        $helper->fields_value['cryptapi_fee_order_percentage'] = empty(Tools::getValue('cryptapi_fee_order_percentage', Configuration::get('cryptapi_fee_order_percentage'))) && Tools::getValue('cryptapi_fee_order_percentage', Configuration::get('cryptapi_fee_order_percentage')) !== 0 ? '0' : Tools::getValue('cryptapi_fee_order_percentage', Configuration::get('cryptapi_fee_order_percentage'));
        $helper->fields_value['cryptapi_show_branding'] = empty(Tools::getValue('cryptapi_show_branding', Configuration::get('cryptapi_show_branding'))) ? 1 : Tools::getValue('cryptapi_show_branding', Configuration::get('cryptapi_show_branding'));
        $helper->fields_value['cryptapi_qrcode_default'] = empty(Tools::getValue('cryptapi_qrcode_default', Configuration::get('cryptapi_qrcode_default'))) ? 1 : Tools::getValue('cryptapi_qrcode_default', Configuration::get('cryptapi_qrcode_default'));
        $helper->fields_value['cryptapi_color_scheme'] = Tools::getValue('cryptapi_color_scheme', Configuration::get('cryptapi_color_scheme'));
        $helper->fields_value['cryptapi_refresh_value_interval'] = empty(Tools::getValue('cryptapi_refresh_value_interval', Configuration::get('cryptapi_refresh_value_interval'))) && Tools::getValue('cryptapi_refresh_value_interval', Configuration::get('cryptapi_refresh_value_interval')) !== '0' ? '0' : Tools::getValue('cryptapi_refresh_value_interval', Configuration::get('cryptapi_refresh_value_interval'));
        $helper->fields_value['cryptapi_order_cancelation_timeout'] = empty(Tools::getValue('cryptapi_order_cancelation_timeout', Configuration::get('cryptapi_order_cancelation_timeout'))) && Tools::getValue('cryptapi_order_cancelation_timeout', Configuration::get('cryptapi_order_cancelation_timeout')) !== '0' ? '0' : Tools::getValue('cryptapi_order_cancelation_timeout', Configuration::get('cryptapi_order_cancelation_timeout'));
        $helper->fields_value['cryptapi_disable_conversion'] = Tools::getValue('cryptapi_disable_conversion', Configuration::get('cryptapi_disable_conversion'));
        $helper->fields_value['cryptapi_qrcode_setting'] = Tools::getValue('cryptapi_qrcode_setting', Configuration::get('cryptapi_qrcode_setting'));
        $helper->fields_value['cryptapi_coins_cache'] = json_encode($cryptocurrencies_api);
        $helper->fields_value['cryptapi_cronjob_nonce'] = $default_nonce;

        if (empty($coins_db)) {
            $helper->fields_value['cryptapi_coins[]'] = '';
        } else {
            $helper->fields_value['cryptapi_coins[]'] = json_decode($coins_db['coins'], true);
        }

        foreach ($cryptocurrencies_api as $ticker => $coin) {
            $helper->fields_value['cryptapi_' . $ticker . '_address'] = Tools::getValue('cryptapi_' . $ticker . '_address', Configuration::get('cryptapi_' . $ticker . '_address'));
        }

        return $helper->generateForm([$form]);
    }

    public function hookPaymentOptions($params)
    {
        if (empty(Configuration::get('cryptapi_active'))) {
            return false;
        }

        if (empty(json_decode(Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'cryptapi_coins` WHERE id=1')['coins'], true))) {
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
        if (!Configuration::get('cryptapi_active')) {
            return false;
        }
        $coins = [];

        $selected = json_decode(Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'cryptapi_coins` WHERE id=1')['coins'], true);

        foreach (json_decode(Configuration::get('cryptapi_coins_cache'), true) as $ticker => $coin) {
            foreach ($selected as $selected_coin) {
                if ($ticker == $selected_coin) {
                    if (!empty(Configuration::get('cryptapi_' . $ticker . '_address')) || !empty(Configuration::get('cryptapi_api_key'))) {
                        $coins[] = [
                            'ticker' => $ticker,
                            'coin' => $coin,
                        ];
                    }
                }
            }
        }

        $embeddedOption = new \PrestaShop\PrestaShop\Core\Payment\PaymentOption();
        $embeddedOption->setCallToActionText(Configuration::get('cryptapi_checkout_title'))->setForm($this->generatePaymentForm($coins));

        return $embeddedOption;
    }

    protected function generatePaymentForm($coins)
    {
        $this->context->smarty->assign([
            'action' => $this->context->link->getModuleLink($this->name, 'validation', [], true),
            'cryptocurrencies' => $coins,
            'fee' => $this->context->link->getModuleLink($this->name, 'fee', [], true),
            'js_dir' => Media::getJSPath(_PS_MODULE_DIR_ . '/cryptapi/views/js/cryptapi_cart.js'),
        ]);

        return $this->context->smarty->fetch('module:cryptapi/views/templates/front/payment_form.tpl');
    }

    public function addOrderState()
    {
        if (!Configuration::get(self::CRYPTAPI_WAITING) || !Validate::isLoadedObject(new OrderState(Configuration::get(self::CRYPTAPI_WAITING)))) {
            $order_state = new OrderState();
            $order_state->name = [];
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
                $source = __DIR__ . '/views/img/cryptapi_payment.png';
                $destination = _PS_ROOT_DIR_ . '/img/os/' . (int) $order_state->id . '.png';
                copy($source, $destination);
            }

            Configuration::updateValue(self::CRYPTAPI_WAITING, $order_state->id);
        }

        return true;
    }

    public static function addPaymentResponse($order_id, $params)
    {
        $db = Db::getInstance();

        $db->Execute('INSERT INTO `' . _DB_PREFIX_ . 'cryptapi_order` (`order_id`, `response`) VALUES (' . $order_id . ", '" . $params . "')");
    }

    public static function getPaymentResponse($orderId)
    {
        return Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'cryptapi_order` WHERE order_id=' . $orderId)['response'];
    }

    public static function updatePaymentResponse($order_id, $param, $value)
    {
        $metaData = cryptapi::getPaymentResponse($order_id);
        if (!empty($metaData)) {
            $metaData = json_decode($metaData, true);
            $metaData[$param] = $value;
            $paymentData = json_encode($metaData);

            $db = Db::getInstance();
            $db->Execute('UPDATE `' . _DB_PREFIX_ . "cryptapi_order` SET response='" . $paymentData . "' WHERE order_id=" . $order_id);
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
        $order_timeout = (int) Configuration::get('cryptapi_order_cancelation_timeout');
        $value_refresh = (int) Configuration::get('cryptapi_refresh_value_interval');

        if ((int) $order_timeout === 0 && (int) $value_refresh === 0) {
            exit;
        }

        $orders = cryptapi::getAllOrders();

        if (!empty($orders)) {
            $currency = strtolower(Currency::getDefaultCurrency()->iso_code);

            foreach ($orders as $order) {
                $orderId = $order['id_order'];
                $disableConversion = Configuration::get('cryptapi_disable_conversion');
                $qrCodeSize = Configuration::get('cryptapi_qrcode_size');

                $metaData = json_decode(cryptapi::getPaymentResponse($orderId), true);

                if (!empty($metaData['cryptapi_last_price_update'])) {
                    $last_price_update = $metaData['cryptapi_last_price_update'];

                    $historyDb = $metaData['cryptapi_history'];

                    $min_tx = (float) $metaData['cryptapi_min'];

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
                        $history->changeIdOrderState((int) Configuration::get('PS_OS_CANCELED'), $history->id_order, false);
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
        for ($i = 0; $i < $len; ++$i) {
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
                if ((int) $item['pending'] === 0) {
                    $remaining = bcsub(CryptAPIHelper::sig_fig($remaining, 6), $item['value_paid'], 8);
                }

                $remaining_pending = bcsub(CryptAPIHelper::sig_fig($remaining_pending, 6), $item['value_paid'], 8);
                $remaining_fiat = bcsub(CryptAPIHelper::sig_fig($remaining_fiat, 6), $item['value_paid_fiat'], 8);

                $already_paid = bcadd(CryptAPIHelper::sig_fig($already_paid, 6), $item['value_paid'], 8);
                $already_paid_fiat = bcadd(CryptAPIHelper::sig_fig($already_paid_fiat, 6), $item['value_paid_fiat'], 8);
            }
        }

        return [
            'already_paid' => (float) $already_paid,
            'already_paid_fiat' => (float) $already_paid_fiat,
            'remaining' => (float) $remaining,
            'remaining_pending' => (float) $remaining_pending,
            'remaining_fiat' => (float) $remaining_fiat,
        ];
    }

    public static function sendMail($orderId)
    {
        $order = new Order((int) $orderId);
        $customer = $order->getCustomer();
        $customerMail = $customer->email;
        $customerName = $customer->firstname . ' ' . $customer->lastname;

        try {
            $metaData = json_decode(cryptapi::getPaymentResponse($orderId), true);
        } catch (Exception $e) {
            return;
        }

        Mail::Send(
            (int) $order->id_lang,
            'cryptapi_link',
            Translate::getModuleTranslation('cryptapi', 'New Order %1$s. Please send a %2$s payment', 'cryptapi', [
                $order->reference, strtoupper($metaData['cryptapi_currency']),
            ]),
            [
                '{email}' => Configuration::get('PS_SHOP_EMAIL'),
                '{firstname}' => $customer->firstname,
                '{lastname}' => $customer->lastname,
                '{order}' => $order->reference,
                '{coin}' => strtoupper($metaData['cryptapi_currency']),
                '{url}' => $metaData['cryptapi_payment_url'],
            ],
            $customerMail,
            $customerName,
            Configuration::get('PS_SHOP_EMAIL'),
            Configuration::get('PS_SHOP_NAME'),
            null,
            null,
            _PS_MODULE_DIR_ . 'cryptapi/mails'
        );
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

        $this->context->smarty->assign([
            'meta_data' => $metaData,
            'history' => $history,
        ]);

        return $this->display(__FILE__, 'views/templates/admin/payment_tab_content.tpl');
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

        return $this->display(__FILE__, 'views/templates/admin/payment_tab.tpl');
    }

    public function hookDisplayAdminOrderTabLink($params)
    {
        return $this->hookDisplayAdminOrderTabOrder($params);
    }
}
