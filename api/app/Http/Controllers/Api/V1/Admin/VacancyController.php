<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Api\V1\Admin\StoreVacancyRequest;
use App\Http\Requests\Api\V1\Admin\UpdateVacancyRequest;
use App\Http\Resources\VacancyResource;
use App\Models\Vacancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VacancyController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $query = Vacancy::query();

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(fn ($vacancies) => $vacancies
                ->where('title', 'like', "%{$search}%")
                ->orWhere('location', 'like', "%{$search}%"));
        }

        if ($request->has('is_published')) {
            $query->where('is_published', $request->boolean('is_published'));
        }

        $vacancies = $query
            ->orderBy('sort_order')
            ->orderByDesc('published_at')
            ->orderByDesc('created_at')
            ->paginate((int) $request->input('per_page', 15));

        return $this->paginated(VacancyResource::collection($vacancies));
    }

    public function store(StoreVacancyRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['is_published'] = $data['is_published'] ?? false;
        $data['sort_order'] = $data['sort_order'] ?? 0;
        $data['published_at'] = $data['is_published'] ? now() : null;

        $vacancy = Vacancy::create($data);

        return $this->created(new VacancyResource($vacancy), 'Vacancy created successfully.');
    }

    public function show(Vacancy $vacancy): JsonResponse
    {
        return $this->success(new VacancyResource($vacancy));
    }

    public function update(UpdateVacancyRequest $request, Vacancy $vacancy): JsonResponse
    {
        $data = $request->validated();

        if (array_key_exists('is_published', $data)) {
            $data['published_at'] = $data['is_published']
                ? ($vacancy->published_at ?? now())
                : null;
        }

        $vacancy->update($data);

        return $this->success(new VacancyResource($vacancy->fresh()), 'Vacancy updated successfully.');
    }

    public function destroy(Vacancy $vacancy): JsonResponse
    {
        $vacancy->delete();

        return $this->success(null, 'Vacancy deleted successfully.');
    }
}
