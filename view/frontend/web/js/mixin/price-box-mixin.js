/**
 * Price Box Mixin
 * 
 * Responsibility:
 * This mixin intercepts Magento's core `priceBox` widget to inject B2B prices into the widget's internal configuration.
 * It ensures that standard initialized Magento products (like Simple Products on PDP) render the custom B2B price instead of the base catalog price.
 * 
 * How it works:
 * 1. Overrides the `_init` method of the `mage.priceBox` widget.
 * 2. It synchronously calls the original `this._super()` to allow Magento to fully initialize the widget.
 * 3. It hooks into the globally available `window.b2bPricesPromise` (provided by `b2b-prices-core.js`).
 * 4. When the promise resolves with the custom B2B prices, it modifies BOTH the widget's options and its internal render cache.
 * 5. Finally, it triggers `self.reloadPrice()` to force the widget to re-render the HTML template using the newly injected B2B price data.
 */
define(['jquery'], function ($) {
    'use strict';

    var priceBoxMixin = {
        _init: function () {
            this._super();
            var self = this;

            var patchAndRedraw = function (pricesById) {
                var boxProductId = self.options.productId || self.element.attr('data-product-id');
                if (boxProductId && pricesById && pricesById[boxProductId]) {
                    var b2bPriceData = pricesById[boxProductId];

                    var updateAmount = function (obj) {
                        if (!obj) return;

                        var singlePriceKeys = ['finalPrice', 'basePrice', 'regularPrice', 'price'];
                        for (var i = 0; i < singlePriceKeys.length; i++) {
                            if (obj[singlePriceKeys[i]] !== undefined) {
                                obj[singlePriceKeys[i]].amount = b2bPriceData.final_price;
                            }
                        }

                        if (b2bPriceData.min_price !== undefined) {
                            if (!obj.minPrice) obj.minPrice = { amount: 0, adjustments: {} };
                            obj.minPrice.amount = b2bPriceData.min_price;
                        }
                        if (b2bPriceData.max_price !== undefined) {
                            if (!obj.maxPrice) obj.maxPrice = { amount: 0, adjustments: {} };
                            obj.maxPrice.amount = b2bPriceData.max_price;
                        }
                    };

                    updateAmount(self.options.priceConfig ? self.options.priceConfig.prices : null);
                    updateAmount(self.options.prices);
                    updateAmount(self.cache ? self.cache.displayPrices : null);

                    if (typeof self.reloadPrice === 'function') {
                        self.reloadPrice();
                    }
                    
                    self.element.removeClass('hidden-zero-price').css('display', '');
                }
            };

            if (window.b2bPricesPromise && window.b2bPricesPromise.state() === 'pending') {
                window.b2bPricesPromise.done(function (data) {
                    patchAndRedraw(data.prices_by_id);
                });
            } else {
                var data = window.b2bPricesData || {};
                patchAndRedraw(data.prices_by_id);
            }
        }
    };

    return function (targetWidget) {
        $.widget('mage.priceBox', targetWidget, priceBoxMixin);
        return $.mage.priceBox;
    };
});
