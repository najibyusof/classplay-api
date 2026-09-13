<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

class OpenApiController
{
    public function ui(): View
    {
        return view('swagger');
    }

    public function specification(): JsonResponse
    {
        $paths = [];

        foreach (Route::getRoutes() as $route) {
            if (! $this->isDocumentedApiRoute($route)) {
                continue;
            }

            $path = $this->openApiPath($route->uri());
            $methods = array_filter($route->methods(), fn (string $method): bool => $method !== 'HEAD');

            foreach ($methods as $method) {
                $operation = [
                    'operationId' => $this->operationId($route, $method),
                    'summary' => $this->summary($route),
                    'tags' => [$this->tag($route)],
                    'responses' => $this->responses($route),
                ];

                if ($this->requiresAuthentication($route)) {
                    $operation['security'] = [['sanctumBearer' => []]];
                }

                $parameters = $this->parameters($route);
                if ($parameters !== []) {
                    $operation['parameters'] = $parameters;
                }

                if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
                    $operation['requestBody'] = [
                        'required' => false,
                        'content' => [
                            'application/json' => [
                                'schema' => ['type' => 'object'],
                            ],
                        ],
                    ];
                }

                $paths[$path][strtolower($method)] = $operation;
            }
        }

        ksort($paths);

        return response()->json([
            'openapi' => '3.0.3',
            'info' => [
                'title' => config('app.name', 'ClassPay').' API',
                'description' => 'ClassPay versioned REST API documentation generated from the registered Laravel routes.',
                'version' => '1.0.0',
            ],
            'servers' => [
                ['url' => rtrim(config('app.url'), '/')],
            ],
            'tags' => $this->tags($paths),
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'sanctumBearer' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'Sanctum token',
                        'description' => 'Use the token returned by POST /api/v1/auth/login.',
                    ],
                ],
            ],
        ]);
    }

    private function isDocumentedApiRoute(LaravelRoute $route): bool
    {
        return str_starts_with($route->uri(), 'api/v1/') && $route->getName() !== null;
    }

    private function openApiPath(string $uri): string
    {
        return '/'.preg_replace('/\{([^}]+)\??}/', '{$1}', preg_replace('/^api\/v1\//', '', $uri));
    }

    private function operationId(LaravelRoute $route, string $method): string
    {
        return strtolower($method).'_'.str_replace(['.', '-', '{', '}'], '_', (string) $route->getName());
    }

    private function summary(LaravelRoute $route): string
    {
        $name = (string) $route->getName();

        return ucwords(str_replace(['.', '-', '_'], ' ', preg_replace('/^v1\./', '', $name)));
    }

    private function tag(LaravelRoute $route): string
    {
        $name = (string) $route->getName();
        $segments = explode('.', preg_replace('/^v1\./', '', $name));

        return ucwords(str_replace(['-', '_'], ' ', $segments[0] ?? 'API'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parameters(LaravelRoute $route): array
    {
        $parameters = [];

        foreach ($route->parameterNames() as $name) {
            $parameters[] = [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'string'],
            ];
        }

        return $parameters;
    }

    /**
     * @return array<string, array<string, array<string, string>>>
     */
    private function responses(LaravelRoute $route): array
    {
        $responses = [
            '200' => ['description' => 'Successful response'],
            '401' => ['description' => 'Unauthenticated'],
            '403' => ['description' => 'Forbidden'],
            '404' => ['description' => 'Resource not found'],
            '422' => ['description' => 'Validation or business rule error'],
        ];

        if (in_array('POST', $route->methods(), true)) {
            $responses['201'] = ['description' => 'Resource created'];
        }

        return $responses;
    }

    /**
     * @param  array<string, mixed>  $paths
     * @return array<int, array{name: string}>
     */
    private function tags(array $paths): array
    {
        $tags = [];

        foreach ($paths as $operations) {
            foreach ($operations as $operation) {
                $name = $operation['tags'][0] ?? 'API';
                $tags[$name] = ['name' => $name];
            }
        }

        return array_values($tags);
    }

    private function requiresAuthentication(LaravelRoute $route): bool
    {
        return in_array('auth:sanctum', $route->gatherMiddleware(), true);
    }
}
