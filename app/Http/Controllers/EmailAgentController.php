<?php

namespace App\Http\Controllers;

use App\Models\Email;
use App\Services\AnthropicException;
use App\Services\EmailAgentService;
use App\Services\InvoiceAgentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * The Inbox's AI actions: read an email, either draft a reply or flag it,
 * and - when the email's action item is something the app can actually do
 * (right now: "don't pay this invoice without checking") - offer to do it,
 * but only once a human clicks to approve.
 */
class EmailAgentController extends Controller
{
    /**
     * POST /api/emails/{email}/draft - drafts a reply, flags for a human,
     * or (for invoice disputes) attaches an action_item describing the task
     * found in the email and the invoice it applies to. Never saves
     * anything itself.
     */
    public function draft(Email $email, EmailAgentService $agent, InvoiceAgentService $invoiceAgent): JsonResponse
    {
        try {
            $result = $agent->draft($email);
        } catch (AnthropicException $e) {
            return response()->json(['error' => $e->getMessage()], $e->status());
        }

        if ($result['action'] === 'flag' && $result['category'] === 'invoice_dispute') {
            $invoice = $invoiceAgent->matchInvoiceForEmail($email);
            if ($invoice) {
                $result['action_item'] = [
                    'invoice_id' => $invoice->id,
                    'invoice_filename' => $invoice->filename,
                    'label' => "Pull {$invoice->filename} into the books with these numbers, and flag it as disputed so it can't be mistaken for a clean, payable invoice.",
                ];
            }
        }

        return response()->json($result);
    }

    /**
     * POST /api/emails/{email}/act - the one step that actually writes
     * anything: books the disputed invoice found by draft() above, tagged
     * with why. Requires the human to have already seen and approved the
     * action_item - the frontend only calls this from that confirmation.
     */
    public function act(Email $email, InvoiceAgentService $invoiceAgent): JsonResponse
    {
        $invoice = $invoiceAgent->matchInvoiceForEmail($email);

        if (! $invoice) {
            return response()->json(['error' => "Couldn't find a matching invoice for this email anymore."], 404);
        }

        try {
            $note = "{$email->from_name} emailed questioning this invoice (\"{$email->subject}\"): ".Str::limit($email->body, 200);
            $invoice = $invoiceAgent->holdAsDisputed($invoice, $note);
        } catch (AnthropicException $e) {
            return response()->json(['error' => $e->getMessage()], $e->status());
        }

        return response()->json([
            'invoice_id' => $invoice->id,
            'invoice_filename' => $invoice->filename,
            'summary' => "Booked {$invoice->supplier}, {$invoice->total} total, and flagged it disputed. Nothing has been paid — check it before you settle it.",
        ]);
    }
}
