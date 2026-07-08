<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Semester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SemesterController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Semester::orderByDesc('start_date')->get());
    }

    /**
     * Admin only (see routes/api.php - 'role:admin' middleware).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'academic_year' => ['required', 'string', 'max:20'],
            'semester_number' => ['required', 'integer', Rule::in([1, 2, 3])],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
        ]);

        $semester = Semester::create($data);

        return response()->json($semester, 201);
    }

    public function activate(Semester $semester): JsonResponse
    {
        Semester::where('is_active', true)->update(['is_active' => false]);
        $semester->update(['is_active' => true]);

        return response()->json($semester);
    }

    /**
     * Admin can edit a semester (in case they made a mistake earlier).
     */
    public function update(Request $request, Semester $semester): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'academic_year' => ['sometimes', 'string', 'max:20'],
            'semester_number' => ['sometimes', 'integer', Rule::in([1, 2, 3])],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'date', 'after:start_date'],
        ]);

        $semester->update($data);

        return response()->json($semester);
    }
}
