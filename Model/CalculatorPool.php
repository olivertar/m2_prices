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

use Orangecat\Prices\Api\PriceCalculatorInterface;
use InvalidArgumentException;

/**
 * Registry holding all active B2B price calculators.
 */
class CalculatorPool
{
    /**
     * @var PriceCalculatorInterface[]
     */
    private array $calculators = [];

    /**
     * @param PriceCalculatorInterface[] $calculators
     */
    public function __construct(array $calculators = [])
    {
        foreach ($calculators as $code => $calculator) {
            if (!$calculator instanceof PriceCalculatorInterface) {
                throw new InvalidArgumentException(
                    sprintf('Calculator "%s" must implement %s', $code, PriceCalculatorInterface::class)
                );
            }
            $this->calculators[$code] = $calculator;
        }
    }

    /**
     * Retrieve all registered price calculators.
     * The order in which they are returned determines their execution priority
     * (configured via di.xml or sorting mechanism).
     *
     * @return PriceCalculatorInterface[]
     */
    public function getCalculators(): array
    {
        return $this->calculators;
    }
}
