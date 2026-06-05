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

namespace Orangecat\Prices\Controller\Rewrite\Product;

use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\InventoryCatalogFrontendUi\Model\GetProductQtyLeft;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use Magento\InventoryCatalogFrontendUi\Controller\Product\GetQty as BaseGetQty;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Customer\Model\Session as CustomerSession;
use Orangecat\Company\Model\CompanyManagement;
use Orangecat\Prices\Model\TierPriceResolver;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Store\Model\StoreManagerInterface;

class GetQty extends BaseGetQty
{
    public function __construct(
        Context $context,
        private ResultFactory $resultPageFactory,
        private GetProductQtyLeft $productQty,
        private StockResolverInterface $stockResolver,
        private CustomerSession $customerSession,
        private CompanyManagement $companyManagement,
        private TierPriceResolver $tierPriceResolver,
        private ProductRepositoryInterface $productRepository,
        private PriceCurrencyInterface $priceCurrency,
        private StoreManagerInterface $storeManager
    ) {
        parent::__construct($context, $resultPageFactory, $productQty, $stockResolver);
    }

    public function execute(): ResultInterface
    {
        $sku = $this->getRequest()->getParam('sku');
        $salesChannel = $this->getRequest()->getParam('channel');
        $salesChannelCode = $this->getRequest()->getParam('salesChannelCode');
        
        $resultJson = $this->resultPageFactory->create(ResultFactory::TYPE_JSON);

        if (!$sku || $salesChannel === null || $salesChannelCode === null) {
            $resultJson->setData([
                'qty' => null,
                'tierPrices' => []
            ]);
            return $resultJson;
        }

        // Get basic QTY logic
        try {
            $stockId = $this->stockResolver->execute($salesChannel, $salesChannelCode)->getStockId();
            $qty = $this->productQty->execute($sku, (int)$stockId);
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            $qty = null;
        }

        // Resolve B2B Tier Prices
        $tierPrices = [];
        try {
            if ($this->customerSession->isLoggedIn()) {
                $customerId = (int)$this->customerSession->getCustomerId();
                $companyId = (int)$this->companyManagement->getCompanyIdByCustomerId($customerId);
                
                $storeId = (int)$this->storeManager->getStore()->getId();
                $product = $this->productRepository->get($sku, false, $storeId);
                $baseProductPrice = (float)$product->getPrice();
                
                $resolvedTiers = $this->tierPriceResolver->resolveTiers($sku, $companyId, $baseProductPrice);
                if (!empty($resolvedTiers)) {
                    foreach ($resolvedTiers as &$tier) {
                        $tier['formatted'] = $this->priceCurrency->format($tier['price'], false);
                    }
                    $tierPrices = $resolvedTiers;
                }
            }
        } catch (\Exception $e) {
            // Ignore error getting tier prices (e.g., product not found)
        }

        // Return combined JSON response
        $resultJson->setData([
            'qty' => $qty,
            'tierPrices' => $tierPrices
        ]);

        return $resultJson;
    }
}
