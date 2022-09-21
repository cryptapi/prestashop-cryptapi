{*q
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
*}

<form action="{$action}" id="payment-form">
    <p>
        <select class="form-control form-control-select" id="coin" name="cryptapi_coin">
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
    const fee_url = '{$fee}'
    document.getElementById('coin').addEventListener('change', function () {
        let val = this.value;
        const payment_fee = document.getElementById('cryptapi_fee');
        const buttonContainer = document.querySelector('.js-payment-confirmation');
        const confirmButton = buttonContainer.querySelector('.ps-shown-by-js');

        confirmButton.style.display = 'none';

        fetch(fee_url + '?cryptapi_coin=' + val)
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                confirmButton.style.display = 'block';
                if (data.fee === 0) {
                    confirmButton.style.display = 'block';
                    return;
                }
                document.getElementById('cryptapi_payment_fee').innerHTML = data.fee;
                document.getElementById('cryptapi_payment_total').innerHTML = data.total;
                payment_fee.style.display = 'block';
            });
    });
</script>

