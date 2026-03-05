/**
 * This file is part of the Orangecat Prices package.
 *
 * (c) Oliverio Gombert <olivertar@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * ------------------------------------------------------------------------
 * Responsibility:
 * Intercept Magento's native `priceBundle` widget to inject B2B prices for 
 * individual bundle items.
 *
 * How it works:
 * 1. Magento calls `_init` on the `priceBundle` widget when a bundle product page loads.
 * 2. This mixin runs `this._super()` synchronously so Magento sets up its initial config.
 * 3. It listens to the global `window.b2bPricesPromise`.
 * 4. When the promise resolves (meaning AJAX returned the B2B prices), it looks for 
 *    `bundle_prices` in the response mapped by the current bundle's Product ID.
 * 5. It iterates over Magento's `self.options.optionConfig.options` and replaces the 
 *    `finalPrice` and `basePrice` amounts for every Selection ID with the B2B amount.
 * 6. It calls `self._applyOptionNodeFix()` to redraw the text inside `<select>` menus.
 * 7. It triggers a `change` event on the options to force the `priceBox` to sum the new total.
 */
define([
    'jquery',
    'Magento_Catalog/js/price-utils'
], function ($, priceUtils) {
    'use strict';

    return function (widget) {
        $.widget('mage.priceBundle', widget, {
            _init: function () {
                var self = this;

                // 1. Let Magento do its normal initialization synchronously
                this._super();

                // 2. Hook into the global B2B promise
                if (window.b2bPricesPromise) {
                    window.b2bPricesPromise.done(function (b2bData) {
                        if (b2bData && b2bData.bundle_prices) {
                            var bundleId = self.options.optionConfig.bundleId;

                            if (bundleId && b2bData.bundle_prices[bundleId]) {
                                var bundleB2bPrices = b2bData.bundle_prices[bundleId];
                                var format = self.options.optionConfig.priceFormat || self.options.priceFormat; // FIXED THIS LINE

                                // 3. Patch the inner JS config with the new B2B prices
                                $.each(self.options.optionConfig.options, function (optionId, optionConfig) {
                                    if (optionConfig.selections) {
                                        $.each(optionConfig.selections, function (selectionId, selectionData) {
                                            if (bundleB2bPrices[selectionId]) {
                                                var b2bPriceData = bundleB2bPrices[selectionId];

                                                if (selectionData.prices) {
                                                    if (selectionData.prices.finalPrice) {
                                                        selectionData.prices.finalPrice.amount = b2bPriceData.finalPrice.amount;
                                                    }
                                                    if (selectionData.prices.basePrice) {
                                                        selectionData.prices.basePrice.amount = b2bPriceData.basePrice.amount;
                                                    }
                                                }

                                                // 4. Try to patch radio/checkbox/hidden labels manually via DOM
                                                var $input = $('#bundle-option-' + optionId + '-' + selectionId);
                                                if ($input.length) {
                                                    var $container = $input.parent();
                                                    var formattedPrice = priceUtils.formatPrice(b2bPriceData.finalPrice.amount, format);

                                                    // Only patch if a price wrapper exists inside this container
                                                    var $priceAmountElement = $container.find('[data-price-amount]');
                                                    var $priceTextElement = $container.find('.price');

                                                    if ($priceAmountElement.length && $priceTextElement.length) {
                                                        $priceTextElement.html(formattedPrice);
                                                        $priceAmountElement.attr('data-price-amount', b2bPriceData.finalPrice.amount);
                                                    }
                                                }
                                            }
                                        });
                                    }
                                });

                                // 5. Apply correct prices to `<select>` `<option>` tags using Magento's native fix
                                var form = self.element;
                                var options = $(self.options.productBundleSelector, form);
                                self._applyOptionNodeFix(options);

                                // 6. Force Magento to recalculate the main bundle PriceBox total
                                var $priceBox = $(self.options.priceBoxSelector, form);

                                var forceRecalculate = function () {
                                    options.trigger('change');
                                };

                                if ($priceBox.data('mage-priceBox') || $priceBox.data('priceBox')) {
                                    forceRecalculate();
                                } else {
                                    $priceBox.on('price-box-initialized', forceRecalculate);
                                }
                            }
                        }
                    });
                }
            }
        });

        return $.mage.priceBundle;
    };
});
