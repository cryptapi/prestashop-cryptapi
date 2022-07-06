{*
 * 2007-2019 PrestaShop
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
 * @author    202-ecommerce <tech@202-ecommerce.com>
 * @copyright Copyright (c) Stripe
 * @license   Commercial license
*}
<div class="tab-pane d-print-block fade show active" id="cryptapiHistoryTabContent" role="tabpanel" aria-labelledby="cryptapiHistoryTab">
    <ul style="list-style: none; padding: 0; margin: 0;">
        {foreach $history as $key => $data}
            <li>
                <strong>Callback UUID: </strong>{$key} <br/>
                <div class="tab-content" style="margin-top: 10px;">
                    <ul style="list-style: none; padding: 0; margin: 0;">
                        {foreach $data as $dataKey => $dataItem}
                            <li><strong>{$dataKey}: </strong>
                                <p style="margin: 0; line-break: anywhere;">
                                    {if $dataKey === 'timestamp'}
                                        {$dataItem|date_format:"%H:%M, %e %B, %Y"}
                                    {else}
                                        {$dataItem}
                                    {/if}
                                </p>
                            </li>
                        {/foreach}
                    </ul>
                </div>
            </li>
        {/foreach}
    </ul>
</div>
<div class="tab-pane d-print-block fade show" id="cryptapiMetaTabContent" role="tabpanel" aria-labelledby="cryptapiMetaTab">
    <div class="tab-content">
        <ul style="list-style: none; padding: 0; margin: 0;">
            {foreach $meta_data as $key => $data}
                <li>
                    <strong>{$key}: </strong>
                    <p style="margin: 0; line-break: anywhere;">
                        {if $key === 'cryptapi_last_price_update' || $key === 'cryptapi_order_created'}
                            {$data|date_format:"%H:%M, %e %B, %Y"}
                        {else}
                            {$data}
                        {/if}
                    </p>
                </li>
            {/foreach}
        </ul>
    </div>
</div>