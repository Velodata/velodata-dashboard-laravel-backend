<?php

namespace App\Http\Controllers;

use App\Models\Task;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    // GET /tasks - Get all tasks, or filter by user_email if provided
    public function index(Request $request)
    {
        $email = $request->query('user_email');

        $tasks = $email
            ? Task::where('user_email', $email)->get()
            : Task::all();

        return response()->json($tasks, 200);
    }

    // POST /tasks - Create a new task
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'due_date' => 'required|date',
            'completed' => 'boolean',
            'user_email' => 'required|email'
        ]);

        $task = Task::create([
            'title' => $validated['title'],
            'description' => $validated['description'],
            'due_date' => $validated['due_date'],
            'completed' => $validated['completed'] ?? false,
            'user_email' => $validated['user_email']
        ]);

        return response()->json([
            'message' => 'Task created successfully',
            'task' => $task
        ], 200);
    }

    // PATCH /tasks/complete/{id} - Mark task as completed
    public function markComplete($id)
    {
        $task = Task::find($id);

        if (!$task) {
            return response()->json(['message' => 'Task not found'], 404);
        }

        $task->completed = true;
        $task->save();

        return response()->json($task, 200);
    }

    // PATCH /tasks/notComplete/{id} - Mark task as incomplete
    public function markIncomplete($id)
    {
        $task = Task::find($id);

        if (!$task) {
            return response()->json(['message' => 'Task not found'], 404);
        }

        $task->completed = false;
        $task->save();

        return response()->json([
            'task' => $task,
            'message' => "Task marked as not complete"
        ], 200);
    }

    // PUT /tasks/{id} - Update task details
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'due_date' => 'required|date',
            'completed' => 'boolean',
            'user_email' => 'required|email'
        ]);

        $task = Task::find($id);

        if (!$task) {
            return response()->json(['message' => 'Task not found'], 404);
        }

        $task->update($validated);

        return response()->json([
            'message' => 'Task updated successfully',
            'UpdatedTask' => $task
        ], 200);
    }

    // DELETE /tasks/{id} - Delete a task
    public function destroy($id)
    {
        $task = Task::find($id);

        if (!$task) {
            return response()->json(['message' => 'Task not found'], 404);
        }

        $task->delete();

        return response()->json([
            'task' => $task,
            'message' => 'Task deleted successfully'
        ], 200);
    }
}
