/**
 * Configurable Mixin
 * 
 * Responsibility:
 * This mixin intercepts Magento's core `configurable` widget (usually used for dropdown-based configurable products).
 * It injects B2B prices for each child variant into the `spConfig` object so the widget calculates accurate B2B totals when users change dropdown options.
 * 
 * How it works:
 * 1. Overrides the `_init` method of the `mage.configurable` widget.
 * 2. It synchronously calls `this._super()` to trigger standard Magento initialization and cache building.
 * 3. It waits for `window.b2bPricesPromise` to resolve with the B2B payload.
 * 4. It matches the custom B2B variant prices against the children IDs over the `self.options.spConfig.optionPrices` and base `prices` tree.
 * 5. It verifies that the dependent `priceBox` widget HTML element is fully instantiated to prevent race-condition script errors (especially on PLPs).
 * 6. It safely calls `self._reloadPrice()` to visually refresh the price block on the page matching the updated B2B values.
 */
define(['jquery'], function ($) {
    'use strict';

    var configurableMixin = {
        _init: function () {
            this._super();
            var self = this;

            var patchAndRedraw = function (configurablePrices, pricesById) {
                var confProductId = self.options.spConfig ? self.options.spConfig.productId : null;

                if (self.options.spConfig && self.options.spConfig.optionPrices && configurablePrices) {
                    var optionPrices = self.options.spConfig.optionPrices;
                    for (var pId in configurablePrices) {
                        if (configurablePrices.hasOwnProperty(pId) && optionPrices[pId]) {
                            var wcp = configurablePrices[pId];
                            if (optionPrices[pId].finalPrice) optionPrices[pId].finalPrice.amount = wcp.finalPrice.amount;
                            if (optionPrices[pId].basePrice) optionPrices[pId].basePrice.amount = wcp.basePrice.amount;
                            if (wcp.oldPrice && optionPrices[pId].oldPrice) {
                                optionPrices[pId].oldPrice.amount = wcp.oldPrice.amount;
                            }
                        }
                    }
                }

                if (confProductId && pricesById && pricesById[confProductId]) {
                    var widgetMainPrice = pricesById[confProductId].final_price;
                    var basePrices = self.options.spConfig.prices;
                    if (basePrices && basePrices.finalPrice) {
                        basePrices.finalPrice.amount = widgetMainPrice;
                        if (basePrices.basePrice) {
                            basePrices.basePrice.amount = widgetMainPrice;
                        }
                    }
                }

                if (typeof self._reloadPrice === 'function') {
                    setTimeout(function () {
                        var $priceBox = self._getPriceBoxElement ? self._getPriceBoxElement() : $('[data-role=priceBox][data-product-id=' + confProductId + ']');
                        if ($priceBox.length && ($priceBox.data('mage-priceBox') || $priceBox.data('priceBox'))) {
                            try {
                                self._reloadPrice();
                            } catch (e) {
                                // Ignore uninitialized priceBox errors
                            }
                        }
                    }, 50);
                }
            };

            if (window.b2bPricesPromise && window.b2bPricesPromise.state() === 'pending') {
                window.b2bPricesPromise.done(function (data) {
                    patchAndRedraw(data.configurable_prices, data.prices_by_id);
                });
            } else {
                var data = window.b2bPricesData || {};
                patchAndRedraw(data.configurable_prices, data.prices_by_id);
            }
        }
    };

    return function (targetWidget) {
        $.widget('mage.configurable', targetWidget, configurableMixin);
        return $.mage.configurable;
    };
});
