define([
    'jquery',
    'underscore',
    'mage/url'
], function ($, _, urlBuilder) {
    'use strict';

    return function (productSku, salesChannel, salesChannelCode) {
        var selectorInfoStockSkuQty = '.availability.only',
            selectorInfoStockSkuQtyValue = '.availability.only > strong',
            productQtyInfoBlock = $(selectorInfoStockSkuQty),
            productQtyInfo = $(selectorInfoStockSkuQtyValue);

        if (!_.isUndefined(productSku) && productSku !== null) {
            // Limpiar tier prices anteriores si el usuario cambia la variante
            $('.b2b-tier-prices.dynamic-b2b-tier').remove();

            // También removemos los nativos temporalmente para no solapar si los hubiera
            $('.prices-tier').remove();

            $.ajax({
                url: urlBuilder.build('inventory_catalog/product/getQty/'),
                dataType: 'json',
                data: {
                    'sku': productSku,
                    'channel': salesChannel,
                    'salesChannelCode': salesChannelCode
                }
            }).done(function (response) {
                // 1. Lógica nativa de Qty
                if (response.qty !== null && response.qty > 0) {
                    productQtyInfo.text(response.qty);
                    productQtyInfoBlock.show();
                } else {
                    productQtyInfoBlock.hide();
                }

                // 2. Lógica B2B de Tier Prices inyectada
                if (response.tierPrices && response.tierPrices.length > 0) {
                    var tiers = response.tierPrices;
                    var html = '<ul class="prices-tier items b2b-tier-prices dynamic-b2b-tier" style="margin-top: 15px; margin-bottom: 20px;">';
                    $.each(tiers, function (i, tier) {
                        html += '<li class="item" style="margin-bottom: 5px;">Buy ' + tier.qty + ' for <span class="price-container price-tier_price"><span class="price-wrapper"><span class="price" style="font-weight: bold;">' + tier.formatted + '</span></span></span> each</li>';
                    });
                    html += '</ul>';

                    var mainProductId = $('input[type="hidden"][name="product"]').val();
                    var $mainPriceBox = $('[data-role="priceBox"][data-product-id="' + mainProductId + '"]').first();

                    if ($mainPriceBox.length) {
                        $mainPriceBox.after(html);
                    } else {
                        // Fallback: If the priceBox hasn't initialized its ID wrapper yet, append after product-info-price
                        $('.product-info-price').append(html);
                    }
                }
            }).fail(function () {
                productQtyInfoBlock.hide();
            });
        } else {
            productQtyInfoBlock.hide();
            // Restore tier prices UI logic si se des-selecciona
            $('.b2b-tier-prices.dynamic-b2b-tier').remove();
        }
    };
});
