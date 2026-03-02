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
     * Set company id to HTTP context during Action dispatch.
     * This is required for Varnish, because Varnish skips Kernel::load
     * and relies on Action dispatch to populate the context before DepersonalizePlugin clears the session.
     *
     * @param \Magento\Framework\App\ActionInterface $subject
     * @return void
     */
    public function beforeExecute(\Magento\Framework\App\ActionInterface $subject): void
    {
        $this->populateContext($this->httpContext);
    }

    /**
     * Set company id to HTTP context before Vary String generation.
     * This is required for Magento Built-in FPC, because Kernel::load generates the vary string
     * before Action dispatch, requiring an early context injection.
     *
     * @param Context $subject
     * @return void
     */
    public function beforeGetVaryString(Context $subject): void
    {
        $this->populateContext($subject);
    }

    /**
     * Shared logic to fetch company ID from session and safely inject into Context.
     *
     * @param Context $context
     */
    private function populateContext(Context $context): void
    {
        // Prevent overwriting context on hits where the value was already set or deserialized
        if ($context->getValue('orangecat_company_id') !== null) {
            return;
        }

        $companyId = 0;
        $session = $this->sessionFactory->create();

        // This is safe because if the session has been cleared by DepersonalizePlugin,
        // it means we are at the end of a Varnish MISS, but populateContext would have
        // already been called by beforeExecute, so it would return early above.
        if ($session->isLoggedIn()) {
            $customerId = (int)$session->getCustomerId();
            $companyId = (int)$this->companyManagement->getCompanyIdByCustomerId($customerId);

            // CRITICAL FIX FOR MAGENTO BUILT-IN FPC BUG:
            // Magento's Built-in FPC `Kernel::load` uses `IdentifierForSave` which calculates 
            // the cache key from the context BEFORE `ActionInterface` runs.
            // We inject the core Magento context early here to ensure LoadKey == SaveKey.
            $context->setValue(
                \Magento\Customer\Model\Context::CONTEXT_GROUP,
                (string)$session->getCustomerGroupId(),
                \Magento\Customer\Model\Group::NOT_LOGGED_IN_ID
            );
            $context->setValue(
                \Magento\Customer\Model\Context::CONTEXT_AUTH,
                true,
                false
            );
        }

        $context->setValue(
            'orangecat_company_id',
            $companyId,
            0
        );
    }

    public function OldFPCbeforeGetVaryString(Context $subject): void
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
