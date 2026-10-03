# AI-Hub 🔌

**One AI gateway for every app in your Nextcloud: an app hands AI-Hub a scenario and a question, and gets back an answer it can act on.**

> A personal project, written for my own apps and shared in case it is useful to someone.
> Self-hosted; the key, the model and every question stay under your own control.

AI-Hub is open to any Nextcloud app, by anyone — not only the maker's own. If you write a Nextcloud app, you can connect it to AI-Hub using this README alone, with no change to AI-Hub itself.

## Contents

- [What it is](#what-it-is)
- [Engines](#engines)
- [Connecting to AI-Hub](#connecting-to-ai-hub)
  - [From an app on the same server (PHP)](#from-an-app-on-the-same-server-php)
  - [Over HTTP (OCS REST API)](#over-http-ocs-rest-api)
  - [Through the Task Processing API](#through-the-task-processing-api)
- [Administration](#administration)
- [Safety](#safety)
- [The apps that connect](#the-apps-that-connect)
- [Requirements](#requirements)
- [Installation](#installation)
- [Status](#status)
- [Licence](#licence)

## What it is

Every app that wants to use an AI model has to solve the same things: where the key is kept, which model answers, how long to wait, who may ask, how many times, what the model is allowed to do, and how to turn its words into something the app can act on. AI-Hub solves them once, for all the apps on the server.

An app does not talk to a model. It registers a **scenario** — what the assistant is, what it may do, and what shape its answers take — and then sends the user's words. AI-Hub chooses the engine, runs the conversation, runs the tools the scenario allows, checks the answer against the shape, and hands it back. The same scenario works whatever the administrator has connected: Claude, Gemini, an OpenAI-compatible service, or a command-line subscription on the server.

AI-Hub is also a **provider for Nextcloud's own Task Processing API**, so Nextcloud Assistant, Talk, Mail and any app written against the standard API can use the connected models without knowing AI-Hub exists.

## Engines

- **Anthropic Claude** and **Google Gemini** through their own APIs, with tool use and web search where the provider offers them.
- **Any OpenAI-compatible endpoint** — OpenRouter, DeepSeek, Mistral, Groq, OpenAI itself, or a server of your own running Ollama, vLLM or LM Studio.
- **A command-line subscription**: where Claude Code or the Gemini CLI is installed on the server, AI-Hub drives it, so a flat-rate plan answers everyone with no per-token bill. Off by default; tools are given to it only as the scenario says.

The engines are the ones Talk-Bot has run since 2026; they move here, and Talk-Bot becomes a client.

## Connecting to AI-Hub

There are three ways in, and you choose by where your code runs:

- **[From an app on the same server (PHP)](#from-an-app-on-the-same-server-php)** — register a scenario, `ask()` for a ticket, `result()` when the answer is ready. Long answers are worked out in a request of their own, so a page never waits for a model.
- **[Over HTTP (OCS REST API)](#over-http-ocs-rest-api)** — the same `ask` / `result` / `status`, for the browser side of an app or for an outside script.
- **[Through the Task Processing API](#through-the-task-processing-api)** — Nextcloud's standard API; nothing AI-Hub-specific to call.

None of this needs a change to AI-Hub. A scenario is named under your own app id, reaches only the tools you register, and is yours alone.

### From an app on the same server (PHP)

The service is `\OCA\AIHub\Service\HubService`, fetched with `\OCP\Server::get(...)`. Guard every use with `class_exists('\\OCA\\AIHub\\Service\\HubService')` so your app keeps working when AI-Hub is not installed.

#### `registerScenario(string $app, string $name, array $spec): void`

Call it from your app's `Application::boot()` **on every request**. Scenarios are held in memory only, and the request that computes an answer is a different one from the request that asked — so a scenario registered on one request would not exist on the next unless you register it every time.

The `$spec` array:

- **`system`** => `string` — the system prompt: who the assistant is and how it answers.
- **`tools`** => `array` — a map from a tool name to `['description' => string, 'input' => <JSON Schema array>, 'run' => callable(string $userId, array $args): string|array]`. AI-Hub runs the tool loop for you (up to **8 tool calls per question**): the model asks for a tool, your `run` callback executes with the asking user's rights, and it returns a string or an array (arrays are JSON-encoded back to the model). Omit `tools` for a plain chat assistant.
- **`answer`** => a JSON Schema `array` the final answer must satisfy, or omit / `null` for free text. An answer that does not fit the schema is sent back to the model once, then the question fails rather than return a guess.
- **`search`** => `bool` — allow web search, performed on the AI provider's side (it reaches the internet, never the server's own network).
- **`language`** => a language code `string` (answer in it), or `null` (follow the user's language), or `false` (your own system prompt decides).

Names are validated:

| Name | Pattern | Notes |
| --- | --- | --- |
| app id | `^[a-z][a-z0-9_]{1,63}$` | |
| scenario name | `^[a-z][a-z0-9_-]{0,63}$` | case-insensitive |
| tool name | `^[a-z][a-z0-9_]{0,63}$` | case-insensitive |

#### `ask(string $userId, string $app, string $scenario, array $messages, array $options = []): array`

`$messages` is a list of `['role' => 'user'|'assistant', 'text' => string]`, oldest first, and the last one must be `'user'` (at most the last **40 turns** are kept).

`$options`:

- **`context`** => `string` — appended to the system prompt for this one question only (your live state: the open document, the records in view…), capped at **200,000 characters**.
- **`search`** => `bool` — overrides the scenario default for this question.

Returns `['id' => string]` (a ticket), or `['error' => <code>]`.

#### `result(string $userId, string $id): array`

Returns `['state' => 'running'|'done'|'error'|'unknown', 'text' => string?, 'answer' => mixed?, 'tools' => string[]?, 'error' => string?]`. A `done` result is handed over once and then forgotten. Results are kept at most **30 minutes**.

#### `askNow(string $userId, string $app, string $scenario, array $messages, array $options = []): array`

Same inputs as `ask`, but it answers **inside the current request** and returns the same shape as `result()` (`state` `done` / `error`, with `text` / `answer` / `tools`). Use it from a background job, an `occ` command, or a Task Processing provider — anywhere no page is waiting.

#### `status(?string $app = null): array`

Returns `['ready' => bool, 'reason' => string, 'provider' => string, 'mode' => string, 'model' => string, 'search' => bool]`. Pass your app id to see the engine and model it will actually get (an app may be given its own). When not ready, `reason` is one of `no-key`, `no-cli`, `no-model`, `no-store`.

#### `ask` error codes

`not-ready`, `user-not-allowed`, `app-not-allowed`, `no-scenario`, `empty`, `busy`.

#### Example

Register the scenario in `boot()`. The `define` tool below is only an illustration — any tool is your own code, run with the asking user's rights.

```php
<?php
// lib/AppInfo/Application.php
namespace OCA\MyApp\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Server;

class Application extends App implements IBootstrap {
    public const APP_ID = 'myapp';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
    }

    public function boot(IBootContext $context): void {
        // Scenarios live in memory only, so register on every request.
        // If AI-Hub is not installed, the app keeps working without AI.
        if (!class_exists('\\OCA\\AIHub\\Service\\HubService')) {
            return;
        }

        $hub = Server::get(\OCA\AIHub\Service\HubService::class);

        $hub->registerScenario('myapp', 'assistant', [
            'system' => 'You are the assistant built into MyApp. '
                . 'Answer briefly and only about what the user is working on.',
            'tools' => [
                'define' => [
                    'description' => 'Look up a term and return its definition.',
                    'input' => [
                        'type' => 'object',
                        'properties' => [
                            'term' => [
                                'type' => 'string',
                                'description' => 'The word or phrase to define.',
                            ],
                        ],
                        'required' => ['term'],
                    ],
                    // Your own code, run with the asking user's rights.
                    // Return a string or an array (arrays go back to the model as JSON).
                    'run' => function (string $userId, array $args): string {
                        return $this->glossary->lookup($userId, $args['term']);
                    },
                ],
            ],
            'answer' => [
                'type' => 'object',
                'properties' => [
                    'text' => ['type' => 'string'],
                ],
                'required' => ['text'],
            ],
            'language' => null,  // follow the user's language
            'search' => false,
        ]);
    }
}
```

Then ask a question and poll for the answer — the ask and the poll are usually two separate requests (for example, an action handler that asks, and a small endpoint the browser polls):

```php
<?php
$hub = \OCP\Server::get(\OCA\AIHub\Service\HubService::class);

$messages = [
    ['role' => 'user', 'text' => 'What does "idempotent" mean here?'],
];

$ticket = $hub->ask($userId, 'myapp', 'assistant', $messages, [
    'context' => $openDocumentText,  // this question only; up to 200,000 characters
]);

if (isset($ticket['error'])) {
    // 'not-ready' | 'user-not-allowed' | 'app-not-allowed' | 'no-scenario' | 'empty' | 'busy'
    return;
}

// Later, poll until the answer is ready.
$reply = $hub->result($userId, $ticket['id']);
// ['state' => 'running']
//     — not finished yet; poll again
// ['state' => 'done', 'answer' => ['text' => '…'], 'text' => '…', 'tools' => ['define']]
//     — the answer, matching the scenario's schema, and which tools ran
// ['state' => 'error', 'error' => '…']
//     — the question failed
```

When no page is waiting — a background job, an `occ` command — use `askNow()` with the same arguments and read the answer straight from its return value.

### Over HTTP (OCS REST API)

Base path: `/ocs/v2.php/apps/ai_hub/api/v1`

| Method | Path | Body |
| --- | --- | --- |
| `GET` | `/status` | — |
| `POST` | `/ask` | `{"app": "...", "scenario": "...", "messages": [{"role": "user", "text": "..."}], "context": "...optional...", "search": false}` |
| `GET` | `/result/{id}` | — |

**Authentication** is either of:

- a logged-in session plus the `requesttoken` header (the browser side of an app), or
- HTTP Basic with a username and an **app password** (a script).

Always send the header `OCS-APIRequest: true`. For JSON, send `Accept: application/json` or add `?format=json`.

**Every response is wrapped:**

```json
{ "ocs": { "meta": { "status": "...", "statuscode": 0, "message": "..." }, "data": {} } }
```

`data` is exactly what the PHP `ask` / `result` / `status` return.

**HTTP status for `ask` errors:**

| Code | Errors |
| --- | --- |
| 403 | `user-not-allowed`, `app-not-allowed` |
| 400 | `no-scenario`, `empty` |
| 429 | `busy` |
| 503 | `not-ready` |

#### curl (script, with an app password)

```bash
# Ask a question.
curl -u alice:app-password-here \
  -H 'OCS-APIRequest: true' \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -X POST 'https://cloud.example.com/ocs/v2.php/apps/ai_hub/api/v1/ask' \
  -d '{
        "app": "myapp",
        "scenario": "assistant",
        "messages": [{"role": "user", "text": "What does idempotent mean?"}],
        "context": "optional live state, for this question only",
        "search": false
      }'
# {"ocs":{"meta":{"status":"ok","statuscode":200,"message":"OK"},"data":{"id":"a1b2c3"}}}

# Poll for the answer with the returned id.
curl -u alice:app-password-here \
  -H 'OCS-APIRequest: true' \
  -H 'Accept: application/json' \
  'https://cloud.example.com/ocs/v2.php/apps/ai_hub/api/v1/result/a1b2c3'
# {"ocs":{"meta":{"status":"ok","statuscode":200,"message":"OK"},
#   "data":{"state":"done","text":"…","answer":{"text":"…"},"tools":["define"]}}}
```

#### fetch (browser side of an app)

The session cookie plus the request token authenticate. `OC.getRootPath()` prefixes the server's webroot; `OC.requestToken` is the token the page already holds.

```js
async function askAIHub(text, context) {
  const res = await fetch(
    OC.getRootPath() + '/ocs/v2.php/apps/ai_hub/api/v1/ask',
    {
      method: 'POST',
      headers: {
        'OCS-APIRequest': 'true',
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'requesttoken': OC.requestToken,
      },
      body: JSON.stringify({
        app: 'myapp',
        scenario: 'assistant',
        messages: [{ role: 'user', text }],
        context,          // optional; for this question only
        search: false,
      }),
    },
  );
  const { ocs } = await res.json();
  // ocs.data === { id: "a1b2c3" }
  // On an error, HTTP is 4xx/5xx and ocs.data === { error: "busy" } (etc.)
  return ocs.data.id;
}

async function pollAIHub(id) {
  const res = await fetch(
    OC.getRootPath() + '/ocs/v2.php/apps/ai_hub/api/v1/result/' + id,
    {
      headers: {
        'OCS-APIRequest': 'true',
        'Accept': 'application/json',
        'requesttoken': OC.requestToken,
      },
    },
  );
  const { ocs } = await res.json();
  // ocs.data === { state: "done", text: "…", answer: { text: "…" }, tools: ["define"] }
  // or { state: "running" }, or { state: "error", error: "…" }
  return ocs.data;
}
```

### Through the Task Processing API

AI-Hub registers providers for `OCP\TaskProcessing\TaskTypes\TextToText` and `TextToTextChat`. An app uses the standard `OCP\TaskProcessing\IManager` (`scheduleTask`, `getTask`) with those task-type IDs; there is nothing AI-Hub-specific to call, and nothing to write — an app already using the standard API simply gains a model.

See the Nextcloud developer documentation for the Task Processing API: <https://docs.nextcloud.com/server/latest/developer_manual/> (under the "AI" / "Task Processing API" section).

## Administration

One settings page, for all apps:

- the engine, its key or its command-line tool, and the model, picked from the list the key can really use, with a connection test;
- **each app its own AI and model**: every app that connects is listed, and can be given an engine (Claude, Gemini, an OpenAI-compatible endpoint or the command-line tool) and a model of its own instead of the server-wide ones, each with its own connection test; Nextcloud's own Task Processing counts as one app, and the Base series (EditBase, RegiBase, FormulaBase, NetBase) and Talk-Bot are listed from the start, greyed out until installed;
- **who** may use the hub (everyone, or chosen users), **how often** (questions per user per minute) and **how many at once**;
- **which apps** may ask — once the list is set, an app that is not on it gets nothing;
- planned: a log of questions by app and user (counts and errors, never the words), so a bill can be explained.

## Safety

- An app reaches only its own scenarios, and only the tools it registered. The model can run nothing else: no files, no shell, no network of the server's own.
- Web search, where allowed, runs on the provider's side.
- Text that comes from a document, a record or a web page is handed to the model as material, never as instructions; the scenario says so, and tools return data, not commands.
- What the model asks a tool to do runs with the rights of the person asking, through the app's own code. AI-Hub never writes to another app's data.
- Keys are stored encrypted in the server's configuration and never leave it.

## The apps that connect

- **Talk-Bot** keeps its name and its job — AI chat in a Talk conversation — and runs on AI-Hub: its engines, keys and limits move here, and its own settings page shrinks to what is about Talk. It requires AI-Hub.
- **EditBase**'s assistant, which reads other apps and changes the open document, becomes a scenario of AI-Hub. Its administrator settings stay where they are.
- **RegiBase, FormulaBase and NetBase** can register scenarios of their own when there is something for an assistant to do in them.
- **Your app** connects the same way, under its own app id — AI-Hub does not need to know it in advance.

## Requirements

Nextcloud 30–35, PHP 8.1 or later, and one of: an API key for Claude, Gemini or an OpenAI-compatible service, or Claude Code / the Gemini CLI installed on the server.

## Installation

From the App Store, under Apps, search for **AI-Hub** and press *Download and enable*. Then open *Administration settings → AI-Hub*, choose the engine and put in the key, and press *Test connection*.

## Status

0.0.1 — the first shape: the engines, scenarios, the PHP and OCS ways in, the text-to-text and chat providers, and the settings page. Talk-Bot and EditBase move onto it next; the version stays below 0.1.0 until they do.

## Licence

AGPL-3.0-or-later. © 2026 KTEC.
