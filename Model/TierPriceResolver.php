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

/**
 * Resolves all B2B quantity tier prices by asking all available calculators.
 */
class TierPriceResolver
{
    /**
     * @param CalculatorPool $calculatorPool
     * @param Config $config
     */
    public function __construct(
        private readonly CalculatorPool $calculatorPool,
        private readonly Config $config
    ) {}

    /**
     * Aggregates quantity pricing tiers from all calculators based on resolution modes.
     *
     * @param string $sku
     * @param int $companyId
     * @param float $basePrice
     * @return array
     */
    public function resolveTiers(string $sku, int $companyId, float $basePrice): array
    {
        if (!$this->config->isTierPricesEnabled()) {
            return []; // Short-circuit: Tier Pricing is disabled
        }

        $calculators = $this->calculatorPool->getCalculators();
        if (empty($calculators)) {
            return [];
        }

        $resolutionMode = $this->config->getResolutionMode();
        $allTiers = [];

        foreach ($calculators as $code => $calculator) {
            $tiers = $calculator->getTiers($sku, $companyId, $basePrice);
            if (empty($tiers)) {
                continue;
            }

            foreach ($tiers as $tier) {
                $qtyStr = (string)$tier['qty'];
                $price = $tier['price'];

                if (!isset($allTiers[$qtyStr])) {
                    $allTiers[$qtyStr] = $price;
                } else {
                    if ($resolutionMode === ResolutionMode::MODE_LOWEST) {
                        if ($price < $allTiers[$qtyStr]) {
                            $allTiers[$qtyStr] = $price;
                        }
                    } else {
                        // Priority mode: Since the array of calculators respects di.xml sortOrder,
                        // the last executed calculator overwrites the previous ones.
                        $allTiers[$qtyStr] = $price;
                    }
                }
            }
        }

        $finalTiers = [];
        foreach ($allTiers as $qtyStr => $price) {
            $finalTiers[] = [
                'qty' => (float)$qtyStr,
                'price' => $price
            ];
        }

        // Must always be returned in quantity ascending order for UI
        usort($finalTiers, function ($a, $b) {
            return $a['qty'] <=> $b['qty'];
        });

        return $finalTiers;
    }
}
