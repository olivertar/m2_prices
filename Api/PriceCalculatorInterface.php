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

namespace Orangecat\Prices\Api;

/**
 * Interface PriceCalculatorInterface
 *
 * All B2B pricing modules (Price Lists, Families, Company Prices) must implement
 * this interface and inject themselves into the CalculatorPool.
 */
interface PriceCalculatorInterface
{
    /**
     * Calculate the B2B price for a given product and company.
     * Must return the calculated float price, or null if this module does
     * not apply any special pricing for the given context.
     *
     * @param string $sku
     * @param float $qty
     * @param int $companyId
     * @param float $basePrice
     * @return float|null
     */
    public function calculate(string $sku, float $qty, int $companyId, float $basePrice = 0.0): ?float;
}
