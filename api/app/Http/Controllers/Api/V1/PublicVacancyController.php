<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\VacancyResource;
use App\Models\Vacancy;
use Illuminate\Http\JsonResponse;

class PublicVacancyController extends BaseController
{
    public function index(): JsonResponse
    {
        $vacancies = Vacancy::query()
            ->published()
            ->orderBy('sort_order')
            ->orderByDesc('published_at')
            ->get();

        return $this->success(VacancyResource::collection($vacancies));
    }
}
