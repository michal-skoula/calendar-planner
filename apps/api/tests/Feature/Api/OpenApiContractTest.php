<?php

namespace Tests\Feature\Api;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The spec and the router describe the same API.
 *
 * MatchesOpenApi checks the responses tests happen to make. This checks the
 * other direction: no route exists that the spec does not mention, and the spec
 * promises nothing the router does not serve.
 */
class OpenApiContractTest extends TestCase
{
    public function test_every_api_route_is_in_the_spec_and_every_spec_path_is_routed(): void
    {
        $routed = collect(Router::getRoutes()->getRoutes())
            ->filter(fn (Route $route): bool => str_starts_with($route->uri(), 'api/'))
            ->flatMap(fn (Route $route): array => collect($route->methods())
                ->reject(fn (string $method): bool => $method === 'HEAD')
                ->map(fn (string $method): string => strtolower($method).' /'.$route->uri())
                ->all())
            ->sort()->values()->all();

        $spec = Yaml::parseFile(base_path('openapi.yaml'));

        $documented = collect($spec['paths'])
            ->flatMap(fn (array $operations, string $path): array => collect($operations)
                ->keys()
                ->reject(fn (string $key): bool => $key === 'parameters')
                ->map(fn (string $method): string => $method.' '.$path)
                ->all())
            ->sort()->values()->all();

        $this->assertSame($routed, $documented);
    }
}
