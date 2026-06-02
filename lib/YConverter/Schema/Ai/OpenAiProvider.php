<?php

/**
 * This file is part of the YConverter package.
 *
 * @author (c) Yakamara Media GmbH & Co. KG
 */

namespace YConverter\Schema\Ai;

/**
 * OpenAI chat-completions provider. One HTTP call via rex_socket; no Composer dependency.
 */
class OpenAiProvider implements AiFieldProvider
{
    /** @var string */
    private $apiKey;
    /** @var string */
    private $model;

    public function __construct(string $apiKey, string $model = '')
    {
        $this->apiKey = $apiKey;
        $this->model = '' !== $model ? $model : 'gpt-4o-mini';
    }

    public function proposeFields(array $columns, array $allowedTypes, array $clangIds): array
    {
        $payload = [
            'model' => $this->model,
            'temperature' => 0,
            'messages' => [
                ['role' => 'system', 'content' => AiPrompt::system($allowedTypes, $clangIds)],
                ['role' => 'user', 'content' => AiPrompt::user($columns)],
            ],
        ];

        $socket = \rex_socket::factoryUrl('https://api.openai.com/v1/chat/completions');
        $socket->addHeader('Authorization', 'Bearer ' . $this->apiKey);
        $socket->addHeader('Content-Type', 'application/json');
        $socket->setTimeout(30);
        $response = $socket->doPost((string) json_encode($payload));

        if (!$response->isOk()) {
            return [];
        }
        $body = json_decode($response->getBody(), true);
        $text = isset($body['choices'][0]['message']['content']) ? (string) $body['choices'][0]['message']['content'] : '';

        return AiResponseParser::parse($text, $allowedTypes);
    }
}
