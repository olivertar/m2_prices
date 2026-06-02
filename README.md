# Orangecat_Prices

B2B pricing engine orchestrator — calculator pool, conflict resolution, and frontend price injection.

**Module:** `Orangecat_Prices`
**Version:** 1.0.0
**License:** OSL-3.0
**Author:** Oliverio Gombert <olivertar@gmail.com>

---

## Table of Contents

1. [Overview](#overview)
2. [Theme Compatibility](#theme-compatibility)
3. [Requirements](#requirements)
4. [Installation](#installation)
5. [What Gets Installed](#what-gets-installed)
6. [Configuration](#configuration)
7. [Store Admin Guide](#store-admin-guide)
8. [Buyer Guide (Frontend)](#buyer-guide-frontend)
9. [Developer Guide](#developer-guide)
10. [REST API](#rest-api)
11. [Frontend Routes Reference](#frontend-routes-reference)
12. [DevOps & Integrator Notes](#devops--integrator-notes)

---

## Overview

`Orangecat_Prices` is the **B2B pricing engine** of the Orangecat suite. It does not store prices itself — it provides the infrastructure that downstream pricing modules (e.g., `Orangecat_PricesList`, `Orangecat_PricesCompany`) plug into to deliver company-specific prices to B2B buyers.

The module handles:

- A **Calculator Pool** that aggregates price calculators registered by downstream modules
- A **Price Resolver** that applies configurable conflict-resolution logic when multiple calculators return a price for the same product
- A **Tier Price Resolver** that aggregates quantity-based discount tiers across all calculators
- PHP-level price injection at three Magento hooks: product final price display, cart add, and quote-to-order conversion
- FPC/Varnish cache segmentation per company via HTTP context
- A frontend AJAX endpoint that resolves B2B prices for batches of products
- JavaScript widget mixins for Luma/RequireJS, Breeze, and Hyvä that update price displays without page reload

### Position in the Orangecat B2B Dependency Chain

```
Orangecat_Core (via composer: orangecat/core)
  └── Orangecat_Company
        └── Orangecat_Prices                ← this module
              ├── Orangecat_PricesList       — defines price lists and per-SKU prices
              └── Orangecat_PricesCompany    — assigns price lists to companies
```

`Orangecat_Prices` depends on `Orangecat_Company` to resolve the company a logged-in customer belongs to. `Orangecat_PricesList` and `Orangecat_PricesCompany` register their calculators into this module's pool and provide the actual pricing data.

### The Calculator Pool Pattern

This is the central architectural concept of the module. No prices are stored here. Downstream modules implement `Orangecat\Prices\Api\PriceCalculatorInterface` and register themselves in the `CalculatorPool` via `di.xml`. The `PriceResolver` calls each calculator for a given (SKU, qty, company) context and determines the winning price based on the configured **Conflict Resolution Mode**.

Two resolution modes are supported:

| Mode | Value | Behavior |
|---|---|---|
| Lowest Price | `lowest_price` | The calculator returning the lowest price wins (best for buyer). Default. |
| Priority | `priority` | The last calculator in `di.xml` `sortOrder` wins, regardless of value. |

The same resolution logic applies to quantity tiers in `TierPriceResolver`.

---

## Theme Compatibility

| Theme | Status | Notes |
|---|---|---|
| **Luma** | Supported | RequireJS widget mixins (`price-box-mixin`, `configurable-mixin`, `swatch-renderer-mixin`, `price-bundle-mixin`) injected via `requirejs-config.js`. `b2b-prices-core.js` loaded as a global dependency on all pages. |
| **Hyvä** | Supported | Dedicated `b2b-tier-prices_hyva.phtml` template loaded on `hyva_catalog_product_view`. Vanilla JS using the native `fetch()` API — no jQuery dependency. Tier prices update on configurable variant selection via the `configurable-selection-changed` custom event. |
| **Breeze Evolution** | Supported | Breeze bundle defined in `breeze_default.xml`. `breeze/prices-mixins.js` uses `$.mixinSuper()` to patch `configurable`, `SwatchRenderer`, and `priceBox` Breeze components. Per-variant tier prices are fetched via the patched `SwatchRenderer._OnClick` and `configurable._configureElement` hooks. |

---

## Requirements

| Dependency | Version / Notes |
|---|---|
| PHP | >= 8.1 |
| `magento/framework` | * |
| `magento/module-catalog` | * |
| `magento/module-configurable-product` | * |
| `magento/module-bundle` | * (bundle price resolution in AJAX endpoint) |
| `magento/module-customer` | * |
| `magento/module-quote` | * |
| `magento/module-sales` | * |
| `magento/module-store` | * |
| `magento/module-inventory-catalog-frontend-ui` | * (GetQty controller rewrite) |
| `orangecat/core` | * |
| `orangecat/module-company` | * |

---

## Installation

### Via Git Submodule (recommended for this project)

```bash
# From repo root
git submodule add git@github.com:olivertar/m2_prices.git app/code/Orangecat/Prices
git submodule update --init --recursive
```

### Enable the Module

Run inside the PHP container (`reward shell`):

```bash
bin/magento module:enable Orangecat_Prices
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

> `Orangecat_Company` must be installed and enabled before this module.

---

## What Gets Installed

### Database Tables

None. `Orangecat_Prices` introduces no database tables or schema changes. All pricing data is owned by downstream modules (`Orangecat_PricesList`, `Orangecat_PricesCompany`).

### EAV Attributes

None.

### Data Patches

None. No default records, roles, CMS pages, or seed data are created by this module.

---

## Configuration

**Path:** Admin menu → **Prices → Settings**, or `Stores > Configuration > Orangecat > Prices (B2B)`

### Global B2B Pricing Engine

| Label | Config Path | Default | Description |
|---|---|---|---|
| Enable B2B Pricing Engine | `prices/general/enabled` | Yes | Master switch. When disabled, all downstream pricing modules are bypassed and standard catalog prices are shown to all users. |
| Conflict Resolution Mode | `prices/general/resolution_mode` | `lowest_price` | When multiple calculators return a price for the same product, determines which wins. Options: `Lowest Price (Best for Customer)` or `Priority (Last Module Executed)`. Hidden when the engine is disabled. |
| Use Company Tier Prices | `prices/general/use_tier_prices` | Yes | Enables dynamic volume/tier pricing in AJAX responses. Disable to reduce payload and DB queries when tier pricing is not in use. Hidden when the engine is disabled. |

Config paths:

```
prices/general/enabled
prices/general/resolution_mode
prices/general/use_tier_prices
```

---

## Store Admin Guide

### Enabling / Disabling the B2B Pricing Engine

1. Navigate to **Admin menu → Prices → Settings** (or `Stores > Configuration > Orangecat > Prices (B2B)`).
2. Set **Enable B2B Pricing Engine** to `Yes` or `No`.
3. Save configuration and flush cache.

When disabled, every B2B price override — flat prices and volume tiers — is suppressed for all users, including company members. All downstream pricing modules are silently ignored.

### Choosing a Conflict Resolution Mode

This setting matters when more than one pricing module (e.g., `Orangecat_PricesList` plus a custom calculator) returns a price for the same product in the same company context:

- **Lowest Price** — always gives the buyer the best discount. Recommended default.
- **Priority** — the calculator with the highest `sortOrder` value in `di.xml` wins, regardless of which price is lower. Use when a specific pricing source must override all others unconditionally.

### Tier Prices Toggle

When **Use Company Tier Prices** is enabled, the AJAX endpoint includes quantity tiers in its response and the frontend renders a "Buy X for $Y each" list on product detail pages. Disabling this setting reduces AJAX response size and database load when volume discounts are not configured.

---

## Buyer Guide (Frontend)

B2B prices are applied **automatically** for any logged-in customer who belongs to a company. No buyer action is required.

- **Product listing pages (PLP):** A single batch AJAX request resolves prices for all products visible on the page. A brief loading state is applied to price boxes while the request is in flight.
- **Product detail pages (PDP):** The resolved B2B final price replaces the catalog price after page load. Volume tier prices ("Buy 5 for $9.00 each") appear below the main price block when configured. On configurable products, tier prices update automatically when the buyer selects a variant or swatch.
- **Cart and checkout:** The B2B price is enforced at the PHP level when items are added to the cart and when the quote is converted to an order. The correct company price is recorded on the order regardless of frontend state.

Logged-in customers who are not members of any company see standard catalog prices.

---

## Developer Guide

### Module Structure

```
Orangecat/Prices/
├── Api/
│   └── PriceCalculatorInterface.php            # Contract all pricing modules must implement
├── Controller/
│   ├── Ajax/AjaxPrices.php                     # POST prices/ajax/ajaxprices — batch B2B price resolver
│   └── Rewrite/Product/GetQty.php              # Extends core GetQty; appends tierPrices to response
├── Model/
│   ├── CalculatorPool.php                      # Registry of active PriceCalculatorInterface instances
│   ├── Config.php                              # system.xml config reader
│   ├── PriceResolver.php                       # Iterates pool; applies resolution mode
│   ├── TierPriceResolver.php                   # Aggregates quantity tiers across all calculators
│   └── Config/Source/ResolutionMode.php        # Dropdown source: lowest_price | priority
├── Observer/
│   └── ProcessFinalPriceObserver.php           # catalog_product_get_final_price → sets cart price
├── Plugin/
│   ├── App/HttpContextPlugin.php               # Injects orangecat_company_id into HTTP context
│   ├── Pricing/FinalPricePlugin.php            # afterGetValue — overrides displayed catalog price
│   ├── Pricing/Render/PriceBox/CacheKeyPlugin.php  # Appends company ID to block cache key
│   └── Quote/Model/Quote/Item/ToOrderItem.php  # afterConvert — applies B2B price to order item
├── etc/
│   ├── acl.xml
│   ├── adminhtml/menu.xml
│   ├── adminhtml/system.xml
│   ├── config.xml                              # Default config values
│   ├── di.xml                                  # Plugin + preference declarations
│   ├── events.xml
│   ├── frontend/routes.xml
│   └── module.xml
└── view/frontend/
    ├── layout/
    │   ├── hyva_catalog_product_view.xml       # Hyvä PDP: adds tier prices phtml block
    │   └── breeze_default.xml                  # Breeze: registers JS bundles
    ├── templates/
    │   └── b2b-tier-prices_hyva.phtml          # Hyvä tier prices + vanilla JS fetch logic
    ├── requirejs-config.js                     # Luma: mixins registration + b2b-prices-core dep
    └── web/
        ├── js/
        │   ├── b2b-prices-core.js                         # AJAX orchestrator; exposes b2bPricesPromise
        │   ├── configurable-variation-qty-override.js     # Replaces core InventoryConfigurableProduct GetQty
        │   ├── breeze/prices-mixins.js                    # Breeze-specific combined mixin
        │   └── mixin/
        │       ├── price-box-mixin.js                     # Luma priceBox widget
        │       ├── configurable-mixin.js                  # Luma configurable widget
        │       ├── swatch-renderer-mixin.js               # Luma swatch renderer
        │       └── price-bundle-mixin.js                  # Luma bundle price widget
        └── css/
            ├── source/_module.less                        # Luma styles
            └── breeze/_default.less                       # Breeze styles
```

### Service Contract

#### `PriceCalculatorInterface` (`Orangecat\Prices\Api\PriceCalculatorInterface`)

```php
/**
 * Return the B2B price for a given context, or null if this module
 * has no applicable price.
 */
public function calculate(string $sku, float $qty, int $companyId, float $basePrice = 0.0): ?float;

/**
 * Return all quantity tiers: [['qty' => 5.0, 'price' => 8.50], ...]
 * Sorted ascending by qty. Return [] if no tiers are configured.
 */
public function getTiers(string $sku, int $companyId, float $basePrice = 0.0): array;
```

### Key Models

#### `Config`

```php
$config->isEnabled($storeId = null): bool
$config->isTierPricesEnabled($storeId = null): bool      // false if engine disabled
$config->getResolutionMode($storeId = null): string      // 'lowest_price' | 'priority'
```

#### `PriceResolver`

```php
// Returns the resolved B2B price, or null if no calculator applies.
$resolver->resolve(string $sku, float $qty, int $companyId, float $basePrice): ?float
```

#### `TierPriceResolver`

```php
// Returns merged, resolution-mode-applied tiers sorted ascending by qty.
$resolver->resolveTiers(string $sku, int $companyId, float $basePrice): array
```

### Observers

| Class | Event | Area | Action |
|---|---|---|---|
| `ProcessFinalPriceObserver` | `catalog_product_get_final_price` | `frontend` | Calls `PriceResolver::resolve()` and calls `$product->setFinalPrice()`. Skips configurable, bundle, and grouped parent products (the engine is applied to their child simples instead). |

### Plugins

| Class | Target | Hook | Purpose |
|---|---|---|---|
| `FinalPricePlugin` | `Magento\Catalog\Pricing\Price\FinalPrice` | `after getValue` | Overrides the displayed catalog price for simple / virtual / downloadable products with the B2B resolved price. |
| `FinalPricePlugin` | `Magento\ConfigurableProduct\Pricing\Price\FinalPrice` | `after getValue` | Same override for the configurable product type's final price model. |
| `CacheKeyPlugin` | `Magento\Framework\Pricing\Render\PriceBox` | `after getCacheKey` | Appends `-{companyId}-` to the block cache key so FPC/ESI cached price blocks are isolated per company. |
| `HttpContextPlugin` | `Magento\Framework\App\ActionInterface` | `before execute` | Injects `orangecat_company_id` into HTTP context for Varnish cache segmentation (called during action dispatch). |
| `HttpContextPlugin` | `Magento\Framework\App\Http\Context` | `before getVaryString` | Same injection for Magento's built-in FPC, which calls `getVaryString` before action dispatch during `Kernel::load`. Also injects `customer_group` and `customer_logged_in` context to fix a core FPC bug where load key and save key diverge. |
| `ToOrderItem` | `Magento\Quote\Model\Quote\Item\ToOrderItem` | `after convert` | Sets `price`, `basePrice`, `originalPrice`, and `baseOriginalPrice` on the new order item using the B2B resolved price. Skips configurable / bundle / grouped parent products. |

### JS Components

#### Luma / RequireJS

| File | Purpose |
|---|---|
| `b2b-prices-core.js` | Collects all `[data-product-sku]` and `[data-role="priceBox"]` elements on page load, sends a single batch AJAX POST, and exposes `window.b2bPricesPromise` that all mixins await before patching. Also performs direct DOM patching for PLP simple products where the `priceBox` widget is never initialized. |
| `mixin/price-box-mixin.js` | Overrides `_init`; awaits `b2bPricesPromise`; patches `options.prices`, `options.priceConfig.prices`, and the display cache; calls `reloadPrice()`. |
| `mixin/configurable-mixin.js` | Patches `spConfig.optionPrices` with B2B variant prices and calls `_reloadPrice()` after a 50 ms guard to avoid race conditions with `priceBox` initialization. |
| `mixin/swatch-renderer-mixin.js` | Patches `jsonConfig.optionPrices`; calls `_UpdatePrice()`; overrides `_OnClick` to fetch per-variant tier prices on swatch selection. |
| `mixin/price-bundle-mixin.js` | Patches bundle selection prices in the bundle price widget. |
| `configurable-variation-qty-override.js` | RequireJS alias replacing `Magento_InventoryConfigurableProductFrontendUi/js/configurable-variation-qty` with a version that also resolves and renders B2B tier prices per selected variant. |

#### Breeze Evolution

| File | Purpose |
|---|---|
| `breeze/prices-mixins.js` | Single Breeze mixin file. Patches `configurable._create` and `SwatchRenderer._create` (via `$.mixinSuper`) to inject B2B variant prices into `optionPrices`. Overrides `SwatchRenderer._OnClick` and `configurable._configureElement` to fetch tier prices via `inventory_catalog/product/getQty/` on variant change. Also patches `priceBox._init` for direct price box updates. |

#### Hyvä

| File | Purpose |
|---|---|
| `b2b-tier-prices_hyva.phtml` | Inline vanilla JS block rendered on `hyva_catalog_product_view`. Calls `prices/ajax/ajaxprices` on `DOMContentLoaded` to fetch tier prices for the main product. Listens for the `configurable-selection-changed` custom event to swap tier price display when the buyer selects a variant. |

### Email Templates

None. This module sends no transactional emails.

### ACL Resources

| Resource ID | Title | Location |
|---|---|---|
| `Orangecat_Prices::config` | B2B Prices | Stores > Settings > Configuration |

### Adding Custom Logic

- **Register a new pricing source:** Implement `PriceCalculatorInterface`, then register the class in `Orangecat_Prices`'s `CalculatorPool` via your module's `di.xml`:
  ```xml
  <type name="Orangecat\Prices\Model\CalculatorPool">
      <arguments>
          <argument name="calculators" xsi:type="array">
              <item name="my_source" xsi:type="object" sortOrder="20">My\Module\Model\MyPriceCalculator</item>
          </argument>
      </arguments>
  </type>
  ```
  The `sortOrder` value controls resolution order when `resolution_mode` is set to `priority`.
- **Override resolution mode per scope:** The `prices/general/resolution_mode` config supports website and store view scope. Override it in `Stores > Configuration` to apply different conflict strategies per store.
- **Extend the AJAX response:** Plugin `afterExecute` on `Orangecat\Prices\Controller\Ajax\AjaxPrices` to add extra keys to the JSON payload without modifying the core controller.

---

## REST API

This module exposes no REST API endpoints. There is no `webapi.xml`. The AJAX pricing endpoint (`prices/ajax/ajaxprices`) is a standard frontend controller intended for storefront use only.

---

## Frontend Routes Reference

| Route | Controller | Access |
|---|---|---|
| `POST /prices/ajax/ajaxprices` | `Controller\Ajax\AjaxPrices` | Public — logged-in company members receive B2B prices; guests and non-company customers receive base catalog prices or an empty map. |
| `GET /inventory_catalog/product/getQty` | `Controller\Rewrite\Product\GetQty` | Public — rewrites the core Magento controller to append `tierPrices` to the standard qty JSON response. |

### AJAX Price Endpoint

**URL:** `POST /prices/ajax/ajaxprices`

Request body (JSON):

```json
{
  "skus": ["SKU-001", "SKU-002"],
  "product_ids": [42, 99],
  "tier_product_ids": [42]
}
```

- All three arrays are optional. Pass `tier_product_ids` only on pages where tier price display is needed (PDP).
- Maximum 200 entries per array.

Response (JSON):

```json
{
  "is_logged_in": true,
  "prices": {
    "SKU-001": { "final_price": 12.50, "formatted": "$12.50" }
  },
  "prices_by_id": {
    "42": { "final_price": 12.50, "formatted": "$12.50" }
  },
  "configurable_prices": {
    "55": {
      "finalPrice": { "amount": 10.00 },
      "basePrice":  { "amount": 10.00 },
      "oldPrice":   { "amount": 15.00 },
      "tierPrices": [
        { "qty": 5, "price": 9.00, "formatted": "$9.00", "percentage": 10, "basePrice": 9.00 }
      ]
    }
  },
  "tier_prices": {
    "SKU-001": [
      { "qty": 5.0, "price": 9.00, "formatted": "$9.00" }
    ]
  },
  "bundle_prices": {
    "99": {
      "final_price": 45.00, "formatted": "$45.00",
      "min_price": 45.00,   "min_price_formatted": "$45.00",
      "max_price": 90.00,   "max_price_formatted": "$90.00"
    }
  }
}
```

- `configurable_prices` is only present when configurable products are in the request.
- `tier_prices` is only present when `tier_product_ids` are passed and `Use Company Tier Prices` is enabled.
- `bundle_prices` is only present when bundle products are in the request.
- Guests receive `{ "is_logged_in": false, "prices": {} }`.

---

## DevOps & Integrator Notes

### Deployment Checklist

```bash
# Run inside the PHP container (reward shell)
bin/magento module:enable Orangecat_Prices
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

### Integration Token Scope

This module exposes no REST API endpoints. No integration token ACL permissions are required for this module specifically.

### FPC / Varnish Cache Segmentation

`HttpContextPlugin` injects `orangecat_company_id` into Magento's HTTP Vary context. Both Varnish and Magento's built-in FPC automatically create separate cache buckets per company ID — no custom Varnish VCL is required. The plugin also fixes a core Magento FPC bug where `Kernel::load` and `Kernel::process` produce different cache keys for logged-in customers, causing perpetual cache misses.

### Disabling Without Uninstalling

Downstream modules that register calculators must be disabled first:

```bash
bin/magento module:disable Orangecat_PricesCompany Orangecat_PricesList Orangecat_Prices
bin/magento setup:upgrade
bin/magento cache:flush
```

When disabled, all B2B price overrides are inactive. Standard catalog prices are shown to all users, including company members.

### Data Integrity

This module introduces no database tables or schema changes. Disabling or uninstalling it leaves no residual data in the database. All pricing data is owned and managed by the downstream modules.
