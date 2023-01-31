{*
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
*}

<form action="{$action}" id="payment-form">
    <p>
        <select class="form-control form-control-select" id="cryptapi_coin" name="cryptapi_coin">
            <option value="none">{l s='Please select a cryptocurrency' mod='cryptapi'}</option>
            {foreach from=$cryptocurrencies key=myId item=i}
                <option value="{$i.ticker}">{$i.coin}</option>
            {/foreach}
        </select>
    </p>
    <div id="cryptapi_fee" class="definition-list additional-information" style="display: none;">
        <dl>
            <dt>{l s='Payment Fee' mod='cryptapi'}</dt>
            <dd id="cryptapi_payment_fee"></dd>
            <dt>{l s='Total Charged' mod='cryptapi'}</dt>
            <dd id="cryptapi_payment_total"></dd>
        </dl>
    </div>
</form>
<script>
    const cryptapi_fee_url = new URL(window.location.protocol + '{url entity='module' name='cryptapi' controller='fee'}')

    document.getElementById('cryptapi_coin').addEventListener('change', function () {
        let val = this.value;
        const payment_fee = document.getElementById('cryptapi_fee');
        const buttonContainer = document.querySelector('.js-payment-confirmation');
        const confirmButton = buttonContainer.querySelector('.ps-shown-by-js');

        confirmButton.style.display = 'none';
        cryptapi_fee_url.searchParams.append('cryptapi_coin', val)
        fetch(cryptapi_fee_url)
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                confirmButton.style.display = 'block';
                if (Number(data.fee) === 0) {
                    confirmButton.style.display = 'block';
                    payment_fee.style.display = 'none';
                    return;
                }
                document.getElementById('cryptapi_payment_fee').innerHTML = data.fee + ' ' + data.simbCurrency;
                document.getElementById('cryptapi_payment_total').innerHTML = data.total + ' ' + data.simbCurrency;
                payment_fee.style.display = 'block';
            });
    });
</script>

