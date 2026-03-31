<?php

namespace samuelreichor\coPilotLangdock\providers;

use Craft;
use samuelreichor\coPilot\helpers\HttpClientFactory;
use samuelreichor\coPilot\helpers\Logger;
use samuelreichor\coPilot\helpers\StreamHelper;
use samuelreichor\coPilot\models\AIResponse;
use samuelreichor\coPilot\models\StreamChunk;
use samuelreichor\coPilot\providers\ProviderInterface;

class LangdockOpenAIProvider implements ProviderInterface
{
    private string $model = 'gpt-4o';

    public function getHandle(): string
    {
        return 'openai';
    }

    public function getName(): string
    {
        return 'OpenAI';
    }

    public function getIcon(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M22.282 9.821a5.985 5.985 0 0 0-.516-4.91 6.046 6.046 0 0 0-6.51-2.9A6.065 6.065 0 0 0 4.981 4.18a5.985 5.985 0 0 0-3.998 2.9 6.046 6.046 0 0 0 .743 7.097 5.98 5.98 0 0 0 .51 4.911 6.051 6.051 0 0 0 6.515 2.9A5.985 5.985 0 0 0 13.26 24a6.056 6.056 0 0 0 5.772-4.206 5.99 5.99 0 0 0 3.997-2.9 6.056 6.056 0 0 0-.747-7.073zM13.26 22.43a4.476 4.476 0 0 1-2.876-1.04l.141-.081 4.779-2.758a.795.795 0 0 0 .392-.681v-6.737l2.02 1.168a.071.071 0 0 1 .038.052v5.583a4.504 4.504 0 0 1-4.494 4.494zM3.6 18.304a4.47 4.47 0 0 1-.535-3.014l.142.085 4.783 2.759a.771.771 0 0 0 .78 0l5.843-3.369v2.332a.08.08 0 0 1-.033.062L9.74 19.95a4.5 4.5 0 0 1-6.14-1.646zM2.34 7.896a4.485 4.485 0 0 1 2.366-1.973V11.6a.766.766 0 0 0 .388.676l5.815 3.355-2.02 1.168a.076.076 0 0 1-.071 0l-4.83-2.786A4.504 4.504 0 0 1 2.34 7.872zm16.597 3.855l-5.833-3.387L15.119 7.2a.076.076 0 0 1 .071 0l4.83 2.791a4.494 4.494 0 0 1-.676 8.105v-5.678a.79.79 0 0 0-.407-.667zm2.01-3.023l-.141-.085-4.774-2.782a.776.776 0 0 0-.785 0L9.409 9.23V6.897a.066.066 0 0 1 .028-.061l4.83-2.787a4.5 4.5 0 0 1 6.68 4.66zm-12.64 4.135l-2.02-1.164a.08.08 0 0 1-.038-.057V6.075a4.5 4.5 0 0 1 7.375-3.453l-.142.08L8.704 5.46a.795.795 0 0 0-.393.681zm1.097-2.365l2.602-1.5 2.607 1.5v2.999l-2.597 1.5-2.607-1.5z"/></svg>';
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
            ['gpt-', 'o1', 'o3', 'o4'],
        );
    }

    public function getTitleModel(): string
    {
        $models = $this->getAvailableModels();
        $preferred = ['gpt-4o-mini', 'gpt-4.1-mini', 'gpt-5-nano'];
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
        return ['model' => 'gpt-4o'];
    }

    public function setConfig(array $config): void
    {
        $this->model = $config['model'] ?? 'gpt-4o';
    }

    public function getSettingsHtml(array $config, array $fileConfig): string
    {
        $plugin = \samuelreichor\coPilot\CoPilot::getInstance();
        $providers = $plugin->providerService->getProviders();

        $providerModels = [];
        foreach ($providers as $handle => $provider) {
            $providerModels[] = [
                'handle' => $handle,
                'label' => $provider->getName(),
                'models' => $provider->getAvailableModels(),
                'config' => $plugin->getSettings()->getProviderConfig($handle),
            ];
        }

        return LangdockConfig::getSettingsHtml($providerModels);
    }

    public function formatTools(array $tools): array
    {
        return array_map(fn(array $tool) => [
            'type' => 'function',
            'function' => [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'parameters' => $tool['parameters'],
            ],
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
            'messages' => $this->formatMessages($systemPrompt, $messages),
        ];

        $formattedTools = $this->formatTools($tools);
        if (!empty($formattedTools)) {
            $payload['tools'] = $formattedTools;
        }

        Logger::info("Langdock OpenAI request: model={$model}");

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
            'messages' => $this->formatMessages($systemPrompt, $messages),
            'stream' => true,
        ];

        $formattedTools = $this->formatTools($tools);
        if (!empty($formattedTools)) {
            $payload['tools'] = $formattedTools;
        }

        Logger::info("Langdock OpenAI stream request: model={$model}");

        $this->sendStreamRequest($apiKey, $payload, $onChunk);
    }

    private function getBaseUrl(): string
    {
        return 'https://api.langdock.com/openai/' . LangdockConfig::getRegion() . '/v1';
    }

    private function getEndpointUrl(): string
    {
        return $this->getBaseUrl() . '/chat/completions';
    }



    /**
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    private function formatMessages(string $systemPrompt, array $messages): array
    {
        $formatted = [['role' => 'system', 'content' => $systemPrompt]];

        foreach ($messages as $message) {
            $role = $message['role'];

            if ($role === 'tool') {
                $content = is_array($message['content'])
                    ? json_encode($message['content'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    : $message['content'];
                $formatted[] = [
                    'role' => 'tool',
                    'tool_call_id' => $message['toolCallId'],
                    'content' => (string) $content,
                ];
                continue;
            }

            if ($role === 'assistant' && !empty($message['toolCalls'])) {
                $formatted[] = [
                    'role' => 'assistant',
                    'content' => $message['content'] ?? null,
                    'tool_calls' => array_map(fn(array $tc) => [
                        'id' => $tc['id'],
                        'type' => 'function',
                        'function' => [
                            'name' => $tc['name'],
                            'arguments' => json_encode($tc['arguments'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        ],
                    ], $message['toolCalls']),
                ];
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
                'headers' => ['Authorization' => "Bearer {$apiKey}", 'Content-Type' => 'application/json'],
                'json' => $payload,
                'timeout' => 120,
            ]);
            $data = json_decode($response->getBody()->getContents(), true);

            return $this->parseResponse($data);
        } catch (\Throwable $e) {
            Logger::error('Langdock OpenAI error: ' . $e->getMessage());

            return AIResponse::error('Langdock OpenAI error: ' . $e->getMessage());
        }
    }

    private function parseResponse(array $data): AIResponse
    {
        $inputTokens = $data['usage']['prompt_tokens'] ?? 0;
        $outputTokens = $data['usage']['completion_tokens'] ?? 0;
        $choice = $data['choices'][0] ?? null;
        if (!$choice) {
            return AIResponse::error('No response from Langdock.', $inputTokens, $outputTokens);
        }

        $msg = $choice['message'] ?? [];
        $text = $msg['content'] ?? null;
        $toolCalls = [];
        foreach ($msg['tool_calls'] ?? [] as $tc) {
            $toolCalls[] = [
                'id' => $tc['id'],
                'name' => $tc['function']['name'],
                'arguments' => json_decode($tc['function']['arguments'], true) ?? [],
            ];
        }

        $type = !empty($toolCalls) ? 'tool_call' : 'text';
        Logger::info("Langdock OpenAI response: type={$type}, inputTokens={$inputTokens}, outputTokens={$outputTokens}");

        if (!empty($toolCalls)) {
            return AIResponse::toolCall($toolCalls, $text, $inputTokens, $outputTokens);
        }

        return AIResponse::text($text ?? '', $inputTokens, $outputTokens);
    }

    private function sendStreamRequest(string $apiKey, array $payload, callable $onChunk): void
    {
        /** @var array<int, array{id: string, name: string, arguments: string}> $toolCalls */
        $toolCalls = [];
        $hasText = false;

        try {
            StreamHelper::stream(
                HttpClientFactory::create(),
                $this->getEndpointUrl(),
                ['Authorization' => "Bearer {$apiKey}"],
                $payload,
                function(string $eventType, array $json) use (&$toolCalls, &$hasText, $onChunk): void {
                    $choice = $json['choices'][0] ?? null;
                    if (!$choice) {
                        if (isset($json['usage'])) {
                            $onChunk(new StreamChunk('usage', inputTokens: $json['usage']['prompt_tokens'] ?? 0, outputTokens: $json['usage']['completion_tokens'] ?? 0));
                        }
                        return;
                    }

                    $delta = $choice['delta'] ?? [];
                    $content = $delta['content'] ?? null;
                    if ($content !== null && $content !== '') {
                        $hasText = true;
                        $onChunk(new StreamChunk('text_delta', delta: $content));
                    }

                    foreach ($delta['tool_calls'] ?? [] as $tc) {
                        $index = $tc['index'] ?? 0;
                        if (isset($tc['id'])) {
                            $toolCalls[$index] = ['id' => $tc['id'], 'name' => $tc['function']['name'] ?? '', 'arguments' => $tc['function']['arguments'] ?? ''];
                        } elseif (isset($toolCalls[$index])) {
                            $toolCalls[$index]['arguments'] .= $tc['function']['arguments'] ?? '';
                        }
                    }

                    if (isset($json['usage'])) {
                        $onChunk(new StreamChunk('usage', inputTokens: $json['usage']['prompt_tokens'] ?? 0, outputTokens: $json['usage']['completion_tokens'] ?? 0));
                    }
                },
            );

            foreach ($toolCalls as $tc) {
                $onChunk(new StreamChunk('tool_call', toolCallId: $tc['id'], toolName: $tc['name'], toolArguments: json_decode($tc['arguments'], true) ?? []));
            }
        } catch (\Throwable $e) {
            Logger::error('Langdock OpenAI stream error: ' . $e->getMessage());
            $onChunk(new StreamChunk('error', error: 'Langdock OpenAI stream error: ' . $e->getMessage()));
        }
    }
}
