<?php

declare(strict_types=1);

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\Info;
use ApiPlatform\OpenApi\Model\Paths;
use ApiPlatform\OpenApi\OpenApi;

/**
 * The root of the decorator chain now that API Platform's own factory is gone (IP-157).
 *
 * AuthDecorator, QuestDecorator and StorageDecorator were written as decorators over
 * `api_platform.openapi.factory` and hand-describe every endpoint in the API Platform OpenAPI
 * model. API Platform the framework was removed (it served zero resources); the model package
 * `api-platform/openapi` stays so the three decorators compile unchanged, and this class gives
 * them an empty document to decorate. DecoratorDescriber then feeds the result to Nelmio, which
 * is what /api/doc renders.
 */
final class EmptyOpenApiFactory implements OpenApiFactoryInterface
{
    public function __invoke(array $context = []): OpenApi
    {
        // Title and version are placeholders: Nelmio's own `documentation.info` wins on merge.
        return new OpenApi(new Info('Monad API', '1.0.0'), [], new Paths());
    }
}
