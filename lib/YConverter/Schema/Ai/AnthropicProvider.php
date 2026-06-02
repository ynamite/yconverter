<?php

/**
 * This file is part of the YConverter package.
 *
 * @author (c) Yakamara Media GmbH & Co. KG
 */

namespace YConverter\Schema\Ai;

/**
 * Anthropic messages provider. One HTTP call via rex_socket; no Composer dependency.
 */
class AnthropicProvider implements AiFieldProvider
{
    /** @var string */
    private $apiKey;
    /** @var string */
    private $model;

    public function __construct(string $apiKey, string $model = '')
    {
        $this->apiKey = $apiKey;
        $this->model = '' !== $model ? $model : 'claude-haiku-4-5';
    }

    public function proposeFields(array $columns, array $allowedTypes, array $clangIds): array
    {
        $payload = [
            'model' => $this->model,
            'max_tokens' => 1024,
            'system' => AiPrompt::system($allowedTypes, $clangIds),
            'messages' => [
                ['role' => 'user', 'content' => AiPrompt::user($columns)],
            ],
        ];

        $socket = \rex_socket::factoryUrl('https://api.anthropic.com/v1/messages');
        $socket->addHeader('x-api-key', $this->apiKey);
        $socket->addHeader('anthropic-version', '2023-06-01');
        $socket->addHeader('Content-Type', 'application/json');
        $socket->setTimeout(30);
        $response = $socket->doPost((string) json_encode($payload));

        if (!$response->isOk()) {
            return [];
        }
        $body = json_decode($response->getBody(), true);
        $text = isset($body['content'][0]['text']) ? (string) $body['content'][0]['text'] : '';

        return AiResponseParser::parse($text, $allowedTypes);
    }
}
