/**
 * Swatch Renderer Mixin
 * 
 * Responsibility:
 * This mixin intercepts Magento's core `SwatchRenderer` widget to inject B2B variant prices into the widget's JSON configuration.
 * It ensures that Configurable products using visual or text swatches will display the correct B2B price depending on the variant selected by the user.
 * 
 * How it works:
 * 1. Overrides the `_init` method of the `mage.SwatchRenderer` widget.
 * 2. It synchronously calls the original `this._super()` to allow Magento to initialize standard swatch behaviors and evaluate pre-selected hash URLs.
 * 3. It waits for the `window.b2bPricesPromise` to resolve.
 * 4. It loops over the returned `configurable_prices` (mapped by child product ID) and deeply patches `self.options.jsonConfig.optionPrices` and `self.options.jsonConfig.prices`.
 * 5. Once patched, it validates that the associated `priceBox` widget component has completely finished loading on the DOM.
 * 6. If ready, it triggers `self._UpdatePrice()` so the SwatchRenderer recalculates and visually displays the new B2B prices for the currently active (or default) swatch selection.
 */
define(['jquery'], function ($) {
    'use strict';

    var swatchRendererMixin = {
        _init: function () {
            this._super();
            var self = this;

            // Override Magento's native tier price template to match the clean B2B layout
            this.options.tierPriceTemplate = '<ul class="prices-tier items b2b-tier-prices">' +
                '<% _.each(tierPrices, function(item, key) { %>' +
                '<li class="item">' +
                'Buy <%= item.qty %> for <span class="price-container price-tier_price"><span class="price-wrapper">' +
                '<span class="price" style="font-weight: bold;"><%= priceUtils.formatPrice(item.price, currencyFormat) %>' +
                '</span></span></span> each' +
                '</li>' +
                '<% }); %>' +
                '</ul>';

            var patchAndRedraw = function (configurablePrices, pricesById) {
                var confProductId = self.options.jsonConfig ? self.options.jsonConfig.productId : null;

                if (self.options.jsonConfig && self.options.jsonConfig.optionPrices && configurablePrices) {
                    var optionPrices = self.options.jsonConfig.optionPrices;
                    for (var pId in configurablePrices) {
                        if (configurablePrices.hasOwnProperty(pId) && optionPrices[pId]) {
                            var wcp = configurablePrices[pId];
                            if (optionPrices[pId].finalPrice) optionPrices[pId].finalPrice.amount = wcp.finalPrice.amount;
                            if (optionPrices[pId].basePrice) optionPrices[pId].basePrice.amount = wcp.basePrice.amount;
                            if (wcp.oldPrice && optionPrices[pId].oldPrice) {
                                optionPrices[pId].oldPrice.amount = wcp.oldPrice.amount;
                            }
                            // Apply custom B2B Tier Prices directly into the widget cache
                            if (wcp.tierPrices !== undefined) {
                                optionPrices[pId].tierPrices = wcp.tierPrices;
                            } else {
                                // Clear old tier prices to avoid ghosting from cached FPC
                                optionPrices[pId].tierPrices = [];
                            }
                        }
                    }
                }

                if (confProductId && pricesById && pricesById[confProductId]) {
                    var widgetMainPrice = pricesById[confProductId].final_price;
                    var basePrices = self.options.jsonConfig.prices;
                    if (basePrices && basePrices.finalPrice) {
                        basePrices.finalPrice.amount = widgetMainPrice;
                        if (basePrices.basePrice) {
                            basePrices.basePrice.amount = widgetMainPrice;
                        }
                    }
                }

                if (typeof self._UpdatePrice === 'function') {
                    setTimeout(function () {
                        var $product = self.element.parents(self.options.selectorProduct);
                        var $productPrice = $product.find(self.options.selectorProductPrice);
                        if (!$productPrice.length) {
                            $productPrice = $('[data-role=priceBox][data-product-id=' + confProductId + ']');
                        }
                        if ($productPrice.length && ($productPrice.data('mage-priceBox') || $productPrice.data('priceBox'))) {
                            try {
                                self._UpdatePrice();
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
        $.widget('mage.SwatchRenderer', targetWidget, swatchRendererMixin);
        return $.mage.SwatchRenderer;
    };
});
