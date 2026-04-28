<?php

namespace App\Http\Controllers;

use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TagController extends Controller
{
    public function index()
    {
        // Fetch all tags from the database
        $tags = Tag::all();

        // Format each tag to match the structure
        $data = $tags->map(function ($tag) {
            return [
                'type' => 'tags',
                'id' => (string) $tag->id,  // Ensure the ID is a string
                'attributes' => [
                    'name' => $tag->name,
                    'color' => $tag->color,
                    'created_at' => $tag->created_at->toDateTimeString(),
                    'updated_at' => $tag->updated_at->toDateTimeString(),
                ],
                'links' => [
                    'self' => url("/api/v2/tags/{$tag->id}")
                ],
            ];
        });

        // Return the response with jsonapi structure
        return response()->json([
            'jsonapi' => ['version' => '2.0'],
            'data' => $data,
        ]);
    }

    // Show a single tag
    public function show($id)
    {
        $tag = Tag::with('items')->find($id);
        if (!$tag) {
            return response()->json(['error' => 'Tag not found'], Response::HTTP_NOT_FOUND);
        }
        return response()->json($tag, Response::HTTP_OK);
    }

    // Store a new tag
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'color' => 'nullable|string|max:7', // e.g., #FFFFFF
        ]);

        $tag = Tag::create($request->all());
        return response()->json($tag, Response::HTTP_CREATED);
    }

    // Update an existing tag
    public function update(Request $request, $id)
    {
        $tag = Tag::find($id);
        if (!$tag) {
            return response()->json(['error' => 'Tag not found'], Response::HTTP_NOT_FOUND);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'color' => 'nullable|string|max:7',
        ]);

        $tag->update($request->all());
        return response()->json($tag, Response::HTTP_OK);
    }

    // Delete a tag
    public function destroy($id)
    {
        $tag = Tag::find($id);
        if (!$tag) {
            return response()->json(['error' => 'Tag not found'], Response::HTTP_NOT_FOUND);
        }

        $tag->delete();
        return response()->json(['message' => 'Tag deleted successfully'], Response::HTTP_OK);
    }
}
