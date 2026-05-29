<?php

/**
 * This file is part of the Orangecat Prices package.
 *
 * (c) Oliverio Gombert <olivertar@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Orangecat\Prices\Controller\Ajax;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Orangecat\Company\Model\CompanyManagement;
use Orangecat\Prices\Model\PriceResolver;
use Orangecat\Prices\Model\TierPriceResolver;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableType;

/**
 * AJAX endpoint that returns B2B prices for a batch of SKUs.
 *
 * URL: prices/ajax/ajaxprices (POST)
 *
 * Request body (JSON): {"skus": ["SKU-001", "SKU-002", ...]}
 *
 * Response (JSON):
 * {
 *   "prices": {
 *     "SKU-001": {"final_price": 12.50, "formatted": "$12.50"},
 *     ...
 *   },
 *   "configurable_prices": {
 *     "42": {
 *       "finalPrice": {"amount": 12.50},
 *       "basePrice": {"amount": 12.50}
 *     },
 *     ...
 *   }
 * }
 */
class AjaxPrices implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /**
     * Maximum number of SKUs per request to prevent abuse.
     */
    private const MAX_SKUS = 200;

    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly CustomerSession $customerSession,
        private readonly CompanyManagement $companyManagement,
        private readonly PriceResolver $priceResolver,
        private readonly TierPriceResolver $tierPriceResolver,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ConfigurableType $configurableType
    ) {}

    /**
     * @inheritdoc
     */
    public function execute()
    {
        $result = $this->jsonFactory->create();

        // Only process for logged-in users (guests get empty object)
        if (!$this->customerSession->isLoggedIn()) {
            return $result->setData(['prices' => new \stdClass(), 'is_logged_in' => false]);
        }

        $customerId = (int)$this->customerSession->getCustomerId();
        $companyId = (int)$this->companyManagement->getCompanyIdByCustomerId($customerId);
        // If the user has no company ($companyId = 0), we STILL process the request
        // because we need to return the base prices to populate the empty server-side DOM.

        // Parse JSON body
        $body = $this->request->getContent();
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $result->setData(['prices' => new \stdClass()]);
        }

        $skusInput = isset($data['skus']) && is_array($data['skus']) ? array_slice($data['skus'], 0, self::MAX_SKUS) : [];
        $idsInput = isset($data['product_ids']) && is_array($data['product_ids']) ? array_slice($data['product_ids'], 0, self::MAX_SKUS) : [];
        $tierIdsInput = isset($data['tier_product_ids']) && is_array($data['tier_product_ids']) ? array_slice($data['tier_product_ids'], 0, self::MAX_SKUS) : [];

        if (empty($skusInput) && empty($idsInput) && empty($tierIdsInput)) {
            return $result->setData(['prices' => new \stdClass(), 'is_logged_in' => true]);
        }

        $skus = array_unique($skusInput);
        $productIds = array_unique(array_filter($idsInput, 'is_numeric'));
        $tierProductIds = array_unique(array_filter($tierIdsInput, 'is_numeric'));

        $prices = [];
        $pricesById = [];
        $configurablePrices = [];
        $bundlePrices = [];
        $tierPrices = [];

        $productsToProcess = [];

        foreach ($skus as $sku) {
            if (!is_string($sku) || $sku === '') continue;
            try {
                $product = $this->productRepository->get($sku);
                $productsToProcess[$product->getId()] = $product;
            } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                continue;
            }
        }

        foreach ($productIds as $pId) {
            if (isset($productsToProcess[$pId])) continue;
            try {
                $product = $this->productRepository->getById($pId);
                $productsToProcess[$product->getId()] = $product;
            } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                continue;
            }
        }

        foreach ($tierProductIds as $pId) {
            if (isset($productsToProcess[$pId])) continue;
            try {
                $product = $this->productRepository->getById($pId);
                $productsToProcess[$product->getId()] = $product;
            } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                continue;
            }
        }

        foreach ($productsToProcess as $product) {
            $sku = $product->getSku();
            $productId = $product->getId();
            $typeId = $product->getTypeId();

            $baseProductPrice = (float)$product->getPrice();

            if (in_array((string)$productId, $tierProductIds) || in_array((int)$productId, $tierProductIds)) {
                $resolvedTiers = $this->tierPriceResolver->resolveTiers($sku, $companyId, $baseProductPrice);
                if (!empty($resolvedTiers)) {
                    foreach ($resolvedTiers as &$tier) {
                        $tier['formatted'] = $this->priceCurrency->format($tier['price'], false);
                    }
                    $tierPrices[$sku] = $resolvedTiers;
                }
            }

            if ($typeId === 'configurable') {
                // For configurables: resolve each child simple product price
                $childProducts = $this->configurableType->getUsedProducts($product);
                $minPrice = null;

                foreach ($childProducts as $child) {
                    $childSku = $child->getSku();
                    $basePrice = (float)$child->getPrice();
                    $b2bPrice = $this->priceResolver->resolve($childSku, 1.0, $companyId, $basePrice);
                    $childFinalPrice = $b2bPrice !== null ? $b2bPrice : $basePrice;

                    $childTierPrices = [];
                    if (in_array((string)$productId, $tierProductIds) || in_array((int)$productId, $tierProductIds)) {
                        $resolvedChildTiers = $this->tierPriceResolver->resolveTiers($childSku, $companyId, $basePrice);
                        if (!empty($resolvedChildTiers)) {
                            foreach ($resolvedChildTiers as $tier) {
                                $percentage = 0;
                                if ($childFinalPrice > 0) {
                                    $percentage = ceil(100 - (($tier['price'] / $childFinalPrice) * 100));
                                }
                                $childTierPrices[] = [
                                    'qty' => $tier['qty'],
                                    'price' => $tier['price'],
                                    'formatted' => $this->priceCurrency->format($tier['price'], false),
                                    'percentage' => max(0, $percentage),
                                    'basePrice' => $tier['price']
                                ];
                            }
                        }
                    }

                    $configurablePrices[$child->getId()] = [
                        'finalPrice' => ['amount' => $childFinalPrice],
                        'basePrice' => ['amount' => $childFinalPrice],
                        'oldPrice' => ['amount' => (float)$child->getPrice()],
                    ];

                    if (!empty($childTierPrices)) {
                        $configurablePrices[$child->getId()]['tierPrices'] = $childTierPrices;
                    }

                    if ($minPrice === null || $childFinalPrice < $minPrice) {
                        $minPrice = $childFinalPrice;
                    }
                }

                // The configurable's displayed price is the minimum child price
                if ($minPrice !== null) {
                    $priceData = [
                        'final_price' => $minPrice,
                        'formatted' => $this->priceCurrency->format($minPrice, false),
                    ];
                    $prices[$sku] = $priceData;
                    $pricesById[$productId] = $priceData;
                }
            } elseif ($typeId === 'bundle') {
                $typeInstance = $product->getTypeInstance();
                if (method_exists($typeInstance, 'getOptionsIds') && method_exists($typeInstance, 'getSelectionsCollection')) {
                    $optionIds = $typeInstance->getOptionsIds($product);
                    $selectionCollection = $typeInstance->getSelectionsCollection($optionIds, $product);
                    $options = $typeInstance->getOptionsCollection($product);
                    $options->appendSelections($selectionCollection);

                    if (!isset($bundlePrices[$productId])) {
                        $bundlePrices[$productId] = [];
                    }

                    $priceType = $product->getPriceType();
                    $isFixed = $priceType == \Magento\Bundle\Model\Product\Price::PRICE_TYPE_FIXED;

                    $bundleBasePrice = 0;
                    if ($isFixed) {
                        $basePrice = (float)$product->getPrice();
                        $b2bPrice = $this->priceResolver->resolve($sku, 1.0, $companyId, $basePrice);
                        $bundleBasePrice = $b2bPrice !== null ? $b2bPrice : $basePrice;
                    }

                    $minPrice = $bundleBasePrice;
                    $maxPrice = $bundleBasePrice;

                    foreach ($options as $option) {
                        if (!$option->getSelections()) {
                            continue;
                        }

                        $optMin = null;
                        $optMax = 0;
                        $isMulti = in_array($option->getType(), ['multi', 'checkbox']);

                        foreach ($option->getSelections() as $selection) {
                            $childSku = $selection->getSku();
                            $selectionId = $selection->getSelectionId();

                            if ($isFixed) {
                                // For fixed bundles, options just add a fixed or percentage amount
                                $selectionPriceType = $selection->getSelectionPriceType(); // 0 = fixed, 1 = percent
                                $selectionPriceValue = (float)$selection->getSelectionPriceValue();
                                
                                if ($selectionPriceType == 1) { // percent
                                    $childFinalPrice = $bundleBasePrice * ($selectionPriceValue / 100);
                                } else { // fixed
                                    // Should we discount the option upcharge? B2B usually discounts the bundle base price.
                                    // For simplicity, we just add the fixed upcharge
                                    $childFinalPrice = $selectionPriceValue;
                                }
                                $oldPrice = $childFinalPrice;
                            } else {
                                // Dynamic bundles
                                $basePrice = (float)$selection->getPrice();
                                $b2bPrice = $this->priceResolver->resolve($childSku, 1.0, $companyId, $basePrice);
                                $childFinalPrice = $b2bPrice !== null ? $b2bPrice : $basePrice;
                                $oldPrice = (float)$selection->getPrice();
                            }

                            $bundlePrices[$productId][$selectionId] = [
                                'finalPrice' => ['amount' => $childFinalPrice],
                                'basePrice' => ['amount' => $childFinalPrice],
                                'oldPrice' => ['amount' => $oldPrice],
                            ];

                            if ($isMulti) {
                                $optMax += $childFinalPrice;
                            } else {
                                if ($childFinalPrice > $optMax) {
                                    $optMax = $childFinalPrice;
                                }
                            }

                            if ($optMin === null || $childFinalPrice < $optMin) {
                                $optMin = $childFinalPrice;
                            }
                        }

                        if ($option->getRequired()) {
                            $minPrice += $optMin !== null ? $optMin : 0;
                        }
                        $maxPrice += $optMax;
                    }

                    $pricesById[$productId] = [
                        'final_price' => $minPrice,
                        'formatted' => $this->priceCurrency->format($minPrice, false),
                        'min_price' => $minPrice,
                        'min_price_formatted' => $this->priceCurrency->format($minPrice, false),
                        'max_price' => $maxPrice,
                        'max_price_formatted' => $this->priceCurrency->format($maxPrice, false),
                    ];
                }
            } elseif ($typeId === 'grouped') {
                // Skip grouped for now
                continue;
            } else {
                // Simple / Virtual / Downloadable
                $basePrice = (float)$product->getPrice();
                $b2bPrice = $this->priceResolver->resolve($sku, 1.0, $companyId, $basePrice);
                $finalPrice = $b2bPrice !== null ? $b2bPrice : $basePrice;

                $priceData = [
                    'final_price' => $finalPrice,
                    'formatted' => $this->priceCurrency->format($finalPrice, false),
                ];
                $prices[$sku] = $priceData;
                $pricesById[$productId] = $priceData;
            }
        }

        $responseData = [
            'is_logged_in' => true,
            'prices' => empty($prices) ? new \stdClass() : $prices,
            'prices_by_id' => empty($pricesById) ? new \stdClass() : $pricesById
        ];

        if (!empty($configurablePrices)) {
            $responseData['configurable_prices'] = $configurablePrices;
        }

        if (!empty($bundlePrices)) {
            $responseData['bundle_prices'] = $bundlePrices;
        }

        if (!empty($tierPrices)) {
            $responseData['tier_prices'] = $tierPrices;
        }

        return $result->setData($responseData);
    }

    /**
     * @inheritdoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @inheritdoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        // This is a JSON API endpoint — CSRF is not applicable
        return true;
    }
}
