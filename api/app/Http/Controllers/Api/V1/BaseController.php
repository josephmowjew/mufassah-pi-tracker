<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;

abstract class BaseController extends Controller
{
    use ApiResponse;
}
