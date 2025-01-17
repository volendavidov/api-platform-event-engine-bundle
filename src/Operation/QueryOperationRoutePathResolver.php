<?php

declare(strict_types=1);

namespace ADS\Bundle\ApiPlatformEventEngineBundle\Operation;

use ApiPlatform\Core\PathResolver\OperationPathResolverInterface;

use function explode;
use function reset;

final class QueryOperationRoutePathResolver implements OperationPathResolverInterface
{
    public function __construct(private OperationPathResolverInterface $deferred)
    {
    }

    /**
     * @param array<mixed> $operation
     * @param bool|string $operationType
     *
     * @phpcsSuppress SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
     */
    public function resolveOperationPath(
        string $resourceShortName,
        array $operation,
        $operationType,
        ?string $operationName = null
    ): string {
        // @phpstan-ignore-next-line
        $path = $this->deferred->resolveOperationPath($resourceShortName, $operation, $operationType, $operationName);

        return self::removeQueryPart($path);
    }

    public static function removeQueryPart(string $url): string
    {
        $parts = explode('?', $url, 2);

        /** @var string $url */
        $url = reset($parts);

        return $url;
    }
}
