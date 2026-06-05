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

namespace Orangecat\Prices\Plugin\Pricing\Render\PriceBox;

use Magento\Framework\Pricing\Render\PriceBox;
use Magento\Framework\App\Http\Context;
use Magento\Customer\Model\Session as CustomerSession;
use Orangecat\Company\Model\CompanyManagement;

class CacheKeyPlugin
{
    /**
     * @param Context $httpContext
     * @param CustomerSession $customerSession
     * @param CompanyManagement $companyManagement
     */
    public function __construct(
        private readonly Context $httpContext,
        private readonly CustomerSession $customerSession,
        private readonly CompanyManagement $companyManagement
    ) {
    }

    /**
     * Append company ID to cache key to separate block scope caching by company context.
     *
     * @param PriceBox $subject
     * @param string $result
     * @return string
     */
    public function afterGetCacheKey(PriceBox $subject, string $result): string
    {
        $companyId = (int)$this->httpContext->getValue('orangecat_company_id');

        if (!$companyId && $this->customerSession->isLoggedIn()) {
            $customerId = (int)$this->customerSession->getCustomerId();
            $companyId = (int)$this->companyManagement->getCompanyIdByCustomerId($customerId);
        }

        if ($companyId) {
            return rtrim($result, '-') . '-' . $companyId . '-';
        }

        return $result;
    }
}
