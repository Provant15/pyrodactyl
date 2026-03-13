<?php

namespace Pterodactyl\Http\Controllers\Api\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Pterodactyl\Http\Controllers\Controller;

/**
 * Base controller for all Admin API endpoints.
 * Uses Laravel API Resources (not Fractal transformers).
 */
abstract class AdminApiController extends Controller
{
    /**
     * Returns a 204 No Content response.
     */
    protected function returnNoContent(): JsonResponse
    {
        return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
    }

    /**
     * Returns a 201 Created response with the given resource.
     *
     * Uses the resource's own response builder to preserve headers and
     * resource-level response configuration.
     *
     * @param JsonResource $resource The API resource to return
     */
    protected function returnCreated(JsonResource $resource): JsonResponse
    {
        return $resource->response()->setStatusCode(JsonResponse::HTTP_CREATED);
    }
}
