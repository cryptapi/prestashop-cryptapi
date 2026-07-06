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

require_once 'lib/CryptAPIHelper.php';

class cryptapi extends PaymentModule
{
    protected $_html = '';

    public $details;
    public $owner;
    public $address;
    public $extra_mail_vars;

    public const CRYPTAPI_WAITING = 'CRYPTAPI_WAITING';

    public function __construct()
    {
        $this->name = 'cryptapi';
        $this->tab = 'payments_gateways';
        $this->version = '1.4.0';
        $this->ps_versions_compliancy = ['min' => '1.7', 'max' => '9.99.99'];
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
    }

    public function install()
    {
        $db = Db::getInstance();
        if (
            !parent::install() ||
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

        if (!$db->Execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'cryptapi_order`(
            `order_id` INT NOT NULL,
            `response` TEXT,
            PRIMARY KEY (`order_id`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4')) {
            return false;
        }

        if (!$db->Execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'cryptapi_coins`(
            `id` INT NOT NULL PRIMARY KEY,
            `coins` TEXT
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4')) {
            return false;
        }

        return true;
    }

    public function uninstall()
    {
        Configuration::deleteByName('cryptapi_pubkey');
        Configuration::deleteByName('cryptapi_pubkey_fetched_at');

        return parent::uninstall()
            && $this->unregisterHook('paymentOptions')
            && $this->unregisterHook('paymentReturn')
            && $this->unregisterHook('displayAdminOrderTabOrder')
            && $this->unregisterHook('displayAdminOrderTabLink')
            && $this->unregisterHook('displayAdminOrderTabContent')
            && Configuration::updateValue('cryptapi_active', 0);
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

            // Validation — on any failure show the error AND re-render the form so
            // the merchant keeps their input and can correct it (never a bare error
            // page with no form).
            if (empty($title) || !Validate::isString($title)) {
                return $this->displayError($this->l('Please enter a valid checkout title.', '', 'en')) . $this->displayForm();
            }

            if (empty($coins_cache) || !Validate::isString($coins_cache)) {
                return $this->displayError($this->l('Could not read the cryptocurrency list (it came back empty). Reload the settings page and try again — if it persists, the CryptAPI API may be unreachable.', '', 'en')) . $this->displayForm();
            }

            if (empty($qrcode_size) || !Validate::isInt($qrcode_size)) {
                return $this->displayError($this->l('Invalid Configuration value. Qr Code Size must be a number.', '', 'en')) . $this->displayForm();
            }

            if (empty($coins)) {
                return $this->displayError($this->l('Invalid Configuration value. Please select the cryptocurrencies you want to enable.', '', 'en')) . $this->displayForm();
            }

            if (!in_array($color_scheme, ['light', 'dark', 'auto'], true)) {
                return $this->displayError($this->l('Please choose a valid color scheme (light, dark or auto).', '', 'en')) . $this->displayForm();
            }

            foreach ($coins as $selected_coin) {
                $save_coins[] = $selected_coin;
            }

            $decoded_coins_cache = json_decode($coins_cache, true);
            if (!is_array($decoded_coins_cache)) {
                return $this->displayError($this->l('The cryptocurrency list could not be parsed. Reload the settings page and try again.', '', 'en')) . $this->displayForm();
            }

            // Validate tickers/names/addresses first and collect the address writes,
            // so nothing is persisted until every submitted value is known valid.
            $addressesToSave = [];
            foreach ($decoded_coins_cache as $ticker => $coin) {
                // Whitelist the ticker/name values before they are cached and later
                // rendered in the checkout form. Tickers are alphanumeric plus _ . -
                // (real CryptAPI tickers include dots for bridged tokens, e.g.
                // arbitrum_usdc.e); the name must be a safe generic display name.
                // This still blocks HTML/JS (< > " ' & / space …) while accepting
                // every legitimate coin.
                if (!preg_match('/^[a-zA-Z0-9_.-]+$/', (string) $ticker) || !Validate::isGenericName((string) $coin)) {
                    return $this->displayError(
                        sprintf($this->l('Rejected cryptocurrency entry "%1$s" => "%2$s" (unexpected characters). Reload the settings page and try again.', '', 'en'), (string) $ticker, (string) $coin)
                    ) . $this->displayForm();
                }

                $configKey = cryptapi::coinAddressConfigKey($ticker);
                $cryptocurrency_value = trim((string) Tools::getValue($configKey));

                if (!cryptapi::validateCryptoAddress($ticker, $cryptocurrency_value)) {
                    return $this->displayError(
                        sprintf($this->l('Invalid %s address: please double-check the wallet address before saving.', '', 'en'), strtoupper($ticker))
                    ) . $this->displayForm();
                }

                $addressesToSave[$configKey] = $cryptocurrency_value;
            }

            // Persist everything. Each write is checked and the whole block is
            // guarded so a DB failure surfaces as a clear admin error (plus a log
            // entry) instead of a fatal PrestaShopDatabaseException (dev mode) or a
            // false "Settings updated" (prod, where failed writes return false).
            try {
                $ok = true;

                foreach ($addressesToSave as $configKey => $address) {
                    $ok = Configuration::updateValue($configKey, $address) && $ok;
                }

                // Update the selected-currencies row; create it if it doesn't exist.
                if (empty($db->getRow('SELECT id FROM `' . _DB_PREFIX_ . 'cryptapi_coins` WHERE id=1'))) {
                    $ok = $db->insert('cryptapi_coins', [
                        'id' => 1,
                        'coins' => json_encode($save_coins),
                    ]) && $ok;
                } else {
                    $ok = $db->update('cryptapi_coins', [
                        'coins' => json_encode($save_coins),
                    ], 'id=1') && $ok;
                }

                $ok = Configuration::updateValue('cryptapi_active', $active) && $ok;
                $ok = Configuration::updateValue('cryptapi_checkout_title', $title) && $ok;
                $ok = Configuration::updateValue('cryptapi_api_key', $api_key) && $ok;
                $ok = Configuration::updateValue('cryptapi_add_blockchain_fee', $add_blockchain_fee) && $ok;
                $ok = Configuration::updateValue('cryptapi_fee_order_percentage', $fee_order_percentage) && $ok;
                $ok = Configuration::updateValue('cryptapi_show_branding', $show_branding) && $ok;
                $ok = Configuration::updateValue('cryptapi_qrcode_default', $qrcode_default) && $ok;
                $ok = Configuration::updateValue('cryptapi_qrcode_setting', $qrcode_setting) && $ok;
                $ok = Configuration::updateValue('cryptapi_color_scheme', $color_scheme) && $ok;
                $ok = Configuration::updateValue('cryptapi_qrcode_size', $qrcode_size) && $ok;
                $ok = Configuration::updateValue('cryptapi_refresh_value_interval', $refresh_value_interval) && $ok;
                $ok = Configuration::updateValue('cryptapi_order_cancelation_timeout', $order_cancelation_timeout) && $ok;
                $ok = Configuration::updateValue('cryptapi_disable_conversion', $disable_conversion) && $ok;
                $ok = Configuration::updateValue('cryptapi_coins_cache', $coins_cache) && $ok;
                $ok = Configuration::updateValue('cryptapi_cronjob_nonce', $cronjob_nonce) && $ok;

                if (!$ok) {
                    throw new PrestaShopException('One or more settings could not be written to the database.');
                }

                $output = $this->displayConfirmation($this->l('Settings updated', '', 'en'));
            } catch (Exception $e) {
                PrestaShopLogger::addLog('CryptAPI: settings save failed — ' . $e->getMessage(), 3);
                $output = $this->displayError(
                    $this->l('Could not save your settings. Please try again; if the problem persists, check Advanced Parameters → Logs for details.', '', 'en')
                );
            }
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
                                    'id_option' => '900',
                                    'name' => $this->l('15 Minutes', '', 'en'),
                                ],
                                [
                                    'id_option' => '1800',
                                    'name' => $this->l('30 Minutes', '', 'en'),
                                ],
                                [
                                    'id_option' => '2700',
                                    'name' => $this->l('45 Minutes', '', 'en'),
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
                'name' => cryptapi::coinAddressConfigKey($ticker),
                'size' => 20,
            ];
        }

        // Build the cronjob URL through the Link class so it respects friendly
        // URLs, SSL and multishop instead of hand-concatenating _PS_BASE_URL_.
        $cronjobUrl = $this->context->link->getModuleLink('cryptapi', 'cronjob', ['nonce' => $default_nonce], true);

        $form['form']['input'][] = [
            'type' => 'text',
            'label' => $this->l('Cronjob Nonce', '', 'en'),
            'desc' => sprintf($this->l('Add this string to your cronjob URL when creating the cronjob in your system. %1$s The request should look like this: %2$s', '', 'en'), '<br/>', '<a href="' . $cronjobUrl . '" target="_blank">' . $cronjobUrl . '</a>'),
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

        $cryptapi_active = Tools::getValue('cryptapi_active', Configuration::get('cryptapi_active'));
        $cryptapi_checkout_title = Tools::getValue('cryptapi_checkout_title', Configuration::get('cryptapi_checkout_title'));
        $cryptapi_qrcode_size = Tools::getValue('cryptapi_qrcode_size', Configuration::get('cryptapi_qrcode_size'));
        $cryptapi_api_key = Tools::getValue('cryptapi_api_key', Configuration::get('cryptapi_api_key'));
        $cryptapi_add_blockchain_fee = Tools::getValue('cryptapi_add_blockchain_fee', Configuration::get('cryptapi_add_blockchain_fee'));
        $cryptapi_fee_order_percentage = Tools::getValue('cryptapi_fee_order_percentage', Configuration::get('cryptapi_fee_order_percentage'));
        $cryptapi_show_branding = Tools::getValue('cryptapi_show_branding', Configuration::get('cryptapi_show_branding'));
        $cryptapi_qrcode_default = Tools::getValue('cryptapi_qrcode_default', Configuration::get('cryptapi_qrcode_default'));
        $cryptapi_color_scheme = Tools::getValue('cryptapi_color_scheme', Configuration::get('cryptapi_color_scheme'));
        $cryptapi_refresh_value_interval = Tools::getValue('cryptapi_refresh_value_interval', Configuration::get('cryptapi_refresh_value_interval'));
        $cryptapi_order_cancelation_timeout = Tools::getValue('cryptapi_order_cancelation_timeout', Configuration::get('cryptapi_order_cancelation_timeout'));
        $cryptapi_disable_conversion = Tools::getValue('cryptapi_disable_conversion', Configuration::get('cryptapi_disable_conversion'));
        $cryptapi_qrcode_setting = Tools::getValue('cryptapi_qrcode_setting', Configuration::get('cryptapi_qrcode_setting'));

        // Load current value into the form
        $helper->fields_value['cryptapi_active'] = is_bool($cryptapi_active) ? 0 : $cryptapi_active;
        $helper->fields_value['cryptapi_checkout_title'] = empty($cryptapi_checkout_title) ? $this->l('Pay with cryptocurrency', '', 'en') : $cryptapi_checkout_title;
        $helper->fields_value['cryptapi_qrcode_size'] = empty($cryptapi_qrcode_size) ? '300' : $cryptapi_qrcode_size;
        $helper->fields_value['cryptapi_api_key'] = $cryptapi_api_key;
        $helper->fields_value['cryptapi_add_blockchain_fee'] = is_bool($cryptapi_add_blockchain_fee) ? 0 : $cryptapi_add_blockchain_fee;
        $helper->fields_value['cryptapi_fee_order_percentage'] = empty($cryptapi_fee_order_percentage) && $cryptapi_fee_order_percentage !== 0 ? '0' : $cryptapi_fee_order_percentage;
        $helper->fields_value['cryptapi_show_branding'] = is_bool($cryptapi_show_branding) ? 1 : $cryptapi_show_branding;
        $helper->fields_value['cryptapi_qrcode_default'] = is_bool($cryptapi_qrcode_default) ? 1 : $cryptapi_qrcode_default;
        $helper->fields_value['cryptapi_color_scheme'] = $cryptapi_color_scheme;
        $helper->fields_value['cryptapi_refresh_value_interval'] = empty($cryptapi_refresh_value_interval) ? '0' : $cryptapi_refresh_value_interval;
        $helper->fields_value['cryptapi_order_cancelation_timeout'] = empty($cryptapi_order_cancelation_timeout) ? '0' : $cryptapi_order_cancelation_timeout;
        $helper->fields_value['cryptapi_disable_conversion'] = $cryptapi_disable_conversion;
        $helper->fields_value['cryptapi_qrcode_setting'] = $cryptapi_qrcode_setting;
        $helper->fields_value['cryptapi_coins_cache'] = json_encode($cryptocurrencies_api);
        $helper->fields_value['cryptapi_cronjob_nonce'] = $default_nonce;

        if (empty($coins_db)) {
            $helper->fields_value['cryptapi_coins[]'] = '';
        } else {
            $helper->fields_value['cryptapi_coins[]'] = json_decode($coins_db['coins'], true);
        }

        foreach ($cryptocurrencies_api as $ticker => $coin) {
            $key = cryptapi::coinAddressConfigKey($ticker);
            $helper->fields_value[$key] = Tools::getValue($key, Configuration::get($key));
        }

        return $helper->generateForm([$form]);
    }

    public function hookPaymentReturn($params)
    {
        if (!$this->active) {
            return null;
        }
        return null;
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
                if ((string) $ticker === (string) $selected_coin) {
                    if (!empty(Configuration::get(cryptapi::coinAddressConfigKey($ticker))) || !empty(Configuration::get('cryptapi_api_key'))) {
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
            'cryptapi_token' => Tools::getToken('cryptapi_validation'),
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

    public static function coinAddressConfigKey($ticker)
    {
        return 'cryptapi_' . preg_replace('/[^a-zA-Z0-9_]/', '_', (string) $ticker) . '_address';
    }

    public static function validateCryptoAddress($ticker, $address)
    {
        $address = trim($address);

        if ($address === '') {
            return true;
        }

        if (preg_match('/\s/', $address) === 1) {
            return false;
        }

        $len = strlen($address);
        if ($len < 20 || $len > 130) {
            return false;
        }

        if (preg_match('/^[\x21-\x7E]+$/', $address) !== 1) {
            return false;
        }

        $patterns = [
            'btc' => ['/^[13][a-km-zA-HJ-NP-Z1-9]{25,34}$/', '/^bc1[ac-hj-np-z02-9]{8,87}$/'],
            'bch' => ['/^[13][a-km-zA-HJ-NP-Z1-9]{25,34}$/', '/^(bitcoincash:)?[qp][a-z0-9]{41,42}$/'],
            'ltc' => ['/^[LM3][a-km-zA-HJ-NP-Z1-9]{26,33}$/', '/^ltc1[ac-hj-np-z02-9]{8,87}$/'],
            'doge' => ['/^D[5-9A-HJ-NP-U][a-km-zA-HJ-NP-Z1-9]{32}$/'],
            'eth' => ['/^0x[a-fA-F0-9]{40}$/'],
            'trx' => ['/^T[1-9A-HJ-NP-Za-km-z]{33}$/'],
            'xmr' => ['/^[48][0-9AB][1-9A-HJ-NP-Za-km-z]{93}$/'],
            'iota' => ['/^iota1[a-z0-9]{59}$/'],
            'miota' => ['/^iota1[a-z0-9]{59}$/'],
            'xrp' => ['/^r[1-9A-HJ-NP-Za-km-z]{24,34}$/'],
        ];

        $tickerLower = strtolower($ticker);

        $family = $tickerLower;
        if (strpos($tickerLower, '_') !== false) {
            $parts = explode('_', $tickerLower);
            $family = end($parts);
        }
        if (in_array($family, ['polygon', 'bsc', 'matic', 'arbitrum', 'optimism', 'avax'], true)) {
            $family = 'eth';
        }

        if (!isset($patterns[$family])) {
            return true;
        }

        foreach ($patterns[$family] as $regex) {
            if (preg_match($regex, $address) === 1) {
                return true;
            }
        }

        return false;
    }

    public static function isConversionDisabled()
    {
        return (int) Configuration::get('cryptapi_disable_conversion') === 1;
    }

    /**
     * Whitelist check: is the given coin ticker part of the merchant's enabled
     * coin set (the selection stored in the cryptapi_coins table)? Used to reject
     * arbitrary request-supplied coins before they reach the API helper.
     */
    public static function isEnabledCoin($coin)
    {
        if (empty($coin) || !is_string($coin)) {
            return false;
        }

        $row = Db::getInstance()->getRow('SELECT `coins` FROM `' . _DB_PREFIX_ . 'cryptapi_coins` WHERE id=1');
        if (empty($row) || empty($row['coins'])) {
            return false;
        }

        $selected = json_decode($row['coins'], true);
        if (!is_array($selected)) {
            return false;
        }

        return in_array($coin, array_map('strval', $selected), true);
    }

    public static function authorizeOrderAccess($order, $providedKey = null)
    {
        if (!Validate::isLoadedObject($order)) {
            return false;
        }

        $context = Context::getContext();
        if (!empty($context->customer) && (int) $context->customer->id > 0
            && (int) $order->id_customer === (int) $context->customer->id) {
            return true;
        }

        if (!empty($providedKey) && is_string($providedKey)
            && hash_equals((string) $order->secure_key, (string) $providedKey)) {
            return true;
        }

        // No order-intrinsic fallback: even legacy (pre-1.3.0) orders require a
        // real caller secret. The order's secure_key exists regardless of whether
        // the emailed URL carried it, so a legitimate owner can still be granted
        // access above via the session-owner check or a valid &key= secure_key.
        return false;
    }

    public static function addPaymentResponse($order_id, $params)
    {
        Db::getInstance()->insert('cryptapi_order', [
            'order_id' => (int) $order_id,
            'response' => $params,
        ]);
    }

    public static function getPaymentResponse($orderId)
    {
        $row = Db::getInstance()->getRow(
            'SELECT `response` FROM `' . _DB_PREFIX_ . 'cryptapi_order` WHERE order_id=' . (int) $orderId
        );

        if (empty($row) || !isset($row['response'])) {
            return null;
        }

        return $row['response'];
    }

    public static function updatePaymentResponse($order_id, $param, $value)
    {
        $metaData = cryptapi::getPaymentResponse($order_id);
        if (!empty($metaData)) {
            $metaData = json_decode($metaData, true);
            $metaData[$param] = $value;
            $paymentData = json_encode($metaData);

            Db::getInstance()->update(
                'cryptapi_order',
                ['response' => $paymentData],
                'order_id=' . (int) $order_id
            );
        }
    }

    public static function getAllOrders()
    {
        // Get only CryptAPI Payments that are waiting for payment
        $orders = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'orders` WHERE module="cryptapi" AND current_state=' . (int) Configuration::get(self::CRYPTAPI_WAITING)
        );

        return $orders;
    }

    public static function cryptapiCronjob()
    {
        $order_timeout = (int) Configuration::get('cryptapi_order_cancelation_timeout');
        $value_refresh = (int) Configuration::get('cryptapi_refresh_value_interval');

        if ($order_timeout === 0 && $value_refresh === 0) {
            return;
        }

        $orders = cryptapi::getAllOrders();
        if (empty($orders)) {
            return;
        }

        foreach ($orders as $order) {
            cryptapi::processOrderTick($order, $order_timeout, $value_refresh);
        }
    }

    public static function processOrderTick($order, $order_timeout = null, $value_refresh = null)
    {
        if ($order_timeout === null) {
            $order_timeout = (int) Configuration::get('cryptapi_order_cancelation_timeout');
        }
        if ($value_refresh === null) {
            $value_refresh = (int) Configuration::get('cryptapi_refresh_value_interval');
        }

        if ($order_timeout === 0 && $value_refresh === 0) {
            return;
        }

        $waitingState = (int) Configuration::get(self::CRYPTAPI_WAITING);
        if ((int) $order['current_state'] !== $waitingState) {
            return;
        }

        $orderId = (int) $order['id_order'];
        $disableConversion = cryptapi::isConversionDisabled();
        $qrCodeSize = Configuration::get('cryptapi_qrcode_size');
        $currency = strtolower(Currency::getDefaultCurrency()->iso_code);

        $responseJson = cryptapi::getPaymentResponse($orderId);
        if ($responseJson === null) {
            return;
        }
        $metaData = json_decode($responseJson, true);
        if (!is_array($metaData) || empty($metaData['cryptapi_last_price_update'])) {
            return;
        }

        $last_price_update = $metaData['cryptapi_last_price_update'];
        $historyDb = $metaData['cryptapi_history'];
        $min_tx = (string) $metaData['cryptapi_min'];

        $calc = cryptapi::calcOrder($historyDb, $metaData['cryptapi_total'], $metaData['cryptapi_total_fiat']);
        $remaining = $calc['remaining'];
        $remaining_pending = $calc['remaining_pending'];
        $already_paid = $calc['already_paid'];

        if ($value_refresh !== 0 && $last_price_update + $value_refresh <= time()) {
            if (bccomp($remaining, $remaining_pending, 8) === 0) {
                $cryptapi_coin = $metaData['cryptapi_currency'];

                $crypto_total = CryptAPIHelper::sig_fig(CryptAPIHelper::get_conversion($currency, $cryptapi_coin, $order['total_paid'], $disableConversion), 6);

                cryptapi::updatePaymentResponse($orderId, 'cryptapi_total', $crypto_total);

                $responseJson = cryptapi::getPaymentResponse($orderId);
                $metaData = $responseJson !== null ? json_decode($responseJson, true) : null;
                if (!is_array($metaData)) {
                    return;
                }

                $calc_cron = cryptapi::calcOrder($historyDb, $metaData['cryptapi_total'], $metaData['cryptapi_total_fiat']);
                $crypto_remaining_total = $calc_cron['remaining_pending'];

                if (bccomp($remaining_pending, $min_tx, 8) <= 0 && bccomp($remaining_pending, '0', 8) > 0) {
                    $qr_code_data_value = CryptAPIHelper::get_static_qrcode($metaData['cryptapi_address'], $cryptapi_coin, $min_tx, $qrCodeSize);
                } else {
                    $qr_code_data_value = CryptAPIHelper::get_static_qrcode($metaData['cryptapi_address'], $cryptapi_coin, $crypto_remaining_total, $qrCodeSize);
                }

                cryptapi::updatePaymentResponse($orderId, 'cryptapi_qr_code_value', $qr_code_data_value['qr_code']);
            }

            cryptapi::updatePaymentResponse($orderId, 'cryptapi_last_price_update', time());
        }

        if ($order_timeout !== 0 && ($metaData['cryptapi_order_created'] + $order_timeout) <= time() && bccomp($already_paid, '0', 8) <= 0) {
            $history = new OrderHistory();
            $history->id_order = $orderId;
            $history->changeIdOrderState((int) Configuration::get('PS_OS_CANCELED'), $history->id_order, false);
            $history->addWithemail();
            $history->save();
        }
    }

    public static function generateNonce($len = 32)
    {
        $data = str_split('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789');
        $max = count($data) - 1;

        $nonce = [];
        for ($i = 0; $i < $len; ++$i) {
            $nonce[] = $data[random_int(0, $max)];
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
            'already_paid' => (string) $already_paid,
            'already_paid_fiat' => (string) $already_paid_fiat,
            'remaining' => (string) $remaining,
            'remaining_pending' => (string) $remaining_pending,
            'remaining_fiat' => (string) $remaining_fiat,
        ];
    }

    public static function sendMail($orderId)
    {
        $order = new Order((int) $orderId);
        $customer = $order->getCustomer();
        $customerMail = $customer->email;
        $customerName = $customer->firstname . ' ' . $customer->lastname;

        $responseJson = cryptapi::getPaymentResponse($orderId);
        if ($responseJson === null) {
            return;
        }
        $metaData = json_decode($responseJson, true);
        if (!is_array($metaData)) {
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

        $responseJson = cryptapi::getPaymentResponse($order->id);
        $metaData = $responseJson !== null ? json_decode($responseJson, true) : null;
        if (!is_array($metaData)) {
            return;
        }
        $history = isset($metaData['cryptapi_history']) ? $metaData['cryptapi_history'] : [];

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
