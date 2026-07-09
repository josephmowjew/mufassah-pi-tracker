<?php

namespace App\Http\Middleware;

use App\Models\IotModule;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateIotModule
{
    /**
     * Handle an incoming request.
     *
     * Authenticates IoT modules using their API token passed in the Authorization header.
     * Token format: Bearer {module_id}:{api_token}
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $authHeader = $request->header('Authorization');

        if (! $authHeader || ! str_starts_with($authHeader, 'Bearer ')) {
            return response()->json([
                'success' => false,
                'message' => 'Missing or invalid authorization header.',
            ], 401);
        }

        $token = substr($authHeader, 7); // Remove 'Bearer ' prefix

        // Token format: module_id:api_token
        $parts = explode(':', $token, 2);
        if (count($parts) !== 2) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid token format. Expected: module_id:api_token',
            ], 401);
        }

        [$moduleId, $apiToken] = $parts;

        // Find the IoT module by module_id
        $module = IotModule::where('module_id', $moduleId)->first();

        if (! $module) {
            return response()->json([
                'success' => false,
                'message' => 'IoT module not found.',
            ], 401);
        }

        // Verify the API token
        if (! Hash::check($apiToken, $module->api_token)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid API token.',
            ], 401);
        }

        // Check if module is active
        if (! $module->status->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'IoT module is not active.',
            ], 403);
        }

        // Check if module is linked to a device
        if (! $module->device) {
            return response()->json([
                'success' => false,
                'message' => 'IoT module is not linked to any device.',
            ], 403);
        }

        // Attach the authenticated module and device to the request
        $request->merge([
            'iot_module' => $module,
            'device' => $module->device,
        ]);

        // Update last ping timestamp
        $module->update(['last_ping' => now()]);

        return $next($request);
    }
}
