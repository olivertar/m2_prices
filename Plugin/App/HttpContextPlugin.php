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

namespace Orangecat\Prices\Plugin\App;

use Magento\Framework\App\Http\Context;
use Magento\Customer\Model\SessionFactory;
use Orangecat\Company\Model\CompanyManagement;

/**
 * Sets the company ID in the HTTP context for FPC/Varnish cache segmentation.
 */
class HttpContextPlugin
{
    /**
     * @param SessionFactory $sessionFactory
     * @param CompanyManagement $companyManagement
     * @param Context $httpContext
     */
    public function __construct(
        private readonly SessionFactory $sessionFactory,
        private readonly CompanyManagement $companyManagement,
        private readonly Context $httpContext
    ) {}

    /**
     * Set company id to HTTP context before Vary String generation for FPC consistency
     *
     * @param Context $subject
     * @return void
     */
    public function beforeGetVaryString(Context $subject): void
    {
        $companyId = 0;

        // Prevent overwriting context on FPC hits where the value was already deserialized
        if ($subject->getValue('orangecat_company_id') !== null) {
            $companyId = (int)$subject->getValue('orangecat_company_id');
        }

        // Only start session and check database if we don't already have the company ID
        // This is safe even on cache hits because the session is preserved during early dispatch
        if (!$companyId) {
            $session = $this->sessionFactory->create();
            if ($session->isLoggedIn()) {
                $customerId = (int)$session->getCustomerId();
                $companyId = (int)$this->companyManagement->getCompanyIdByCustomerId($customerId);

                // CRITICAL FIX FOR MAGENTO FPC BUG:
                // Magento's Built-in FPC `Kernel::load` uses `IdentifierForSave` which calculates 
                // the cache key from the context BEFORE `ActionInterface` runs.
                // But `Kernel::process` calculates the save key AFTER `ActionInterface` runs (which adds `customer_group`).
                // This causes a perpetual cache MISS because LoadKey (missing group) != SaveKey (has group).
                // We inject the core Magento context early here to ensure LoadKey == SaveKey.
                $subject->setValue(
                    \Magento\Customer\Model\Context::CONTEXT_GROUP,
                    (string)$session->getCustomerGroupId(),
                    \Magento\Customer\Model\Group::NOT_LOGGED_IN_ID
                );
                $subject->setValue(
                    \Magento\Customer\Model\Context::CONTEXT_AUTH,
                    true,
                    false
                );
            }
        }

        if ($companyId) {
            $subject->setValue(
                'orangecat_company_id',
                $companyId,
                0
            );
        }
    }
}
