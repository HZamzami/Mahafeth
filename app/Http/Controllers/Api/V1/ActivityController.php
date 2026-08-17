<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivityCategory;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ActivityEventResource;
use App\Models\ActivityEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ActivityController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $category = ActivityCategory::tryFrom((string) $request->query('category', ''));

        $query = ActivityEvent::whereBelongsTo($request->user())->latest('id');

        if ($category !== null) {
            $query->whereIn('type', $category->types());
        }

        return ActivityEventResource::collection($query->paginate(25));
    }
}
