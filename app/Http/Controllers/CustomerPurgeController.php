<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\CustomerPurgeService;
use Illuminate\Http\JsonResponse;

// pktakip: müşteriyi altındaki her şeyle birlikte silme (özet + silme). Mevcut CustomerController::destroy değişmez.
class CustomerPurgeController extends Controller
{
    public function __construct(private CustomerPurgeService $service)
    {
    }

    public function summary(Customer $customer): JsonResponse
    {
        return response()->json($this->service->summary($customer));
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $this->service->purge($customer);

        return response()->json(['message' => 'Müşteri ve altındaki tüm kayıtlar silindi.']);
    }
}
