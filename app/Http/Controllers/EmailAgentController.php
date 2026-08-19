<?php

namespace App\Http\Controllers;

use App\Models\Email;
use App\Services\AnthropicException;
use App\Services\EmailAgentService;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/emails/{email}/draft - runs the email through EmailAgentService
 * and returns either a drafted reply or a flag explaining why a human needs
 * to handle it. This never saves anything; EmailController::reply is still
 * the only thing that persists a reply, and a human still has to click Send.
 */
class EmailAgentController extends Controller
{
    public function draft(Email $email, EmailAgentService $agent): JsonResponse
    {
        try {
            $result = $agent->draft($email);
        } catch (AnthropicException $e) {
            return response()->json(['error' => $e->getMessage()], $e->status());
        }

        return response()->json($result);
    }
}
