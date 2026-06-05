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
     * @param \Magento\Framework\App\Http\Context $httpContext
     */
    public function __construct(
        private readonly PriceResolver $priceResolver,
        private readonly CustomerSession $customerSession,
        private readonly CompanyManagement $companyManagement,
        private readonly \Magento\Framework\App\Http\Context $httpContext
    ) {
    }

    /**
     * Returns the overridden price if applicable.
     *
     * @param FinalPrice $subject
     * @param float|bool $result
     * @return float|bool
     */
    public function afterGetValue(FinalPrice $subject, $result)
    {
        // Try getting the company ID from the Http Context first (Works for FPC)
        $companyId = (int)$this->httpContext->getValue('orangecat_company_id');

        // Fallback to customer session if not in Context (regular non-FPC request or early request)
        if (!$companyId && $this->customerSession->isLoggedIn()) {
            $customerId = (int)$this->customerSession->getCustomerId();
            $companyId = (int)$this->companyManagement->getCompanyIdByCustomerId($customerId);
        }

        /** @var \Magento\Catalog\Model\Product $product */
        $product = $subject->getProduct();

        if (!$companyId) {
            return $result;
        }

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
