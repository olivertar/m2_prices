var config = {
    map: {
        '*': {
            'configurableVariationQty': 'Orangecat_Prices/js/configurable-variation-qty-override',
            'Magento_InventoryConfigurableProductFrontendUi/js/configurable-variation-qty': 'Orangecat_Prices/js/configurable-variation-qty-override'
        }
    },
    config: {
        mixins: {
            'Magento_Catalog/js/price-box': {
                'Orangecat_Prices/js/mixin/price-box-mixin': true
            },
            'Magento_Swatches/js/swatch-renderer': {
                'Orangecat_Prices/js/mixin/swatch-renderer-mixin': true
            },
            'Magento_ConfigurableProduct/js/configurable': {
                'Orangecat_Prices/js/mixin/configurable-mixin': true
            },
            'Magento_Bundle/js/price-bundle': {
                'Orangecat_Prices/js/mixin/price-bundle-mixin': true
            }
        }
    },
    deps: ['Orangecat_Prices/js/b2b-prices-core']
};
