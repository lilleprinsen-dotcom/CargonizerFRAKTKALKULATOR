<?php

namespace Lilleprinsen\Cargonizer\Compatibility;

use Automattic\WooCommerce\Utilities\FeaturesUtil;

final class CompatibilityBridge
{
    public function declareWooCommerceFeaturesCompatibility(): void
    {
        if (!class_exists(FeaturesUtil::class)) {
            return;
        }

        FeaturesUtil::declare_compatibility('custom_order_tables', LP_CARGONIZER_PLUGIN_FILE, true);
    }
}
