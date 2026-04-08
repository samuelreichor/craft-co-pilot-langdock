<div align="center">
	<a href="https://packagist.org/packages/samuelreichor/craft-co-pilot-langdock" align="center">
      <img src="https://online-images-sr.netlify.app/assets/craft-co-pilot-langdock.png" width="100" alt="Craft CoPilot">
	</a>
  <br>
	<h1 align="center">Langdock Provider for CoPilot</h1>
    <p align="center">
        Route all CoPilot AI requests through Langdock's unified API for DSGVO-compliant usage of LLMs. Replaces the default OpenAI, Anthropic, and Google Gemini providers with Langdock-backed variants.
    </p>
</div>

<p align="center">
  <a href="https://packagist.org/packages/samuelreichor/craft-co-pilot-langdock">
    <img src="https://img.shields.io/packagist/v/samuelreichor/craft-co-pilot-langdock?label=version&color=blue">
  </a>
  <a href="https://packagist.org/packages/samuelreichor/craft-co-pilot-langdock">
    <img src="https://img.shields.io/packagist/dt/samuelreichor/craft-co-pilot-langdock?color=blue">
  </a>
  <a href="https://packagist.org/packages/samuelreichor/craft-co-pilot-langdock">
    <img src="https://img.shields.io/packagist/php-v/samuelreichor/craft-co-pilot-langdock?color=blue">
  </a>
  <a href="https://packagist.org/packages/samuelreichor/craft-co-pilot-langdock">
    <img src="https://img.shields.io/packagist/l/samuelreichor/craft-co-pilot-langdock?color=blue">
  </a>
</p>

## Features

- **Single API Key**: One Langdock API key covers OpenAI, Anthropic, and Google Gemini
- **Data Residency**: Choose between EU and US regions for API routing
- **Streaming & Tool Calling**: Full support for streaming responses and function/tool calling across all providers
- **Dynamic Model Discovery**: Available models are fetched from your Langdock workspace and cached automatically

## Requirements

- Craft CMS 5.0+
- PHP 8.2+
- [CoPilot](https://github.com/samuelreichor/craft-co-pilot) installed
- API key for [Langdock](https://langdock.com)

## Setup

Install the plugin, then navigate to the CoPilot provider settings in your Craft control panel. Enter your API key environment variable, and choose your preferred region and models.

## Documentation

Visit the [Craft CoPilot documentation](https://samuelreichor.at/libraries/craft-co-pilot) for all documentation, guides and developer resources.

## Support

If you encounter bugs or have feature requests, [please submit an issue](/../../issues/new). Your feedback helps improve the plugin!
