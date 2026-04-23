define(['jquery', 'underscore', 'mage/url'], function ($, _, urlBuilder) {
    'use strict';

    console.log('B2B Prices Mixins Loaded (Breeze)');

    /**
     * Fetch inventory/tier prices via AJAX and render them in the DOM.
     * Inlined here because Breeze's alias system prevents overriding
     * the native configurableVariationQty component reliably.
     */
    var fetchAndRenderTierPrices = function (productSku, salesChannel, salesChannelCode) {
        if (!productSku) {
            $('.b2b-tier-prices.dynamic-b2b-tier').remove();
            return;
        }

        console.log('B2B Inventory AJAX for SKU:', productSku);

        // Remove previous dynamic tier prices
        $('.b2b-tier-prices.dynamic-b2b-tier').remove();
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
            console.log('B2B Inventory Response:', response);

            // Update qty availability UI
            var $qtyBlock = $('.availability.only, .stock.available');
            var $qtyVal = $('.availability.only > strong, .stock.available > span');
            if (response.qty !== null && response.qty > 0) {
                $qtyVal.text(response.qty);
                $qtyBlock.show();
            } else {
                $qtyBlock.hide();
            }

            // Render tier prices
            if (response.tierPrices && response.tierPrices.length > 0) {
                var html = '<ul class="prices-tier items b2b-tier-prices dynamic-b2b-tier" style="margin-top:15px;margin-bottom:20px;">';
                $.each(response.tierPrices, function (i, tier) {
                    html += '<li class="item" style="margin-bottom:5px;">Buy ' + tier.qty +
                        ' for <span class="price-container price-tier_price"><span class="price-wrapper">' +
                        '<span class="price" style="font-weight:bold;">' + tier.formatted +
                        '</span></span></span> each</li>';
                });
                html += '</ul>';

                var $priceBox = $('.product-info-main [data-role="priceBox"], .product-info-wrapper [data-role="priceBox"]').first();
                if ($priceBox.length) {
                    console.log('B2B tiers rendered after priceBox');
                    $priceBox.after(html);
                } else {
                    console.log('B2B tiers rendered in product-info-main (fallback)');
                    $('.product-info-main, .product-info-wrapper').first().append(html);
                }
            }
        }).fail(function () {
            console.log('B2B Inventory AJAX Failed');
            $('.availability.only, .stock.available').hide();
        });
    };

    var patchAndRedraw = function (self, configurablePrices, pricesById, configKey, methodToReload) {
        var confProductId = self.options[configKey] ? self.options[configKey].productId : null;

        if (self.options[configKey] && self.options[configKey].optionPrices && configurablePrices) {
            var optionPrices = self.options[configKey].optionPrices;
            for (var pId in configurablePrices) {
                if (configurablePrices.hasOwnProperty(pId) && optionPrices[pId]) {
                    var wcp = configurablePrices[pId];
                    if (optionPrices[pId].finalPrice) optionPrices[pId].finalPrice.amount = wcp.finalPrice.amount;
                    if (optionPrices[pId].basePrice) optionPrices[pId].basePrice.amount = wcp.basePrice.amount;
                    if (wcp.oldPrice && optionPrices[pId].oldPrice) {
                        optionPrices[pId].oldPrice.amount = wcp.oldPrice.amount;
                    }
                    if (wcp.tierPrices !== undefined) {
                        optionPrices[pId].tierPrices = wcp.tierPrices;
                    } else {
                        optionPrices[pId].tierPrices = [];
                    }
                }
            }
        }

        if (confProductId && pricesById && pricesById[confProductId]) {
            var widgetMainPrice = pricesById[confProductId].final_price;
            var basePrices = self.options[configKey].prices;
            if (basePrices && basePrices.finalPrice) {
                basePrices.finalPrice.amount = widgetMainPrice;
                if (basePrices.basePrice) {
                    basePrices.basePrice.amount = widgetMainPrice;
                }
            }
        }

        if (typeof self[methodToReload] === 'function') {
            setTimeout(function () {
                var $priceBox = self.element.parents(self.options.selectorProduct).find(self.options.selectorProductPrice);
                if (!$priceBox.length) {
                    $priceBox = $('[data-role=priceBox][data-product-id=' + confProductId + ']');
                }
                if ($priceBox.length && ($priceBox.data('mage-priceBox') || $priceBox.data('priceBox') || $priceBox.priceBox('instance'))) {
                    try {
                        self[methodToReload]();
                        if (typeof self._displayTierPriceBlock === 'function') {
                            self._displayTierPriceBlock(typeof self.getProduct === 'function' && self.getProduct() ? self.getProduct() : self.simpleProduct);
                        }
                    } catch (e) { }
                }
            }, 50);
        }
    };

    var template = '<ul class="prices-tier items b2b-tier-prices" style="margin-top: 15px; margin-bottom: 20px;">' +
        '<% _.each(tierPrices, function(item, key) { %>' +
        '<li class="item" style="margin-bottom: 5px;">' +
        'Buy <%= item.qty %> for <span class="price-container price-tier_price"><span class="price-wrapper">' +
        '<span class="price" style="font-weight: bold;"><%= typeof priceUtils !== "undefined" && priceUtils.formatPrice ? priceUtils.formatPrice(item.price, currencyFormat) : item.formatted %>' +
        '</span></span></span> each' +
        '</li>' +
        '<% }); %>' +
        '</ul>';

    $.mixinSuper('configurable', {
        _create: function () {
            this._super();
            var self = this;
            this.options.tierPriceTemplate = template;

            var run = function (data) {
                patchAndRedraw(self, data.configurable_prices, data.prices_by_id, 'spConfig', '_reloadPrice');
            };

            if (window.b2bPricesPromise) {
                window.b2bPricesPromise.done(run);
            } else {
                run(window.b2bPricesData || {});
            }
        }
    });

    $.mixinSuper('SwatchRenderer', {
        _create: function () {
            this._super();
            var self = this;
            this.options.tierPriceTemplate = template;

            var run = function (data) {
                patchAndRedraw(self, data.configurable_prices, data.prices_by_id, 'jsonConfig', '_UpdatePrice');
            };

            if (window.b2bPricesPromise) {
                window.b2bPricesPromise.done(run);
            } else {
                run(window.b2bPricesData || {});
            }
        },

        _OnClick: function ($this, widget) {
            var salesChannel = this.options.jsonConfig.channel,
                salesChannelCode = this.options.jsonConfig.salesChannelCode,
                productVariationsSku = this.options.jsonConfig.sku;

            this._super($this, widget);

            var productId = widget.getProductId ? widget.getProductId() : null;
            if (productVariationsSku && productId && productVariationsSku[productId]) {
                fetchAndRenderTierPrices(productVariationsSku[productId], salesChannel, salesChannelCode);
            }
        }
    });

    // Also hook into dropdown-based configurable selection
    $.mixinSuper('configurable', {
        _configureElement: function (element) {
            this._super(element);
            var spConfig = this.options.spConfig;
            if (spConfig && spConfig.sku && this.simpleProduct) {
                fetchAndRenderTierPrices(
                    spConfig.sku[this.simpleProduct],
                    spConfig.channel,
                    spConfig.salesChannelCode
                );
            }
        }
    });

    $.mixinSuper('priceBox', {
        _init: function () {
            this._super();
            var self = this;

            var patchAndRedrawPriceBox = function (pricesById) {
                var boxProductId = self.options.productId || (typeof self.element.data === 'function' ? self.element.data('productId') : null);
                if (boxProductId && pricesById && pricesById[boxProductId]) {
                    var b2bPriceData = pricesById[boxProductId];

                    var updateAmount = function (obj) {
                        if (!obj) return;
                        var singlePriceKeys = ['finalPrice', 'basePrice', 'regularPrice', 'price'];
                        _.each(singlePriceKeys, function (key) {
                            if (obj[key] !== undefined) {
                                obj[key].amount = b2bPriceData.final_price;
                            }
                        });

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

            if (window.b2bPricesPromise) {
                window.b2bPricesPromise.done(function (data) {
                    patchAndRedrawPriceBox(data.prices_by_id);
                });
            } else {
                var data = window.b2bPricesData || {};
                patchAndRedrawPriceBox(data.prices_by_id);
            }
        }
    });
});

