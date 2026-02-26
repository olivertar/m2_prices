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

use Orangecat\Prices\Model\Config;
use Orangecat\Prices\Model\Config\Source\ResolutionMode;
use function min;
use function is_null;

/**
 * Resolves the final B2B price by asking all available calculators in the pool.
 */
class PriceResolver
{
    /**
     * @param CalculatorPool $calculatorPool
     * @param Config $config
     */
    public function __construct(
        private readonly CalculatorPool $calculatorPool,
        private readonly Config $config
    ) {
    }

    /**
     * Iterates through all pricing strategies (modules) and returns the final calculated price.
     *
     * Takes into account the Global Resolution Mode (Lowest vs Priority).
     *
     * @param string $sku
     * @param float $qty
     * @param int $companyId
     * @param float $basePrice The base catalog price of the product to compare against
     * @return float|null Returns the new price, or null if no module modifies the price.
     */
    public function resolve(string $sku, float $qty, int $companyId, float $basePrice): ?float
    {
        if (!$this->config->isEnabled()) {
            return null; // B2B Engine is disabled
        }

        $calculators = $this->calculatorPool->getCalculators();
        if (empty($calculators)) {
            return null;
        }

        $resolutionMode = $this->config->getResolutionMode();
        $finalPrice     = null;

        foreach ($calculators as $code => $calculator) {
            $calculatedPrice = $calculator->calculate($sku, $qty, $companyId, $basePrice);

            if ($calculatedPrice === null) {
                continue; // This module has no price for this context
            }

            if ($finalPrice === null) {
                $finalPrice = $calculatedPrice;
                continue;
            }

            if ($resolutionMode === ResolutionMode::MODE_LOWEST) {
                $finalPrice = min($finalPrice, $calculatedPrice);
            } else {
                // Priority mode: Since the array of calculators respects di.xml sortOrder,
                // the last executed calculator overwrites the previous ones.
                $finalPrice = $calculatedPrice;
            }
        }

        // B2B Prices should ideally not be strictly higher than the base catalog price,
        // but we return whatever the engine resolved. We can add a check later if needed.
        return $finalPrice;
    }
}
