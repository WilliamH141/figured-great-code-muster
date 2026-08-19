<?php

namespace App\Http\Controllers;

use App\Services\Anthropic;
use App\Services\AnthropicException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The AI proxy. The frontend posts { system?, prompt } here; we call the
 * Anthropic Messages API (via App\Services\Anthropic) with the key from
 * .env and return { text }.
 *
 * The key stays on the server. Never call the Anthropic API from browser
 * code, and never put the key anywhere in the frontend.
 */
class AiController extends Controller
{
    public function __invoke(Request $request, Anthropic $anthropic): JsonResponse
    {
        $validated = $request->validate([
            'system' => ['nullable', 'string'],
            'prompt' => ['required', 'string'],
        ]);

        try {
            $text = $anthropic->complete($validated['prompt'], $validated['system'] ?? null);
        } catch (AnthropicException $e) {
            return response()->json(['error' => $e->getMessage()], $e->status());
        }

        return response()->json(['text' => $text]);
    }
}
