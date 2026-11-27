<?php

declare(strict_types=1);

namespace Modules\CMS\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\CMS\Services\TagTypeDefinition;
use Modules\CMS\Services\TagTypeRegistry;
use Modules\Core\Helpers\ResponseBuilder;

/**
 * Read-only endpoint listing the registered tag types with their translated label and icons.
 */
final class TagTypesController extends Controller
{
    public function __construct(
        private readonly TagTypeRegistry $registry,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $response = new ResponseBuilder($request);
        $response->setData(array_map(
            static fn (TagTypeDefinition $definition): array => [
                'type' => $definition->type,
                'label' => __($definition->labelKey),
                'heroicon' => $definition->heroicon,
                'lucide' => $definition->lucide,
            ],
            $this->registry->all(),
        ));

        return $response->json();
    }
}
