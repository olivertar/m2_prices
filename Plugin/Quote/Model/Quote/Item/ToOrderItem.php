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

namespace Orangecat\Prices\Plugin\Quote\Model\Quote\Item;

use Magento\Quote\Model\Quote\Item\ToOrderItem as MagentoToOrderItem;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\Sales\Model\Order\Item as OrderItem;
use Orangecat\Prices\Model\PriceResolver;
use Magento\Customer\Model\Session as CustomerSession;
use Orangecat\Company\Model\CompanyManagement;

/**
 * Ensures that when a quote item is converted to an order item,
 * the final B2B orchestrated price is respected.
 * Note: A proper B2B implementation also requires intercepting the Cart Add action
 * (e.g. catalog_product_get_final_price event) so the cart reflects the price dynamically.
 */
class ToOrderItem
{
    /**
     * @param PriceResolver $priceResolver
     * @param CustomerSession $customerSession
     * @param CompanyManagement $companyManagement
     */
    public function __construct(
        private readonly PriceResolver $priceResolver,
        private readonly CustomerSession $customerSession,
        private readonly CompanyManagement $companyManagement
    ) {
    }

    /**
     * Converts the quote item and applies the orchestrated B2B price.
     *
     * @param MagentoToOrderItem $subject
     * @param OrderItem $orderItem
     * @param AbstractItem $quoteItem
     * @param array $data
     * @return OrderItem
     */
    public function afterConvert(
        MagentoToOrderItem $subject,
        OrderItem $orderItem,
        AbstractItem $quoteItem,
        $data = []
    ) {
        if (!$this->customerSession->isLoggedIn()) {
            return $orderItem;
        }

        $customerId = (int)$this->customerSession->getCustomerId();
        $companyId = (int)$this->companyManagement->getCompanyIdByCustomerId($customerId);

        if (!$companyId) {
            return $orderItem;
        }

        $product = $quoteItem->getProduct();
        if (!$product) {
            return $orderItem;
        }

        $typeId = $product->getTypeId();
        if (in_array($typeId, ['configurable', 'bundle', 'grouped'])) {
            return $orderItem;
        }

        $sku       = $product->getSku();
        $qty       = (float)$quoteItem->getQty();
        $basePrice = (float)$product->getPrice();

        $b2bPrice = $this->priceResolver->resolve($sku, $qty, (int)$companyId, $basePrice);

        if ($b2bPrice !== null) {
            $orderItem->setPrice($b2bPrice);
            $orderItem->setBasePrice($b2bPrice);
            $orderItem->setOriginalPrice($b2bPrice);
            $orderItem->setBaseOriginalPrice($b2bPrice);
        }

        return $orderItem;
    }
}
