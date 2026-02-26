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

namespace Orangecat\Prices\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ResolutionMode implements OptionSourceInterface
{
    public const MODE_LOWEST   = 'lowest_price';
    public const MODE_PRIORITY = 'priority';

    /**
     * Get options
     *
     * @return array[]
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::MODE_LOWEST, 'label' => __('Lowest Price (Best for Customer)')],
            ['value' => self::MODE_PRIORITY, 'label' => __('Priority (Last Module Executed)')]
        ];
    }
}
