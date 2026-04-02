<?php

namespace samuelreichor\coPilotLangdock\providers;

use Craft;
use craft\helpers\App;
use samuelreichor\coPilot\CoPilot;
use samuelreichor\coPilot\helpers\Logger;

/**
 * Shared configuration for all Langdock providers.
 * Reads API key and region from CoPilot's providerSettings['_langdock'].
 */
final class LangdockConfig
{
    private const AGENT_MODELS_URL = 'https://api.langdock.com/agent/v1/models';
    private const MODELS_CACHE_KEY = 'coPilotLangdock.models';
    private const MODELS_CACHE_DURATION = 86400;
    private const CONFIG_KEY = '_langdock';

    public static function getApiKey(): ?string
    {
        $config = self::getConfig();
        $key = App::parseEnv($config['apiKeyEnvVar'] ?? '');

        return $key ?: null;
    }

    public static function getRegion(): string
    {
        return self::getConfig()['region'] ?? 'eu';
    }

    public static function getApiKeyEnvVar(): string
    {
        return self::getConfig()['apiKeyEnvVar'] ?? '';
    }

    /**
     * Returns the stored config from CoPilot settings.
     *
     * @return array<string, mixed>
     */
    public static function getConfig(): array
    {
        try {
            return CoPilot::getInstance()->getSettings()->getProviderConfig(self::CONFIG_KEY);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function getDefaultConfig(): array
    {
        return [
            'apiKeyEnvVar' => '',
            'region' => 'eu',
        ];
    }

    /**
     * Renders the shared Langdock settings HTML (API key, region, all model selects).
     *
     * @param array<int, array{handle: string, label: string, models: array<int, string>, config: array<string, mixed>}> $providerModels
     */
    public static function getSettingsHtml(array $providerModels = []): string
    {
        $config = self::getConfig();
        $fileConfig = self::getFileConfig();

        return Craft::$app->getView()->renderTemplate('co-pilot-langdock/_shared-settings', [
            'config' => $config,
            'fileConfig' => $fileConfig,
            'providerModels' => $providerModels,
            'hasApiKey' => self::getApiKey() !== null,
        ]);
    }

    public static function invalidateModelCache(): void
    {
        Craft::$app->getCache()->delete(self::MODELS_CACHE_KEY);
    }

    /**
     * Fetches all available model IDs from the Langdock workspace (Agent API).
     *
     * @return array<int, string>
     */
    public static function fetchAllModels(): array
    {
        $cache = Craft::$app->getCache();
        $cached = $cache->get(self::MODELS_CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        $models = self::fetchModels(self::AGENT_MODELS_URL);
        if (!empty($models)) {
            $cache->set(self::MODELS_CACHE_KEY, $models, self::MODELS_CACHE_DURATION);
        }

        return $models;
    }

    /**
     * Filters models by prefix and normalizes IDs (@ → -).
     *
     * @param array<int, string> $models
     * @param array<int, string> $prefixes
     * @return array<int, string>
     */
    public static function filterAndNormalizeModels(array $models, array $prefixes): array
    {
        $result = [];
        foreach ($models as $model) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($model, $prefix)) {
                    $result[] = self::normalizeModelId($model);
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Fetches available model IDs from a Langdock models endpoint.
     *
     * @return array<int, string>
     */
    public static function fetchModels(string $modelsUrl): array
    {
        $apiKey = self::getApiKey();
        if (!$apiKey) {
            return [];
        }

        try {
            $client = Craft::createGuzzleClient();
            $response = $client->get($modelsUrl, [
                'headers' => [
                    'Authorization' => "Bearer {$apiKey}",
                ],
                'timeout' => 10,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return self::parseModelsResponse($data);
        } catch (\Throwable $e) {
            Logger::warning('Langdock: failed to fetch models from ' . $modelsUrl . ': ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Normalizes a model ID from the Agent API format.
     * Converts @ to - (e.g. claude-opus-4-6@default → claude-opus-4-6-default).
     */
    public static function normalizeModelId(string $model): string
    {
        return str_replace('@', '-', $model);
    }

    /**
     * Parses model IDs from either OpenAI-style or Google-style responses.
     *
     * OpenAI/Anthropic: {"data": [{"id": "model-name"}]}
     * Google: {"models": [{"name": "models/gemini-2.5-flash"}]}
     *
     * @param array<string, mixed> $data
     * @return array<int, string>
     */
    private static function parseModelsResponse(array $data): array
    {
        $models = [];

        // OpenAI/Anthropic format
        foreach ($data['data'] ?? [] as $model) {
            if (isset($model['id'])) {
                $models[] = $model['id'];
            }
        }

        // Google format — strip "models/" prefix
        foreach ($data['models'] ?? [] as $model) {
            if (isset($model['name'])) {
                $name = $model['name'];
                if (str_starts_with($name, 'models/')) {
                    $name = substr($name, 7);
                }
                $models[] = $name;
            }
        }

        sort($models);

        return $models;
    }

    /**
     * @return array<string, mixed>
     */
    private static function getFileConfig(): array
    {
        try {
            $fileConfig = Craft::$app->getConfig()->getConfigFromFile('co-pilot');

            return $fileConfig['providerSettings'][self::CONFIG_KEY] ?? [];
        } catch (\Throwable) {
            return [];
        }
    }
}
