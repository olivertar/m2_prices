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

namespace Orangecat\Prices\Plugin\Pricing;

use Magento\Catalog\Pricing\Price\FinalPrice;
use Orangecat\Prices\Model\PriceResolver;
use Magento\Customer\Model\Session as CustomerSession;
use Orangecat\Company\Model\CompanyManagement;

class FinalPricePlugin
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
     * Returns the overridden price if applicable.
     *
     * @param FinalPrice $subject
     * @param float|bool $result
     * @return float|bool
     */
    public function afterGetValue(FinalPrice $subject, $result)
    {
        // Avoid calculation if the customer is not logged in / FPC context without identity
        if (!$this->customerSession->isLoggedIn()) {
            return $result;
        }

        $customerId = (int)$this->customerSession->getCustomerId();
        $companyId = (int)$this->companyManagement->getCompanyIdByCustomerId($customerId);

        if (!$companyId) {
            return $result;
        }

        /** @var \Magento\Catalog\Model\Product $product */
        $product = $subject->getProduct();

        $typeId = $product->getTypeId();
        if (in_array($typeId, ['configurable', 'bundle', 'grouped'])) {
            return $result;
        }

        $sku = $product->getSku();
        $qty = 1.0; // The Pricing architecture usually calculates per unit base price unless tiered.

        // BasePrice here should be the native fallback price (result) so Percentage discounts work correctly
        $basePrice = (float)$result;

        $b2bPrice = $this->priceResolver->resolve($sku, $qty, $companyId, $basePrice);

        if ($b2bPrice !== null) {
            return (float)$b2bPrice;
        }

        return $result;
    }
}
