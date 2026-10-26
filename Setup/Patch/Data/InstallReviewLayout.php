<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxPaymentPayPal\Setup\Patch\Data;

use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use OOCS\LynxPageBuilder\Model\Design;
use OOCS\LynxPageBuilder\Setup\Patch\Data\CreateDefaultDesign;

/**
 * The layout the PayPal Express review page starts life with
 */
class InstallReviewLayout implements DataPatchInterface
{
    private const TABLE = 'lynx_pagebuilder_layout';

    /**
     * Module data setup, json
     *
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param Json $json
     */
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly Json $json,
    ) {
    }

    /**
     * Get Dependencies
     *
     * @return array
     */
    public static function getDependencies(): array
    {
        // The row carries a design id, and that design is created there.
        return [CreateDefaultDesign::class];
    }

    /**
     * Get Aliases
     *
     * @return array
     */
    public function getAliases(): array
    {
        return [];
    }

    /**
     * Apply
     *
     * @return self
     */
    public function apply(): self
    {
        $this->moduleDataSetup->getConnection()->insertOnDuplicate(
            $this->moduleDataSetup->getTable(self::TABLE),
            [
                'design_id' => Design::DEFAULT_ID,
                'path'      => 'paypal_express_review',
                'value'     => $this->json->serialize([
                    // The invariants of a stored node (see InstallCheckoutLayouts)
                    [
                        'label'     => 'Group',
                        'name'      => 'default_group',
                        'component' => 'OOCS_LynxThemeComponent/Components/Group',
                        'props'     => ['layout' => 'constrained', 'width' => 'site', 'padding_y' => 'md'],
                        'visible'   => true,
                        'slots'     => [
                            'default' => [
                                [
                                    'label'     => 'PayPal Review',
                                    'name'      => 'default_paypal_review',
                                    'component' => 'OOCS_LynxPaymentPayPal/Components/Express/Review',
                                    'props'     => [],
                                    'visible'   => true,
                                ],
                            ],
                        ],
                    ],
                ]),
            ],
            ['value']
        );

        return $this;
    }
}
