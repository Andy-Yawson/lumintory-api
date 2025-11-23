<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Str;
use Symfony\Component\HttpFoundation\Response;

class AuditLogMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $start = microtime(true);

        $response = $next($request);

        try {
            $this->log($request, $response, $start);
        } catch (\Throwable $e) {
            \Log::warning('Audit logging failed: ' . $e->getMessage());
        }

        return $response;
    }

    protected function log(Request $request, $response, float $start): void
    {
        $user = $request->user();
        $tenant = $user?->tenant;

        // Skip some noise routes if you want
        if ($this->shouldSkip($request)) {
            return;
        }

        // Log only write ops (you can add GET if you want full trail)
        if (!in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            return;
        }

        $durationMs = (microtime(true) - $start) * 1000;

        $route = $request->route();
        $action = $route?->getActionName(); // Controller@method

        $requestData = $request->except([
            'password',
            'password_confirmation',
            'current_password',
            'token',
        ]);

        $responseBody = null;
        if (!$response->isSuccessful()) {
            $responseBody = [
                'body' => Str::limit($response->getContent(), 1000),
            ];
        }

        AuditLog::create([
            'tenant_id' => $tenant?->id,
            'user_id' => $user?->id,
            'event' => $this->deriveEventName($route),
            'method' => $request->method(),
            'route' => $request->path(),
            'controller' => $action,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit($request->userAgent() ?? '', 255),
            'status_code' => $response->getStatusCode(),
            'duration_ms' => $durationMs,
            'request' => $requestData,
            'response' => $responseBody,
        ]);
    }

    protected function shouldSkip(Request $request): bool
    {
        $path = $request->path();

        $skipPatterns = [
            'sanctum/*',
            'telescope/*',
            'horizon/*',
        ];

        foreach ($skipPatterns as $pattern) {
            if (Str::is($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    protected function deriveEventName($route): ?string
    {
        if (!$route) {
            return null;
        }

        if ($route->getName()) {
            return $route->getName(); // e.g. products.store
        }

        return $route->getActionName();
    }
}
