<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ActivityEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ActivityEvent
 */
class ActivityEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'category' => $this->type->category()->value,
            'label' => $this->type->label($this->params ?? []),
            'icon' => $this->type->icon(),
            'color' => $this->type->color(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
