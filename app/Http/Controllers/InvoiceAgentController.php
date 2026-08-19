<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\AnthropicException;
use App\Services\InvoiceAgentService;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/invoices/{invoice}/extract - reads the scanned invoice text into
 * suggested entry-form fields, and checks whether a client has emailed
 * about this supplier. Never saves anything; InvoiceController::update is
 * still the only thing that persists an entry, and a human still has to
 * hit Save.
 */
class InvoiceAgentController extends Controller
{
    public function extract(Invoice $invoice, InvoiceAgentService $agent): JsonResponse
    {
        try {
            $result = $agent->extract($invoice);
        } catch (AnthropicException $e) {
            return response()->json(['error' => $e->getMessage()], $e->status());
        }

        return response()->json($result);
    }
}
