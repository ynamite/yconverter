<?php

/**
 * This file is part of the YConverter package.
 *
 * @author (c) Yakamara Media GmbH & Co. KG
 */

namespace YConverter\Schema\Ai;

use YConverter\Config;

class AiProviderFactory
{
    public static function fromConfig(Config $config): ?AiFieldProvider
    {
        $provider = $config->getAiProvider();
        $key = $config->getAiApiKey();
        if ('' === $key || 'none' === $provider) {
            return null;
        }
        if ('openai' === $provider) {
            return new OpenAiProvider($key, $config->getAiModel());
        }
        if ('anthropic' === $provider) {
            return new AnthropicProvider($key, $config->getAiModel());
        }
        return null;
    }
}
