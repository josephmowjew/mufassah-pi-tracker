<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Branch;
use Illuminate\Http\JsonResponse;

class PublicBranchController extends BaseController
{
    public function index(): JsonResponse
    {
        $branches = Branch::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'address', 'city', 'phone', 'email']);

        return $this->success($branches);
    }
}
