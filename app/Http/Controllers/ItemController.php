<?php

namespace App\Http\Controllers;

use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Carbon;


class ItemController extends Controller
{
    public function index()
    {
        // Fetch items with related user, category, and tag
        $items = Item::with(['category', 'user', 'tag'])
            ->where('status', 'published')
            ->get();

        // Transform the items into the JSON:API format
        return response()->json([
            'jsonapi' => [
                'version' => '2.0'
            ],
            'data' => $items->map(function ($item) {
                return [
                    'type' => 'items',
                    'id' => (string) $item->id, // Ensure ID is a string
                    'attributes' => [
                        'name' => $item->name,
                        'excerpt' => $item->excerpt,
                        'description' => $item->description,
                        'status' => $item->status,
                        'image' => $item->image,
                        'is_on_homepage' => true,
                        // 'date_at' => $item->date_at->toISOString(),
                        'date_at' => Carbon::parse($item->date_at)->toIso8601String(),
                        'created_at' => $item->created_at,
                        'updated_at' => $item->updated_at,
                    ],
                    'relationships' => [
                        'user' => [
                            'data' => [
                                'id' => (string) $item->user->id,
                                'type' => 'users',
                                'name' => $item->user->name, // Include user name
                            ],
                            'links' => [
                                'self' => route('users.show', $item->user->id)
                            ]
                        ],
                        'category' => [
                            'data' => [
                                'id' => (string) $item->category->id,
                                'type' => 'categories',
                                'name' => $item->category->name, // Include category name
                            ],
                            'links' => [
                                'self' => route('categories.show', $item->category->id)
                            ]
                        ],
                        'tag' => [
                            'data' => [
                                'id' => $item->tag ? (string) $item->tag->id : null, // Tag ID (nullable)
                                'type' => 'tags',
                                'name' => $item->tag ? $item->tag->name : null,
                                'color' => $item->tag ? $item->tag->color : null,
                            ],
                            'links' => [
                                'self' => $item->tag ? route('tags.show', $item->tag->id) : null
                            ]
                        ],
                    ],
                    'links' => [
                        'self' => route('items.show', $item->id)
                    ]
                ];
            }),
            'meta' => [
                'total_items' => $items->count(),
            ]
        ]);
    }














    public function show($id)
    {
        // Retrieve the item by ID
        $item = Item::findOrFail($id);

        // // Check if the 'include' parameter exists in the request
        // $includes = $this->getIncludes();

        // // Load relationships if the 'include' parameter is provided
        // if ($includes) {
        //     $item->load($includes);
        // }

        // Prepare the data
        $data = [
            'type' => 'items',
            'id' => (string) $item->id,
            'attributes' => [
                'name' => $item->name,
                'excerpt' => $item->excerpt,
                'description' => $item->description,
                'status' => $item->status,
                'image' => $item->image,
                'is_on_homepage' => $item->is_on_homepage,
                'date_at' => $item->date_at,
                'created_at' => $item->created_at->toDateTimeString(),
                'updated_at' => $item->updated_at->toDateTimeString(),
            ],
            'relationships' => [
                'user' => [
                    'data' => [
                        'id' => (string) $item->user->id,
                        'type' => 'users',
                        'name' => $item->user->name, // Include user name
                    ],
                    'links' => [
                        'self' => route('users.show', $item->user->id)
                    ]
                ],
                'category' => [
                    'data' => [
                        'id' => (string) $item->category->id,
                        'type' => 'categories',
                        'name' => $item->category->name, // Include category name
                    ],
                    'links' => [
                        'self' => route('categories.show', $item->category->id)
                    ]
                ],
                'tag' => [
                    'data' => [
                        'id' => $item->tag ? (string) $item->tag->id : null, // Tag ID (nullable)
                        'type' => 'tags',
                        'name' => $item->tag ? $item->tag->name : null,
                        'color' => $item->tag ? $item->tag->color : null,
                    ],
                    'links' => [
                        'self' => $item->tag ? route('tags.show', $item->tag->id) : null
                    ]
                ],
            ],
        ];



        // Return the response with the included section if necessary
        return response()->json([
            'jsonapi' => [
                'version' => '2.0',
            ],
            'links' => [
                'self' => route('items.show', $item->id),
            ],
            'data' => $data,
            // 'included' => $included, // Only include if 'include' was requested
        ]);
    }

    private function getIncludes()
    {
        $includes = request()->query('include');

        if ($includes) {
            return explode(',', $includes);
        }

        return [];
    }












    // Store a new item
    public function store(Request $request)
    {
        $data = $request->input('data');

        // Validate request data
        $validatedData = $request->validate([
            'data.attributes.name' => 'required|string|max:255',
            'data.attributes.status' => 'required|string',
            'data.attributes.description' => 'nullable|string',
            'data.attributes.excerpt' => 'nullable|string',
            'data.attributes.date_at' => 'nullable|date',
            'data.attributes.is_on_homepage' => 'nullable|boolean',
            'data.attributes.user_id' => 'required|integer|exists:users,id',
            'data.relationships.category.data.id' => 'required|integer|exists:categories,id',
            'data.relationships.tags.data.id' => 'required|integer|exists:tags,id',
        ]);


        // Extract user attributes
        $attributes = $data['attributes'];
        $relationships = $data['relationships'];
        $category = $relationships['category'];
        $date_at = $attributes['date_at'];
        $description = $attributes['description'];
        $name = $attributes['name'];
        $excerpt = $attributes['excerpt'];
        $is_on_homepage = $attributes['is_on_homepage'];
        $status = $attributes['status'];
        $user_id = $attributes['user_id'];
        $category_data = $category['data'];
        $categoryId = intval($validatedData['data']['relationships']['category']['data']['id']);


        $item = Item::create([
            'name' => $name,
            'status' => $status,
            'description' => $description,
            'excerpt' => $excerpt,
            'date_at' => $date_at,
            'is_on_homepage' => $is_on_homepage,
            'user_id' => $user_id,
        ]);


        $item->user_id = $attributes['user_id'];
        $item->category_id = intval($validatedData['data']['relationships']['category']['data']['id']);
        $item->tag_id = intval($validatedData['data']['relationships']['tags']['data']['id']);
        $item->save();


        // $item = Item::create($request->all());
        return response()->json($item, Response::HTTP_CREATED);
    }











    public function update(Request $request, $id)
    {
        // Validate incoming request
        $validated = $request->validate([
            'data.attributes.name' => 'required|string|max:255',
            'data.attributes.excerpt' => 'nullable|string',
            'data.attributes.description' => 'nullable|string',
            'data.attributes.status' => 'required|string|in:published,draft',
            'data.attributes.image' => 'nullable|url',
            'data.attributes.is_on_homepage' => 'boolean',
            'data.attributes.date_at' => 'nullable|date',
            'data.relationships.category.data.id' => 'required|integer|exists:categories,id',
            'data.relationships.tags.data.id' => 'required|integer|exists:tags,id',
        ]);

        // Find the item by ID
        $item = Item::findOrFail($id);

        // Update attributes
        $item->name = $validated['data']['attributes']['name'];
        $item->excerpt = $validated['data']['attributes']['excerpt'] ?? $item->excerpt;
        $item->description = $validated['data']['attributes']['description'] ?? $item->description;
        $item->status = $validated['data']['attributes']['status'];
        $item->image = $validated['data']['attributes']['image'] ?? $item->image;
        $item->is_on_homepage = $validated['data']['attributes']['is_on_homepage'] ?? $item->is_on_homepage;
        $item->date_at = $validated['data']['attributes']['date_at'] ?? $item->date_at;
        $item->category_id = intval($validated['data']['relationships']['category']['data']['id']);
        $item->tag_id = intval($validated['data']['relationships']['tags']['data']['id']);
        $item->save();

        // Return response
        return response()->json([
            'data' => [
                'type' => 'items',
                'id' => (string) $item->id,
                'attributes' => $item->toArray(),
            ]
        ], 200);
    }

    // Delete an item
    public function destroy($id)
    {
        $item = Item::find($id);
        if (!$item) {
            return response()->json(['error' => 'Item not found'], Response::HTTP_NOT_FOUND);
        }

        $item->delete();
        return response()->json(['message' => 'Item deleted successfully'], Response::HTTP_OK);
    }











    public function uploadItemImage(Request $request, $itemId)
    {
        $request->validate([
            'attachment' => 'required|image|max:2048',
        ]);

        $path = "items/{$itemId}/image";

        try {
            $filePath = Storage::disk('public')->put($path, $request->file('attachment'));

            if (!$filePath) {
                return response()->json([
                    'error' => [
                        'title' => 'Upload Error',
                        'detail' => 'Failed to upload profile image to ' . $path,
                        'status' => 500,
                    ]
                ], 500);
            }

            $fileUrl = Storage::url($filePath);

            // Update user profile_image URL in the database
            $item = Item::findOrFail($itemId);
            $appURL = config('app.url');
            $item->image = $appURL . $fileUrl;  //  $appURL lives in the Laravel .env file
            $item->save();

            return response()->json([
                'jsonapi' => ['version' => '2.0'],
                'data' => [
                    'type' => 'item-image',
                    'id' => $itemId,
                    'attributes' => [
                        'profile_image' => $appURL . $fileUrl,
                    ]
                ]
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => [
                    'title' => 'Server Error',
                    'detail' => 'An error occurred while uploading the image.',
                    'status' => 500,
                    'meta' => ['exception' => $e->getMessage()],
                ]
            ], 500);
        }
    }
}
