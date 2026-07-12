<?php
declare(strict_types=1);

namespace App\Support;

use RuntimeException;

// Thin wrapper over Groq's OpenAI-compatible chat-completions endpoint.
// Centralizes API-key/model lookup, transport (curl with a stream fallback),
// and error translation so every caller reports failures the same way.
class GroqClient
{
    private const ENDPOINT = 'https://api.groq.com/openai/v1/chat/completions';

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @param array<string, mixed> $options Extra top-level payload fields
     *        (e.g. max_tokens, response_format). Merged over the defaults.
     */
    public static function complete(array $messages, array $options = []): string
    {
        $apiKey = Settings::get('groq_api_key');
        if (!$apiKey) {
            throw new RuntimeException('The Groq API key has not been configured yet in the admin settings.');
        }

        $payload = json_encode(array_merge([
            'model' => Settings::get('groq_model', 'llama-3.1-8b-instant'),
            'max_tokens' => 1500,
            'messages' => $messages,
        ], $options));

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ];

        [$status, $response] = function_exists('curl_init')
            ? self::postViaCurl($payload, $headers)
            : self::postViaStream($payload, $headers);

        $decoded = json_decode($response, true);

        if ($status === 429) {
            throw new RuntimeException(
                'The Groq model in use has hit its usage limit for today. '
                . 'An admin can switch to a different model in Settings to restore service immediately, '
                . 'or wait for the daily limit to reset.'
            );
        }

        if ($status !== 200) {
            $apiMessage = $decoded['error']['message'] ?? 'Unknown API error';
            throw new RuntimeException("Groq API error ({$status}): {$apiMessage}");
        }

        return (string) ($decoded['choices'][0]['message']['content'] ?? '');
    }

    /** @return array{0: int, 1: string} */
    private static function postViaCurl(string $payload, array $headers): array
    {
        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Failed to reach the Groq API: ' . $error);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$status, (string) $response];
    }

    /** @return array{0: int, 1: string} */
    private static function postViaStream(string $payload, array $headers): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $payload,
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents(self::ENDPOINT, false, $context);
        if ($response === false) {
            throw new RuntimeException('Failed to reach the Groq API.');
        }

        $status = 0;
        if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }

        return [$status, $response];
    }
}
