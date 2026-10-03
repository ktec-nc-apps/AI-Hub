# Changelog

All notable changes to AI-Hub are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [0.0.1] — 2026-10-03

First shape, for the App Store registration. Not yet meant for use.

### Added
- Engines: Anthropic Claude and Google Gemini through their APIs, any OpenAI-compatible
  endpoint, and a Claude Code or Gemini CLI subscription installed on the server.
- Scenarios: an app registers a system prompt, tools (a JSON Schema and a PHP callback
  each) and the shape of the answer; the hub runs the tool loop and checks the answer.
- Asking from PHP (`HubService::ask` / `result`) and over OCS
  (`/ocs/v2.php/apps/ai_hub/api/v1/ask`, `…/result/{id}`, `…/status`).
- Providers for Nextcloud's Task Processing API: text to text, and chat.
- Administration: engine, keys, model list with a connection test, who and which apps
  may ask, questions per minute and answers in progress at once.
