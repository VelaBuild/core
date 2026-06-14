<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use Illuminate\Support\Facades\Route;

class ListRoutesTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $filter = $parameters['filter'] ?? '';
        $routes = collect(Route::getRoutes()->getRoutes());

        if ($filter) {
            $routes = $routes->filter(function ($route) use ($filter) {
                return str_contains($route->uri(), $filter)
                    || str_contains($route->getName() ?? '', $filter)
                    || str_contains(implode('|', $route->methods()), strtoupper($filter));
            });
        }

        $results = $routes->map(function ($route) {
            return [
                'method' => implode('|', $route->methods()),
                'uri' => $route->uri(),
                'name' => $route->getName(),
                'action' => $route->getActionName(),
                'middleware' => implode(', ', $route->gatherMiddleware()),
            ];
        })->values()->take(100)->toArray();

        return ['routes' => $results, 'count' => count($results), 'total' => $routes->count()];
    }
}
