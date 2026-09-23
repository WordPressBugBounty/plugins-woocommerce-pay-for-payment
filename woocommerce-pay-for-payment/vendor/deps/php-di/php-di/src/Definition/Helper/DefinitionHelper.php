<?php

declare (strict_types=1);
namespace Pay4PaymentDeps\DI\Definition\Helper;

use Pay4PaymentDeps\DI\Definition\Definition;
/**
 * Helps defining container entries.
 *
 * @author Matthieu Napoli <matthieu@mnapoli.fr>
 */
interface DefinitionHelper
{
    /**
     * @param string $entryName Container entry name
     */
    public function getDefinition(string $entryName): Definition;
}
