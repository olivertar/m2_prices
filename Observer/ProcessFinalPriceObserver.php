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

namespace Orangecat\Prices\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Orangecat\Prices\Model\PriceResolver;
use Magento\Customer\Model\Session as CustomerSession;
use Orangecat\Company\Model\CompanyManagement;
use Magento\Catalog\Model\Product;

/**
 * Intercepts the final price calculation natively in PHP.
 * This ensures that when a product is added to the cart, the quote engine
 * uses the orchestrated B2B price instead of the public catalog price.
 */
class ProcessFinalPriceObserver implements ObserverInterface
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
    ) {}

    /**
     * Set the B2B price in the cart product.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        if (!$this->customerSession->isLoggedIn()) {
            return;
        }

        $customerId = (int)$this->customerSession->getCustomerId();
        $companyId = (int)$this->companyManagement->getCompanyIdByCustomerId($customerId);

        if (!$companyId) {
            return; // Not a B2B customer inside a company context
        }

        /** @var Product $product */
        $product = $observer->getEvent()->getProduct();

        $typeId = $product->getTypeId();
        if (in_array($typeId, ['configurable', 'bundle', 'grouped'])) {
            return;
        }

        $qty = 1.0;
        if ($observer->getEvent()->getQty() !== null) {
            $qty = (float)$observer->getEvent()->getQty();
        } elseif ($product->getCustomOption('info_buyRequest')) {
            $buyRequest = $product->getCustomOption('info_buyRequest');
            $value = $buyRequest->getValue();
            if ($value && is_string($value)) {
                $data = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE && isset($data['qty'])) {
                    $qty = (float)$data['qty'];
                }
            }
        }
        $qty = $qty > 0 ? $qty : 1.0;
        $sku       = $product->getSku();
        $basePrice = (float)$product->getPrice();

        $b2bPrice = $this->priceResolver->resolve($sku, $qty, (int)$companyId, $basePrice);

        if ($b2bPrice !== null) {
            $product->setFinalPrice($b2bPrice);
            // Some catalog rules and cart math rely on SpecialPrice or Price being overwritten too
            // product->setPrice($b2bPrice);
        }
    }
}
