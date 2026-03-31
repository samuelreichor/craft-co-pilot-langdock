<?php

namespace samuelreichor\coPilotLangdock\providers;

use samuelreichor\coPilot\helpers\HttpClientFactory;
use samuelreichor\coPilot\helpers\Logger;
use samuelreichor\coPilot\helpers\StreamHelper;
use samuelreichor\coPilot\models\AIResponse;
use samuelreichor\coPilot\models\StreamChunk;
use samuelreichor\coPilot\providers\ProviderInterface;

class LangdockGeminiProvider implements ProviderInterface
{
    private string $model = 'gemini-2.5-flash';

    public function getHandle(): string
    {
        return 'gemini';
    }

    public function getName(): string
    {
        return 'Google Gemini';
    }

    public function getIcon(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C12 6.627 6.627 12 0 12c6.627 0 12 5.373 12 12 0-6.627 5.373-12 12-12-6.627 0-12-5.373-12-12z"/></svg>';
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
            ['gemini-'],
        );
    }

    public function getTitleModel(): string
    {
        $models = $this->getAvailableModels();
        $preferred = ['gemini-2.5-flash', 'gemini-2.0-flash'];
        foreach ($preferred as $candidate) {
            if (in_array($candidate, $models, true)) {
                return $candidate;
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
        return ['model' => 'gemini-2.5-flash'];
    }

    public function setConfig(array $config): void
    {
        $this->model = $config['model'] ?? 'gemini-2.5-flash';
    }

    public function getSettingsHtml(array $config, array $fileConfig): string
    {
        // Rendered by LangdockOpenAIProvider in the shared settings block
        return '';
    }

    public function formatTools(array $tools): array
    {
        if (empty($tools)) {
            return [];
        }

        return [
            [
                'functionDeclarations' => array_map(function(array $tool) {
                    $declaration = [
                        'name' => $tool['name'],
                        'description' => $tool['description'],
                    ];
                    if ($this->hasProperties($tool['parameters'])) {
                        $declaration['parameters'] = $tool['parameters'];
                    }

                    return $declaration;
                }, $tools),
            ],
        ];
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
        $payload = $this->buildPayload($systemPrompt, $messages, $tools);

        Logger::info("Langdock Gemini request: model={$model}");

        return $this->sendRequest($apiKey, $model, $payload);
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
        $payload = $this->buildPayload($systemPrompt, $messages, $tools);

        Logger::info("Langdock Gemini stream request: model={$model}");

        $this->sendStreamRequest($apiKey, $model, $payload, $onChunk);
    }

    private function getEndpointBase(): string
    {
        return 'https://api.langdock.com/google/' . LangdockConfig::getRegion() . '/v1beta/models/';
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(string $systemPrompt, array $messages, array $tools): array
    {
        $payload = [
            'systemInstruction' => $systemPrompt,
            'contents' => $this->formatMessages($messages),
        ];

        $formattedTools = $this->formatTools($tools);
        if (!empty($formattedTools)) {
            $payload['tools'] = $formattedTools;
        }

        return $payload;
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
                $responseContent = is_array($message['content']) && $message['content'] !== []
                    ? $message['content']
                    : ['result' => $message['content']];
                $formatted[] = [
                    'role' => 'user',
                    'parts' => [['functionResponse' => ['name' => $message['toolName'] ?? 'unknown', 'response' => (object) $responseContent]]],
                ];
                continue;
            }

            if ($role === 'assistant' && !empty($message['toolCalls'])) {
                $parts = [];
                if (!empty($message['content'])) {
                    $parts[] = ['text' => $message['content']];
                }
                foreach ($message['toolCalls'] as $tc) {
                    $parts[] = ['functionCall' => ['name' => $tc['name'], 'args' => (object) ($tc['arguments'] ?: [])]];
                }
                $formatted[] = ['role' => 'model', 'parts' => $parts];
                continue;
            }

            $geminiRole = $role === 'assistant' ? 'model' : 'user';
            $formatted[] = [
                'role' => $geminiRole,
                'parts' => [['text' => is_array($message['content']) ? json_encode($message['content'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $message['content']]],
            ];
        }

        return $formatted;
    }

    private function sendRequest(string $apiKey, string $model, array $payload): AIResponse
    {
        $client = HttpClientFactory::create();
        $url = $this->getEndpointBase() . $model . ':generateContent';

        try {
            $response = $client->post($url, [
                'headers' => ['Authorization' => "Bearer {$apiKey}", 'Content-Type' => 'application/json'],
                'json' => $payload,
                'timeout' => 120,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return $this->parseResponse($data);
        } catch (\Throwable $e) {
            Logger::error('Langdock Gemini error: ' . $e->getMessage());

            return AIResponse::error('Langdock Gemini error: ' . $e->getMessage());
        }
    }

    private function parseResponse(array $data): AIResponse
    {
        $inputTokens = $data['usageMetadata']['promptTokenCount'] ?? 0;
        $outputTokens = $data['usageMetadata']['candidatesTokenCount'] ?? 0;

        $candidate = $data['candidates'][0] ?? null;
        if (!$candidate) {
            return AIResponse::error('No response from Langdock Gemini.', $inputTokens, $outputTokens);
        }

        $parts = $candidate['content']['parts'] ?? [];
        $textParts = [];
        $toolCalls = [];

        foreach ($parts as $part) {
            if (isset($part['text'])) {
                $textParts[] = $part['text'];
            }
            if (isset($part['functionCall'])) {
                $toolCalls[] = [
                    'id' => 'gemini_' . uniqid(),
                    'name' => $part['functionCall']['name'],
                    'arguments' => $part['functionCall']['args'] ?? [],
                ];
            }
        }

        $text = implode("\n", $textParts) ?: null;
        $type = !empty($toolCalls) ? 'tool_call' : 'text';
        Logger::info("Langdock Gemini response: type={$type}, inputTokens={$inputTokens}, outputTokens={$outputTokens}");

        if (!empty($toolCalls)) {
            return AIResponse::toolCall($toolCalls, $text, $inputTokens, $outputTokens, $parts);
        }

        return AIResponse::text($text ?? '', $inputTokens, $outputTokens);
    }

    private function sendStreamRequest(string $apiKey, string $model, array $payload, callable $onChunk): void
    {
        $url = $this->getEndpointBase() . $model . ':streamGenerateContent?alt=sse';
        $hasToolCalls = false;
        /** @var array<int, array<string, mixed>> $rawModelParts */
        $rawModelParts = [];

        try {
            StreamHelper::stream(
                HttpClientFactory::create(),
                $url,
                ['Authorization' => "Bearer {$apiKey}"],
                $payload,
                function(string $eventType, array $json) use (&$hasToolCalls, &$rawModelParts, $onChunk): void {
                    if (isset($json['usageMetadata'])) {
                        $onChunk(new StreamChunk('usage', inputTokens: $json['usageMetadata']['promptTokenCount'] ?? 0, outputTokens: $json['usageMetadata']['candidatesTokenCount'] ?? 0));
                    }

                    $parts = $json['candidates'][0]['content']['parts'] ?? [];
                    foreach ($parts as $part) {
                        if (isset($part['functionCall'])) {
                            $hasToolCalls = true;
                        }
                        $rawModelParts[] = $part;

                        if (isset($part['text'])) {
                            $type = !empty($part['thought']) ? 'thinking' : 'text_delta';
                            $onChunk(new StreamChunk($type, delta: $part['text']));
                        }
                        if (isset($part['functionCall'])) {
                            $onChunk(new StreamChunk('tool_call', toolCallId: 'gemini_' . uniqid(), toolName: $part['functionCall']['name'], toolArguments: $part['functionCall']['args'] ?? []));
                        }
                    }
                },
            );

            if ($hasToolCalls && !empty($rawModelParts)) {
                $onChunk(new StreamChunk('model_parts', rawModelParts: $rawModelParts));
            }
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $errorMsg = 'Langdock Gemini stream error: ' . $e->getMessage();
            $response = $e->getResponse();
            if ($response !== null) {
                $errorMsg .= ' | Response: ' . mb_substr((string) $response->getBody(), 0, 500);
            }
            Logger::error($errorMsg);
            $onChunk(new StreamChunk('error', error: $errorMsg));
        } catch (\Throwable $e) {
            Logger::error('Langdock Gemini stream error: ' . $e->getMessage());
            $onChunk(new StreamChunk('error', error: 'Langdock Gemini stream error: ' . $e->getMessage()));
        }
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function hasProperties(array $parameters): bool
    {
        $properties = $parameters['properties'] ?? null;
        if ($properties instanceof \stdClass) {
            return (array) $properties !== [];
        }

        return is_array($properties) && $properties !== [];
    }
}
