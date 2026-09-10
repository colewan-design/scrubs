<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = Category::query()
            ->active()
            ->whereNull('parent_id')
            ->with('children')
            ->orderBy('position')
            ->get()
            ->map(fn (Category $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                'description' => $c->description,
                'image_path' => $c->image_path,
                'children' => $c->children->map(fn ($child) => [
                    'name' => $child->name,
                    'slug' => $child->slug,
                ]),
            ]);

        return response()->json(['data' => $categories]);
    }

    public function show(Category $category): JsonResponse
    {
        abort_unless($category->is_active, 404);

        return response()->json(['data' => [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'meta' => [
                'title' => $category->meta_title ?? $category->name,
                'description' => $category->meta_description ?? $category->description,
            ],
        ]]);
    }
}
