/**
 * B2B Prices Core Script
 * 
 * Responsibility:
 * This script is responsible for determining the B2B prices for all products currently visible on the page (PDP, PLP, etc.).
 * It initiates a single AJAX request to fetch these custom prices based on the logged-in B2B user's catalog.
 * 
 * How it works:
 * 1. It is loaded globally and executes synchronously, exposing a global jQuery Deferred promise (`window.b2bPricesPromise`).
 * 2. It waits for the document to be fully ready (`$(function() {...})`) so that all lazy-loaded or related products are present in the DOM.
 * 3. It scans the DOM for all `[data-product-sku]` and `[data-role="priceBox"][data-product-id]` elements to collect unique SKUs and product IDs.
 * 4. It fires an AJAX request to the server with the collected IDs/SKUs.
 * 5. Upon success, it resolves the globally available promise with the B2B price payload. 
 *    - This allows all Magento JS Widget Mixins (like priceBox, swatchRenderer) to pause their rendering logic until this promise resolves.
 * 6. As a fallback for PLP "Simple" products (which Magento renders as purely static HTML and does NOT initialize the priceBox widget for), 
 *    it manually updates the DOM text and `data-price-amount` attributes directly to ensure the frontend displays the B2B prices without requiring the widget.
 */
define(['jquery'], function ($) {
    'use strict';

    if (window.b2bPricesPromise) {
        return; // Already started
    }

    var baseUrl = window.BASE_URL || '/';
    if (baseUrl.charAt(baseUrl.length - 1) !== '/') {
        baseUrl += '/';
    }
    var ENDPOINT = baseUrl + 'prices/ajax/ajaxprices';

    function collectSkus() {
        var skus = [];
        $('[data-product-sku]').each(function () {
            var s = $(this).attr('data-product-sku');
            if (s && skus.indexOf(s) === -1) {
                skus.push(s);
            }
        });
        return skus;
    }

    function collectProductIds() {
        var ids = [];
        $('[data-role="priceBox"][data-product-id]').each(function () {
            var id = $(this).attr('data-product-id');
            if (id && ids.indexOf(id) === -1) {
                ids.push(id);
            }
        });

        // Fallback for PDPs where the priceBox might lack the data-product-id attribute (e.g., Bundles)
        var mainProductId = $('input[type="hidden"][name="product"]').val();
        if (mainProductId && ids.indexOf(mainProductId) === -1) {
            ids.push(mainProductId);
        }

        return ids;
    }

    var deferred = $.Deferred();
    window.b2bPricesPromise = deferred.promise();

    $(function () {
        var skus = collectSkus();
        var productIds = collectProductIds();

        var isPdp = $('body').hasClass('catalog-product-view');
        var tierProductIds = [];
        var mainProductId = null;
        if (isPdp) {
            mainProductId = $('input[type="hidden"][name="product"]').val();
            if (mainProductId) {
                tierProductIds.push(mainProductId);
            }
        }

        if (skus.length === 0 && productIds.length === 0 && tierProductIds.length === 0) {
            window.b2bPricesData = {};
            deferred.resolve({});
            return;
        }

        // Apply loading class to all price boxes
        var $allBoxes = $('[data-role="priceBox"]');
        $allBoxes.addClass('b2b-price-loading');

        $.ajax({
            url: ENDPOINT,
            type: 'POST',
            dataType: 'json',
            contentType: 'application/json',
            data: JSON.stringify({ skus: skus, product_ids: productIds, tier_product_ids: tierProductIds })
        }).done(function (data) {
            window.b2bPricesData = data || {};

            // Fallback for Simple Products on PLP (where Magento does NOT instantiate priceBox widgets)
            // Or to proactively avoid flicker on elements that will be instantiated shortly
            if (data && data.prices_by_id) {
                $('[data-role="priceBox"][data-product-id]').each(function () {
                    var $box = $(this);
                    var id = $box.attr('data-product-id');
                    if (id && data.prices_by_id[id]) {
                        var p = data.prices_by_id[id];
                        var $wrappers = $box.find('[data-price-type="finalPrice"], [data-price-type="price"], [data-price-type="regularPrice"], [data-price-type="basePrice"]');

                        $wrappers.each(function () {
                            $(this).attr('data-price-amount', p.final_price);
                            $(this).find('.price').html(p.formatted);
                        });

                        // Patch min and max prices if they exist (Bundles)
                        if (p.min_price !== undefined) {
                            var $minWrappers = $box.find('[data-price-type="minPrice"]');
                            if ($minWrappers.length) {
                                $minWrappers.each(function () {
                                    $(this).attr('data-price-amount', p.min_price);
                                    $(this).find('.price').html(p.min_price_formatted);
                                });
                            } else {
                                // FPC-cached page rendered a single price (no range) because guest prices were 0.
                                // Patch the existing finalPrice wrapper to show the "From" min price.
                                var $fallback = $box.find('[data-price-type="finalPrice"]');
                                $fallback.each(function () {
                                    $(this).attr('data-price-amount', p.min_price);
                                    $(this).attr('data-price-type', 'minPrice');
                                    $(this).find('.price').html(p.min_price_formatted);
                                });

                                // Add "From" label if missing
                                var $priceContainer = $box.find('.price-container').first();
                                if ($priceContainer.length && !$priceContainer.find('.price-label').length) {
                                    $priceContainer.prepend('<span class="price-label">From&nbsp;</span>');
                                }
                            }
                        }
                        if (p.max_price !== undefined) {
                            var $maxWrappers = $box.find('[data-price-type="maxPrice"]');
                            if ($maxWrappers.length) {
                                $maxWrappers.each(function () {
                                    $(this).attr('data-price-amount', p.max_price);
                                    $(this).find('.price').html(p.max_price_formatted);
                                });
                            }
                        }

                        // Update priceBox widget internals so Magento recalculations don't override our patches
                        if ($box.data('magePriceBox') || $box.data('mage-priceBox')) {
                            try {
                                var priceBoxInstance = $box.data('magePriceBox') || $box.data('mage-priceBox');
                                if (priceBoxInstance && priceBoxInstance.options && priceBoxInstance.options.prices) {
                                    if (p.min_price !== undefined && priceBoxInstance.options.prices.minPrice) {
                                        priceBoxInstance.options.prices.minPrice.amount = p.min_price;
                                    }
                                    if (p.max_price !== undefined && priceBoxInstance.options.prices.maxPrice) {
                                        priceBoxInstance.options.prices.maxPrice.amount = p.max_price;
                                    }
                                    if (priceBoxInstance.options.prices.finalPrice) {
                                        priceBoxInstance.options.prices.finalPrice.amount = p.final_price;
                                    }
                                    if (priceBoxInstance.options.prices.basePrice) {
                                        priceBoxInstance.options.prices.basePrice.amount = p.final_price;
                                    }
                                }
                            } catch (e) {
                                // Ignore if priceBox not fully initialized
                            }
                        }

                        // Reveal the box if it was hidden safely by the server plugin due to zero price
                        $box.removeClass('hidden-zero-price').css('display', '');
                    }
                });
            }

            // Inject B2B Tier Prices dynamically
            if (data && data.tier_prices && isPdp && mainProductId) {
                // Remove Magento's native FPC-cached tier prices first to prevent conflicts
                $('.prices-tier').remove();

                $.each(data.tier_prices, function (sku, tiers) {
                    if (tiers.length > 0) {
                        var html = '<ul class="prices-tier items b2b-tier-prices" style="margin-top: 15px; margin-bottom: 20px;">';
                        $.each(tiers, function (i, tier) {
                            html += '<li class="item" style="margin-bottom: 5px;">Buy ' + tier.qty + ' for <span class="price-container price-tier_price"><span class="price-wrapper"><span class="price" style="font-weight: bold;">' + tier.formatted + '</span></span></span> each</li>';
                        });
                        html += '</ul>';

                        // Target the primary product price box on the PDP
                        var $mainPriceBox = $('[data-role="priceBox"][data-product-id="' + mainProductId + '"]').first();

                        if ($mainPriceBox.length) {
                            $mainPriceBox.after(html);
                        } else {
                            // If the priceBox hasn't initialized its ID wrapper yet, append after product-info-price
                            $('.product-info-price').append(html);
                        }
                    }
                });
            }

            // Remove the inline styles and hidden-cart classes injected by the CatalogBlockPlugin
            // Use the explicit is_logged_in flag so it works even if there are no B2B prices (fallback to base)
            if (data && data.is_logged_in === true) {
                $('.hidden-cart').removeClass('hidden-cart').css('display', '');
                $('.hidden-zero-price').removeClass('hidden-zero-price').css('display', '');

                // Specifically re-enable tocart form if it was hidden
                $('[data-b2b-hidden="1"]').removeAttr('data-b2b-hidden').css('display', '');
            }

            $allBoxes.removeClass('b2b-price-loading');
            deferred.resolve(window.b2bPricesData);
        }).fail(function () {
            window.b2bPricesData = {};
            $allBoxes.removeClass('b2b-price-loading');
            deferred.resolve({});
        });
    });

});
