<?php

declare(strict_types=1);

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Serializer\OpenApiNormalizer;
use Nelmio\ApiDocBundle\Describer\ExternalDocDescriber;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Bridges the hand-written OpenAPI decorators into Nelmio (IP-157).
 *
 * Until API Platform was removed, Nelmio picked the decorated document up through its own
 * `ApiPlatformDescriber`, which is only wired when ApiPlatformBundle is registered. This class is
 * that describer's shape with the bundle-specific services replaced: the outermost decorator
 * (StorageDecorator, over QuestDecorator, over AuthDecorator, over EmptyOpenApiFactory) produces
 * the document, `OpenApiNormalizer` over the application serializer turns it into the array
 * Nelmio merges, and the same three keys Nelmio drops are dropped here for the same reason
 * (zircote/swagger-php does not accept API Platform's `openapi` version string; `servers` come
 * from nelmio_api_doc.yaml).
 *
 * Registered in services.yaml with the tag `nelmio_api_doc.describer` at the priority Nelmio
 * used for its API Platform describer, so the merge order is unchanged.
 */
final class DecoratorDescriber extends ExternalDocDescriber
{
    public function __construct(OpenApiFactoryInterface $factory, NormalizerInterface $normalizer)
    {
        parent::__construct(static function () use ($factory, $normalizer): array {
            $document = (new OpenApiNormalizer($normalizer))->normalize($factory(), OpenApiNormalizer::FORMAT);

            unset($document['openapi'], $document['basePath'], $document['servers']);

            return $document;
        });
    }
}
