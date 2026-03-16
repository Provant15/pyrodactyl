<?php

namespace Pterodactyl\Http\Controllers\Api\Admin;

use Pterodactyl\Models\Egg;
use Illuminate\Http\JsonResponse;

/**
 * Provides egg listing for the admin panel's deploy/swap UI.
 * Returns egg summaries grouped by nest, including resource minimums
 * needed for the EggPicker component to validate against slot resources.
 */
class EggController extends AdminApiController
{
    /**
     * List all eggs with their nest and resource requirements.
     */
    public function index(): JsonResponse
    {
        $eggs = Egg::with('nest:id,name')
            ->select([
                'id', 'uuid', 'nest_id', 'name', 'description',
                'docker_images', 'min_memory', 'min_disk', 'min_cpu',
                'archive_excludes',
            ])
            ->orderBy('nest_id')
            ->orderBy('name')
            ->get()
            ->map(fn (Egg $egg) => [
                'id' => $egg->id,
                'uuid' => $egg->uuid,
                'name' => $egg->name,
                'description' => $egg->description,
                'nest' => $egg->nest ? [
                    'id' => $egg->nest->id,
                    'name' => $egg->nest->name,
                ] : null,
                'docker_images' => $egg->docker_images,
                'minimums' => [
                    'memory' => $egg->min_memory ?? 0,
                    'disk' => $egg->min_disk ?? 0,
                    'cpu' => $egg->min_cpu ?? 0,
                ],
                'has_archive_excludes' => !empty($egg->archive_excludes),
            ]);

        return new JsonResponse(['data' => $eggs]);
    }
}
