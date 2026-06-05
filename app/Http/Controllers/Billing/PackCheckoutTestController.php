<?php

namespace App\Http\Controllers\Billing;

use App\Models\CreditPackPurchase;
use App\Services\PackCheckoutService;
use Illuminate\Routing\Controller;
use RuntimeException;

class PackCheckoutTestController extends Controller
{
    public function checkout(string $purchaseId, PackCheckoutService $checkout)
    {
        $purchase = CreditPackPurchase::query()->find($purchaseId);

        if (! $purchase) {
            abort(404, 'Purchase not found');
        }

        if ($purchase->status !== 'pending') {
            return response()->json([
                'error' => 'Purchase is not pending',
                'status' => $purchase->status,
            ], 400);
        }

        $intent = $checkout->createCheckout($purchase);

        // Direct redirect naar Mollie checkout
        return redirect()->away($intent->checkout_url);
    }
}
