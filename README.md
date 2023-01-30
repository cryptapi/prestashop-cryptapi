![CryptAPI](https://i.imgur.com/IfMAa7E.png)

# CryptAPI Payment Gateway for PrestaShop
Accept cryptocurrency payments on your PrestaShop website

### Requirements:

```
PHP >= 7.3
PrestaShop >= 1.7
```

### Description

Accept payments in Bitcoin, Bitcoin Cash, Litecoin, Ethereum, Monero and IOTA directly to your crypto wallet, without any sign-ups or lengthy processes. All you need is to provide your crypto address.

#### Allow users to pay with crypto directly on your store

The CryptAPI module enables your PrestaShop store to get receive payments in cryptocurrency, with a simple setup and no sign-ups required.

#### Accepted cryptocurrencies & tokens include:

* (BTC) Bitcoin
* (ETH) Ethereum
* (BCH) Bitcoin Cash
* (LTC) Litecoin
* (XMR) Monero
* (TRX) Tron
* (BNB) Binance Coin
* (USDT) USDT

among many others, for a full list of the supported cryptocurrencies and tokens, check [this page](https://cryptapi.io/cryptocurrencies/).

#### Auto-value conversion

CryptAPI module will attempt to automatically convert the value you set on your store to the cryptocurrency your customer chose.
Exchange rates are fetched every 5 minutes.

Supported currencies for automatic exchange rates are:

* (USD) United States Dollar
* (EUR) Euro
* (GBP) Great Britain Pound
* (JPY) Japanese Yen
* (CNY) Chinese Yuan
* (INR) Indian Rupee
* (CAD) Canadian Dollar
* (HKD) Hong Kong Dollar
* (BRL) Brazilian Real
* (DKK) Danish Krone
* (MXN) Mexican Peso
* (AED) United Arab Emirates Dirham

If your WooCommerce's currency is none of the above, the exchange rates will default to USD.
If you're using WooCommerce in a different currency not listed here and need support, please [contact us](https://cryptapi.io/contacts/) via our live chat.

**Note:** CryptAPI will not exchange your crypto for FIAT or other crypto, just convert the value

#### Why choose CryptAPI?

CryptAPI has no setup fees, no monthly fees, no hidden costs, and you don't even need to sign-up!
Simply set your crypto addresses and you're ready to go. As soon as your customers pay we forward your earnings directly to your own wallet.

CryptAPI has a low 1% fee on the transactions processed. No hidden costs.
For more info on our fees [click here](https://cryptapi.io/fees/)

### Installation

#### Uploading in Prestashop Dashboard

1. Navigate to the 'Module Manager' in the PrestaShop dashboard
2. Click the 'Upload a Module' button
3. Select `prestashop-cryptapi.zip` from your computer

#### Using FTP

1. Download `prestashop-cryptapi.zip`
2. Extract the `prestashop-cryptapi` directory to your computer
3. Upload the `prestashop-cryptapi` directory to the `/your-store/modules/` directory
4. Activate the module in the `Module Catalog` dashboard and then configure it.

### Configuration

1. Go to Prestashop dashboard
2. Select the "Modules" tab and click "Module Manager"
3. Search for "CryptAPI" and click "configure" in our module
4. Set the name you wish to show your users on Checkout (for example: "Cryptocurrency")
5. Select which cryptocurrencies you wish to accept (control + click to select many)
6. Input your addresses to the cryptocurrencies you selected. This is where your funds will be sent to, so make sure the addresses are correct.
7. Click "Save"
8. All done!

### Enabling the Cronjob

Some features require a cronjob to work. You need to create one in your hosting that runs every 1 minute. It should call this URL YOUR-DOMAIN/module/cryptapi/cronjob?nonce=`your_nonce_here`, using `CURL`.
The required `nonce` its generated in the Module Manager configuration. 

### Frequently Asked Questions

#### Do I need an API key?

No. You just need to insert your crypto address of the cryptocurrencies you wish to accept. Whenever a customer pays, the money will be automatically and instantly forwarded to your address.

#### How long do payments take before they're confirmed?

This depends on the cryptocurrency you're using. Bitcoin usually takes up to 11 minutes, Ethereum usually takes less than a minute.

#### Is there a minimum for a payment?

Yes, the minimums change according to the chosen cryptocurrency and can be checked [here](https://cryptapi.io/get_started/#fees).
If the WooCommerce order total is below the chosen cryptocurrency's minimum, an error is raised to the user.

#### Where can I find more documentation on your service?

You can find more documentation about our service on our [get started](https://cryptapi.io/get_started) page, our [technical documentation](https://cryptapi.io/docs/) page or our [resources](https://cryptapi.io/resources/) page.
If there's anything else you need that is not covered on those pages, please get in touch with us, we're here to help you!

#### Where can I get support?

The easiest and fastest way is via our live chat on our [website](https://cryptapi.io) or via our [contact form](https://cryptapi.io/contact/).

### Changelog

#### 1.0.0
* Initial release.

#### 1.1.0
* Support for BlockBee
* New e-mail with the order payment link
* Minor bugfixes
* Added translations for Portuguese, Spanish, Italian and French

#### 1.1.1
* Minor bugfixes

#### 1.2.0
* Support for Prestashop 8
* Minor bugfixes

### Upgrade Notice
* No breaking changes