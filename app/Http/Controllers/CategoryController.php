<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CategoryController extends Controller
{
    // Display a listing of categories
    public function index()
    {
        // Get all categories from the database
        $categories = Category::all();

        // Map the categories to match the required structure
        $data = $categories->map(function ($category) {
            return [
                'type' => 'categories',
                'id' => (string) $category->id, // Ensure the id is a string
                'attributes' => [
                    'name' => $category->name,
                    'description' => $category->description,
                    'created_at' => $category->created_at->toISOString(), // Format to ISO 8601
                    'updated_at' => $category->updated_at->toISOString(), // Format to ISO 8601
                ],
                'links' => [
                    'self' => url("/api/v2/categories/{$category->id}"), // Generate the self link
                ],
            ];
        });

        // Return the response with the 'jsonapi' version and the data
        return response()->json([
            'jsonapi' => ['version' => '2.0'],
            'data' => $data,
        ]);
    }


    // Show a single category
    public function show($id)
    {
        $category = Category::with('items')->find($id);
        if (!$category) {
            return response()->json(['error' => 'Category not found'], Response::HTTP_NOT_FOUND);
        }
        return response()->json($category, Response::HTTP_OK);
    }

    // Store a new category
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $category = Category::create($request->all());
        return response()->json($category, Response::HTTP_CREATED);
    }

    // Update an existing category
    public function update(Request $request, $id)
    {
        $category = Category::find($id);
        if (!$category) {
            return response()->json(['error' => 'Category not found'], Response::HTTP_NOT_FOUND);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $category->update($request->all());
        return response()->json($category, Response::HTTP_OK);
    }

    // Delete a category
    public function destroy($id)
    {
        $category = Category::find($id);
        if (!$category) {
            return response()->json(['error' => 'Category not found'], Response::HTTP_NOT_FOUND);
        }

        $category->delete();
        return response()->json(['message' => 'Category deleted successfully'], Response::HTTP_OK);
    }
}
