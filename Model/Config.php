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

namespace Orangecat\Prices\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Orangecat\Prices\Model\Config\Source\ResolutionMode;

/**
 * Provides access to the system configuration settings for the overarching Prices module.
 */
class Config
{
    /**
     * XML Paths
     */
    public const XML_PATH_ENABLED         = 'prices/general/enabled';
    public const XML_PATH_RESOLUTION_MODE = 'prices/general/resolution_mode';

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Check if the B2B Custom Pricing Engine is globally enabled.
     *
     * @param int|string|null $storeId
     * @return bool
     */
    public function isEnabled($storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get the configured resolution mode for cross-module conflicting prices.
     *
     * Returns 'lowest_price' or 'priority'.
     *
     * @param int|string|null $storeId
     * @return string
     */
    public function getResolutionMode($storeId = null): string
    {
        $mode = $this->scopeConfig->getValue(
            self::XML_PATH_RESOLUTION_MODE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $mode ?: ResolutionMode::MODE_LOWEST;
    }
}
