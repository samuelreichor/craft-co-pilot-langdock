<?php

namespace samuelreichor\coPilotLangdock\providers;

use samuelreichor\coPilot\helpers\HttpClientFactory;
use samuelreichor\coPilot\helpers\Logger;
use samuelreichor\coPilot\helpers\StreamHelper;
use samuelreichor\coPilot\models\AIResponse;
use samuelreichor\coPilot\models\StreamChunk;
use samuelreichor\coPilot\providers\ProviderInterface;

class LangdockAnthropicProvider implements ProviderInterface
{
    private const API_VERSION = '2023-06-01';

    private string $model = 'claude-sonnet-4-6';

    public function getHandle(): string
    {
        return 'anthropic';
    }

    public function getName(): string
    {
        return 'Anthropic';
    }

    public function getIcon(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M13.827 3.52h3.603L24 20.48h-3.603l-6.57-16.96zm-7.258 0h3.767L16.906 20.48h-3.674l-1.343-3.461H5.017l-1.344 3.46H0l6.57-16.96zm1.04 3.88L5.2 13.796h4.822L7.609 7.4z"/></svg>';
    }

    public function getApiKey(): ?string
    {
        return LangdockConfig::getApiKey();
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getAvailableModels(): array
    {
        return LangdockConfig::filterAndNormalizeModels(
            LangdockConfig::fetchAllModels(),
            ['claude-'],
        );
    }

    public function getTitleModel(): string
    {
        $models = $this->getAvailableModels();
        $preferred = ['claude-haiku-4-5', 'claude-sonnet-4-6'];
        foreach ($preferred as $candidate) {
            foreach ($models as $m) {
                if (str_starts_with($m, $candidate)) {
                    return $m;
                }
            }
        }

        return $this->model;
    }

    public function validateApiKey(string $key): bool
    {
        return true;
    }

    public function getDefaultConfig(): array
    {
        return ['model' => 'claude-sonnet-4-6'];
    }

    public function setConfig(array $config): void
    {
        $this->model = $config['model'] ?? 'claude-sonnet-4-6';
    }

    public function getSettingsHtml(array $config, array $fileConfig): string
    {
        // Rendered by LangdockOpenAIProvider in the shared settings block
        return '';
    }

    public function formatTools(array $tools): array
    {
        return array_map(fn(array $tool) => [
            'name' => $tool['name'],
            'description' => $tool['description'],
            'input_schema' => $tool['parameters'],
        ], $tools);
    }

    public function chat(
        string $systemPrompt,
        array $messages,
        array $tools,
        ?string $model = null,
    ): AIResponse {
        $apiKey = $this->getApiKey();
        if (!$apiKey) {
            return AIResponse::error('Langdock API key not configured.');
        }

        $model = LangdockConfig::normalizeModelId($model ?? $this->model);
        $payload = [
            'model' => $model,
            'max_tokens' => 4096,
            'system' => $systemPrompt,
            'messages' => $this->formatMessages($messages),
        ];

        $formattedTools = $this->formatTools($tools);
        if (!empty($formattedTools)) {
            $payload['tools'] = $formattedTools;
        }

        Logger::info("Langdock Anthropic request: model={$model}");

        return $this->sendRequest($apiKey, $payload);
    }

    public function chatStream(
        string $systemPrompt,
        array $messages,
        array $tools,
        ?string $model,
        callable $onChunk,
    ): void {
        $apiKey = $this->getApiKey();
        if (!$apiKey) {
            $onChunk(new StreamChunk('error', error: 'Langdock API key not configured.'));
            return;
        }

        $model = LangdockConfig::normalizeModelId($model ?? $this->model);
        $payload = [
            'model' => $model,
            'max_tokens' => 4096,
            'system' => $systemPrompt,
            'messages' => $this->formatMessages($messages),
            'stream' => true,
        ];

        $formattedTools = $this->formatTools($tools);
        if (!empty($formattedTools)) {
            $payload['tools'] = $formattedTools;
        }

        Logger::info("Langdock Anthropic stream request: model={$model}");

        $this->sendStreamRequest($apiKey, $payload, $onChunk);
    }

    private function getBaseUrl(): string
    {
        return 'https://api.langdock.com/anthropic/' . LangdockConfig::getRegion() . '/v1';
    }

    private function getEndpointUrl(): string
    {
        return $this->getBaseUrl() . '/messages';
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    private function formatMessages(array $messages): array
    {
        $formatted = [];

        foreach ($messages as $message) {
            $role = $message['role'];

            if ($role === 'tool') {
                $toolResult = [
                    'type' => 'tool_result',
                    'tool_use_id' => $message['toolCallId'],
                    'content' => is_array($message['content'])
                        ? json_encode($message['content'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                        : $message['content'],
                ];
                if (!empty($message['isError'])) {
                    $toolResult['is_error'] = true;
                }
                $formatted[] = ['role' => 'user', 'content' => [$toolResult]];
                continue;
            }

            if ($role === 'assistant' && !empty($message['toolCalls'])) {
                $content = [];
                if (!empty($message['content'])) {
                    $content[] = ['type' => 'text', 'text' => $message['content']];
                }
                foreach ($message['toolCalls'] as $tc) {
                    $content[] = [
                        'type' => 'tool_use',
                        'id' => $tc['id'],
                        'name' => $tc['name'],
                        'input' => (object) ($tc['arguments'] ?: []),
                    ];
                }
                $formatted[] = ['role' => 'assistant', 'content' => $content];
                continue;
            }

            $formatted[] = [
                'role' => $role,
                'content' => is_array($message['content'])
                    ? json_encode($message['content'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    : $message['content'],
            ];
        }

        return $formatted;
    }

    private function sendRequest(string $apiKey, array $payload): AIResponse
    {
        $client = HttpClientFactory::create();
        try {
            $response = $client->post($this->getEndpointUrl(), [
                'headers' => [
                    'Authorization' => "Bearer {$apiKey}",
                    'anthropic-version' => self::API_VERSION,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
                'timeout' => 120,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return $this->parseResponse($data);
        } catch (\Throwable $e) {
            Logger::error('Langdock Anthropic error: ' . $e->getMessage());

            return AIResponse::error('Langdock Anthropic error: ' . $e->getMessage());
        }
    }

    private function parseResponse(array $data): AIResponse
    {
        $usage = $data['usage'] ?? [];
        $inputTokens = $usage['input_tokens'] ?? 0;
        $outputTokens = $usage['output_tokens'] ?? 0;

        $textParts = [];
        $toolCalls = [];

        foreach ($data['content'] ?? [] as $block) {
            if ($block['type'] === 'text') {
                $textParts[] = $block['text'];
            }
            if ($block['type'] === 'tool_use') {
                $toolCalls[] = [
                    'id' => $block['id'],
                    'name' => $block['name'],
                    'arguments' => $block['input'] ?? [],
                ];
            }
        }

        $text = implode("\n\n", $textParts) ?: null;
        $type = !empty($toolCalls) ? 'tool_call' : 'text';
        Logger::info("Langdock Anthropic response: type={$type}, inputTokens={$inputTokens}, outputTokens={$outputTokens}");

        if (!empty($toolCalls)) {
            return AIResponse::toolCall($toolCalls, $text, $inputTokens, $outputTokens);
        }

        return AIResponse::text($text ?? '', $inputTokens, $outputTokens);
    }

    private function sendStreamRequest(string $apiKey, array $payload, callable $onChunk): void
    {
        /** @var array<string, array{id: string, name: string, input: string}> $toolCalls */
        $toolCalls = [];
        $currentBlockId = null;
        $hasText = false;

        try {
            StreamHelper::stream(
                HttpClientFactory::create(),
                $this->getEndpointUrl(),
                [
                    'Authorization' => "Bearer {$apiKey}",
                    'anthropic-version' => self::API_VERSION,
                ],
                $payload,
                function(string $event, array $json) use (&$toolCalls, &$currentBlockId, &$hasText, $onChunk): void {
                    switch ($event) {
                        case 'content_block_start':
                            $block = $json['content_block'] ?? [];
                            if (($block['type'] ?? '') === 'tool_use') {
                                $currentBlockId = $block['id'] ?? '';
                                $toolCalls[$currentBlockId] = ['id' => $currentBlockId, 'name' => $block['name'] ?? '', 'input' => ''];
                            } elseif (($block['type'] ?? '') === 'text' && $hasText) {
                                $onChunk(new StreamChunk('text_delta', delta: "\n\n"));
                            }
                            break;

                        case 'content_block_delta':
                            $delta = $json['delta'] ?? [];
                            $deltaType = $delta['type'] ?? '';
                            if ($deltaType === 'text_delta') {
                                $hasText = true;
                                $onChunk(new StreamChunk('text_delta', delta: $delta['text'] ?? ''));
                            } elseif ($deltaType === 'thinking_delta') {
                                $onChunk(new StreamChunk('thinking', delta: $delta['thinking'] ?? ''));
                            } elseif ($deltaType === 'input_json_delta' && $currentBlockId && isset($toolCalls[$currentBlockId])) {
                                $toolCalls[$currentBlockId]['input'] .= $delta['partial_json'] ?? '';
                            }
                            break;

                        case 'content_block_stop':
                            $currentBlockId = null;
                            break;

                        case 'message_delta':
                            $usage = $json['usage'] ?? [];
                            if (!empty($usage)) {
                                $onChunk(new StreamChunk('usage', outputTokens: $usage['output_tokens'] ?? 0));
                            }
                            break;

                        case 'message_start':
                            $usage = $json['message']['usage'] ?? [];
                            if (!empty($usage)) {
                                $onChunk(new StreamChunk('usage', inputTokens: $usage['input_tokens'] ?? 0));
                            }
                            break;
                    }
                },
            );

            foreach ($toolCalls as $tc) {
                $onChunk(new StreamChunk('tool_call', toolCallId: $tc['id'], toolName: $tc['name'], toolArguments: json_decode($tc['input'], true) ?? []));
            }
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $errorMsg = 'Langdock Anthropic stream error: ' . $e->getMessage();
            $response = $e->getResponse();
            if ($response !== null) {
                $errorMsg .= ' | Response: ' . mb_substr((string) $response->getBody(), 0, 500);
            }
            Logger::error($errorMsg);
            $onChunk(new StreamChunk('error', error: $errorMsg));
        } catch (\Throwable $e) {
            Logger::error('Langdock Anthropic stream error: ' . $e->getMessage());
            $onChunk(new StreamChunk('error', error: 'Langdock Anthropic stream error: ' . $e->getMessage()));
        }
    }
}
