<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReservePatientNumberRequest;
use App\Services\PatientNumbers;
use Illuminate\Http\JsonResponse;

class PatientNumberController extends Controller
{
    public function store(ReservePatientNumberRequest $request, PatientNumbers $numbers): JsonResponse
    {
        return $numbers->reserve($request->user('web'), (int) $request->validated('category_id'), $request->header('Idempotency-Key'));
    }
}
