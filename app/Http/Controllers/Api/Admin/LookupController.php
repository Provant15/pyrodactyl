<?php

namespace Pterodactyl\Http\Controllers\Api\Admin;

use Pterodactyl\Models\Node;
use Pterodactyl\Models\User;
use Pterodactyl\Models\Allocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Provides lightweight lookup data for admin forms.
 * Returns minimal projections for select dropdowns and autocomplete fields.
 */
class LookupController extends AdminApiController
{
    /**
     * List users for select dropdowns (id, username, email).
     */
    public function users(Request $request): JsonResponse
    {
        $query = User::select(['id', 'username', 'email', 'name_first', 'name_last']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('username', 'ilike', "%{$search}%")
                  ->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        $users = $query->orderBy('username')->limit(50)->get();

        return new JsonResponse(['data' => $users]);
    }

    /**
     * List nodes for select dropdowns (id, name, location, FQDN).
     */
    public function nodes(): JsonResponse
    {
        $nodes = Node::with('location:id,short')
            ->select(['id', 'name', 'location_id', 'fqdn', 'memory', 'disk'])
            ->orderBy('name')
            ->get()
            ->map(fn (Node $node) => [
                'id' => $node->id,
                'name' => $node->name,
                'fqdn' => $node->fqdn,
                'location' => $node->location->short ?? null,
                'memory' => $node->memory,
                'disk' => $node->disk,
            ]);

        return new JsonResponse(['data' => $nodes]);
    }

    /**
     * Get the next available port on a node and a summary of port usage.
     * The frontend pre-fills this port but lets the admin override it.
     */
    public function nextPort(Node $node): JsonResponse
    {
        $nextAvailable = Allocation::where('node_id', $node->id)
            ->whereNull('server_id')
            ->orderBy('port')
            ->value('port');

        $usedCount = Allocation::where('node_id', $node->id)
            ->whereNotNull('server_id')
            ->count();

        $totalCount = Allocation::where('node_id', $node->id)->count();

        return new JsonResponse([
            'data' => [
                'next_port' => $nextAvailable,
                'used_ports' => $usedCount,
                'total_ports' => $totalCount,
            ],
        ]);
    }
}
