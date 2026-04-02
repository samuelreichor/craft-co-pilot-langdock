<?php

namespace samuelreichor\coPilotLangdock;

use craft\base\Plugin;
use craft\events\RegisterCacheOptionsEvent;
use craft\utilities\ClearCaches;
use samuelreichor\coPilot\events\RegisterProvidersEvent;
use samuelreichor\coPilot\services\ProviderService;
use samuelreichor\coPilotLangdock\providers\LangdockAnthropicProvider;
use samuelreichor\coPilotLangdock\providers\LangdockConfig;
use samuelreichor\coPilotLangdock\providers\LangdockGeminiProvider;
use samuelreichor\coPilotLangdock\providers\LangdockOpenAIProvider;
use yii\base\Event;

/**
 * coPilot Langdock plugin
 *
 * Replaces the default CoPilot providers with Langdock-backed variants.
 * All providers share a single Langdock API key and route to the
 * correct provider-specific Langdock endpoint.
 *
 * @method static CoPilotLangdock getInstance()
 * @author Samuel Reichör <samuelreichor@gmail.com>
 * @copyright Samuel Reichör
 * @license MIT
 */
class CoPilotLangdock extends Plugin
{
    public string $schemaVersion = '1.0.0';

    public function init(): void
    {
        parent::init();

        Event::on(
            ProviderService::class,
            ProviderService::EVENT_REGISTER_PROVIDERS,
            function(RegisterProvidersEvent $event) {
                // Replace default providers with Langdock-backed variants
                $event->providers = [
                    'openai' => new LangdockOpenAIProvider(),
                    'anthropic' => new LangdockAnthropicProvider(),
                    'gemini' => new LangdockGeminiProvider(),
                ];
            }
        );

        Event::on(
            ClearCaches::class,
            ClearCaches::EVENT_REGISTER_CACHE_OPTIONS,
            function(RegisterCacheOptionsEvent $event): void {
                $event->options[] = [
                    'key' => 'copilot-langdock-models',
                    'label' => 'CoPilot model caches',
                    'action' => [LangdockConfig::class, 'invalidateModelCache'],
                ];
            },
        );
    }
}
