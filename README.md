![CryptAPI](https://i.imgur.com/IfMAa7E.png)

# CryptAPI – Crypto Payment Gateway for PrestaShop
Accept Bitcoin, Ethereum, USDT, USDC, Solana and other cryptocurrency payments in PrestaShop. No sign-up: funds go straight to your wallet.

### Requirements:

```
PHP >= 7.3
PrestaShop >= 1.7
```

### Description

Accept payments in Bitcoin, Ethereum, Bitcoin Cash, Litecoin, BNB, USDT, SHIB, DOGE and many more directly to your crypto wallet, without any sign-ups or lengthy processes.
All you need is to provide your crypto address and verify your domain once.

#### Allow users to pay with crypto directly on your store

The CryptAPI module enables your PrestaShop store to receive payments in cryptocurrency, with a simple setup and no sign-ups required.

#### Accepted cryptocurrencies & tokens include:

* (BTC) Bitcoin
* (ETH) Ethereum
* (BCH) Bitcoin Cash
* (LTC) Litecoin
* (TRX) Tron
* (BNB) Binance Coin
* (USDT) USDT
* (USDC) USDC
* (SHIB) Shiba Inu
* (DOGE) Dogecoin
* (POL) Polygon
* (SOL) Solana

among many others, for a full list of the supported cryptocurrencies and tokens, check [this page](https://cryptapi.io/cryptocurrencies/).

Stablecoins include USDT on TRC20 (Tron), ERC20, BEP20, Polygon, Solana, Base and TON, and USDC on ERC20, BEP20, Polygon, Solana, Base and Arbitrum.

#### Features

* Payment page with live payment progress
* QR codes with or without the amount
* Emails the customer a link to the payment page
* Handles partial payments and shows a payment history on the admin order page
* Refreshes the converted value on a schedule you choose
* Cancels unpaid orders after a time you choose
* Can add service and blockchain fees at checkout
* Signed callbacks, checked against CryptAPI's public key
* Light, dark and auto color schemes
* Checkout and payment pages translated into French, Spanish, Italian and Portuguese

#### Auto-value conversion

CryptAPI will attempt to automatically convert the value you set on your store to the cryptocurrency your customer chose.
Exchange rates are fetched every 5 minutes.

Supported currencies for automatic exchange rates are:

- (USD) United States Dollar
- (EUR) Euro
- (GBP) Great Britain Pound
- (CAD) Canadian Dollar
- (JPY) Japanese Yen
- (AED) UAE Dollar
- (MYR) Malaysian Ringgit
- (IDR) Indonesian Rupiah
- (THB) Thai Baht
- (CHF) Swiss Franc
- (SGD) Singapore Dollar
- (RUB) Russian Ruble
- (ZAR) South African Rand
- (TRY) Turkish Lira
- (LKR) Sri Lankan Rupee
- (RON) Romanian Leu
- (BGN) Bulgarian Lev
- (HUF) Hungarian Forint
- (CZK) Czech Koruna
- (PHP) Philippine Peso
- (PLN) Poland Zloti
- (UGX) Uganda Shillings
- (MXN) Mexican Peso
- (INR) Indian Rupee
- (HKD) Hong Kong Dollar
- (CNY) Chinese Yuan
- (BRL) Brazilian Real
- (DKK) Danish Krone
- (TWD) New Taiwan Dollar
- (AUD) Australian Dollar
- (NGN) Nigerian Naira
- (SEK) Swedish Krona
- (NOK) Norwegian Krone
- (UAH) Ukrainian Hryvnia
- (VND) Vietnamese Dong

If your PrestaShop store's currency is none of the above, the exchange rates will default to USD.
If you're using PrestaShop in a different currency not listed here and need support, please [contact us](https://cryptapi.io/contacts/) via our live chat.

**Note:** CryptAPI will not exchange your crypto for FIAT or other crypto, just convert the value.

#### Why choose CryptAPI?

CryptAPI has no setup fees, no monthly fees, no hidden costs, and you don't even need to sign-up!
Simply set your crypto addresses, verify your domain and you're ready to go. As soon as your customers pay we forward your earnings directly to your own wallet.

CryptAPI has a low 1% fee on the transactions processed. No hidden costs.
For more info on our fees [click here](https://cryptapi.io/fees/).

### Installation

#### Uploading in the PrestaShop Dashboard

1. Download `cryptapi.zip` from the [Releases](https://github.com/cryptapi/prestashop-cryptapi/releases) page
2. In your PrestaShop admin, go to Modules > Module Manager
3. Click 'Upload a module' and select `cryptapi.zip` from your computer
4. PrestaShop installs the module. Click 'Configure' to set it up.

#### Using FTP

1. Download `cryptapi.zip` from the [Releases](https://github.com/cryptapi/prestashop-cryptapi/releases) page
2. Extract it on your computer
3. Upload the `cryptapi` folder to the `/your-store/modules/` folder. Keep that folder name: PrestaShop uses it to find the module.
4. Install CryptAPI from the Modules page of your PrestaShop admin, then click 'Configure'

### Configuration

1. Open your PrestaShop admin
2. Go to Modules > Module Manager
3. Search for "CryptAPI" and click "Configure"
4. Set the name you wish to show your users on Checkout (for example: "Cryptocurrency")
5. Select which cryptocurrencies you wish to accept (control + click to select many)
6. Input your addresses to the cryptocurrencies you selected. This is where your funds will be sent to, so make sure the addresses are correct.
7. Click "Save"
8. Verify your store's domain at [cryptapi.io/verify_domain](https://cryptapi.io/verify_domain/), with a DNS TXT record or an HTTPS file. You only need to do this once, but customers can't pay with CryptAPI until you do. When you're done, click "I've verified my domain, hide this notice" on the settings page.
9. All done!

### Enabling the Cronjob

Some features, such as refreshing the converted value and cancelling unpaid orders, need a cronjob. Create one on your server that runs every minute and calls this URL with `curl`:

```
https://YOUR-DOMAIN/module/cryptapi/cronjob?nonce=YOUR_NONCE
```

The module settings page shows the exact URL, with your nonce.

### Frequently Asked Questions

#### Do I need an API key?

No. You just need to insert your crypto address of the cryptocurrencies you wish to accept and verify your domain once (see below). Whenever a customer pays, the money will be automatically and instantly forwarded to your address.

#### Do I need to verify my domain?

Yes. CryptAPI only creates payment addresses for verified domains, so customers can't pay until you verify the domain your store runs on. You only need to do it once, at [cryptapi.io/verify_domain](https://cryptapi.io/verify_domain/), with a DNS TXT record or an HTTPS file. You don't need an account.

A DNS record also covers your subdomains. An HTTPS file covers only the exact host, so `www.example.com` and `example.com` count as two domains. If you run more than one shop, verify each shop's domain. Payment addresses you already created keep working. Our [step-by-step guide](https://docs.cryptapi.io/kb/developers/how-to-verify-your-callback-domain) shows how.

#### How long do payments take before they're confirmed?

This depends on the cryptocurrency you're using. Bitcoin usually takes up to 11 minutes, Ethereum usually takes less than a minute.

#### Is there a minimum for a payment?

Yes, the minimums change according to the chosen cryptocurrency and can be checked [here](https://cryptapi.io/cryptocurrencies/).
If the order total is below the chosen cryptocurrency's minimum, the customer sees an error.

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

#### 1.2.1
* Minor bugfixes

#### 1.2.2
* Minor bugfixes

#### 1.2.3
* Add new choices for order cancellation.

#### 1.3.0
* Security and reliability improvements
* Minor bugfixes

#### 1.4.0
* Bugfixes and security hardening

#### 1.5.0
* Domain verification notice
* Button to hide the notice
* New module name

### Upgrade Notice
* No breaking changes
* From 1.5.0, the settings page shows a domain verification notice until you click "I've verified my domain, hide this notice". If you haven't verified your domain yet, see [Do I need to verify my domain?](#do-i-need-to-verify-my-domain)
