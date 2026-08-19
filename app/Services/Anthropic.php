<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around the Anthropic Messages API. The key lives in .env and
 * this is the only place that ever calls out to Claude - AiController and
 * EmailAgentController both go through here.
 */
class Anthropic
{
    /**
     * @throws AnthropicException
     */
    public function complete(string $prompt, ?string $system = null, int $maxTokens = 4096): string
    {
        $apiKey = config('services.anthropic.key');
        if (! $apiKey || str_starts_with($apiKey, 'sk-ant-your-key')) {
            throw new AnthropicException('No Anthropic API key configured. Set ANTHROPIC_API_KEY in .env (ask a Figgie for the key).');
        }

        $body = [
            'model' => 'claude-sonnet-4-6',
            'max_tokens' => $maxTokens,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];
        if ($system) {
            $body['system'] = $system;
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
            ])->timeout(120)->post('https://api.anthropic.com/v1/messages', $body);
        } catch (ConnectionException $e) {
            throw new AnthropicException('Could not reach the Anthropic API: '.$e->getMessage());
        }

        if ($response->failed()) {
            throw new AnthropicException($response->json('error.message') ?? 'Anthropic API request failed.', $response->status());
        }

        // The response content is a list of blocks; concatenate the text ones.
        return collect($response->json('content'))
            ->where('type', 'text')
            ->pluck('text')
            ->implode('');
    }
}
