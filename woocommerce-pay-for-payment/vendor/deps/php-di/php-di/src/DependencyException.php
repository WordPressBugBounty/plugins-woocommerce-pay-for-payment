<?php

declare (strict_types=1);
namespace Pay4PaymentDeps\DI;

use Pay4PaymentDeps\Psr\Container\ContainerExceptionInterface;
/**
 * Exception for the Container.
 */
class DependencyException extends \Exception implements ContainerExceptionInterface
{
}
