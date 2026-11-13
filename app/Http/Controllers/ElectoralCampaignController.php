<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

// Campañas electorales module (behind `feature:campanas`). Placeholder until the module exists.
class ElectoralCampaignController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => [], 'message' => 'Módulo de campañas electorales en construcción.']);
    }
}
