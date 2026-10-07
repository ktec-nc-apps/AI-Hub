# AI-Hub 🔌

**One AI gateway for every app in your Nextcloud: an app hands AI-Hub a scenario and a question, and gets back an answer it can act on.**

> A personal project, written for my own apps and shared in case it is useful to someone.
> Self-hosted; the key, the model and every question stay under your own control.

AI-Hub is open to any Nextcloud app, by anyone — not only the maker's own. If you write a Nextcloud app, you can connect it to AI-Hub using this README alone, with no change to AI-Hub itself.

## Contents

- [What it is](#what-it-is)
- [Engines](#engines)
- [Connecting to AI-Hub](#connecting-to-ai-hub)
  - [Declaring AI-Hub support](#declaring-ai-hub-support)
  - [From an app on the same server (PHP)](#from-an-app-on-the-same-server-php)
  - [Over HTTP (OCS REST API)](#over-http-ocs-rest-api)
  - [Through the Task Processing API](#through-the-task-processing-api)
  - [The wire protocol: tool and answer blocks](#the-wire-protocol-tool-and-answer-blocks)
  - [Reference](#reference)
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

### Declaring AI-Hub support

To advertise AI-Hub support — so administrators can pick your app from the list on the AI-Hub settings page instead of typing its id — ship a small file, **`appinfo/ai-hub.json`**, inside your app:

```json
{"ai-hub": 1, "scenarios": ["assistant"]}
```

- **`ai-hub`** (number, required) — the version of the AI-Hub protocol the app was written for; `1` today.
- **`scenarios`** (list of strings, optional) — the scenario names the app registers.

It is a file of its own rather than an element of `info.xml`, because the App Store's schema rejects unknown elements there. AI-Hub reads it from every installed app, enabled or not, so an app shows up as compatible before it has ever run. Nothing else is needed: an app without the file can still connect and still be added by its id.

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
- **`allow`** => `callable(string $userId): bool` — **your app's own rule on who may ask** (its administrator settings: a group, a list of users, a switch). AI-Hub calls it before every question, on every way in — the PHP API, the OCS endpoint and the request that computes the answer — so the rule cannot be gone round by calling AI-Hub directly instead of your controller. Omit it only when everyone on the server may ask this scenario.
- **`ocs`** => `bool` — open this scenario to the [OCS endpoint](#over-http-ocs-rest-api). Off by default: a scenario that your own controller gates (the usual case) is then not reachable round that controller. Set it only when the browser side of your app, or a script, is meant to call `/ask` itself; `allow` still applies.

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
- **`conversation`** => `string` — a short token (letters, digits, `_`, `-`; up to 64) that makes AI-Hub **remember this conversation** for you. Make a new random token when a conversation begins and send the *same* token with every later question; you can then send only the latest message each time (AI-Hub keeps the running transcript, keyed by app + user + token, and it survives a page reload). Send a new token — or call `forgetConversation()` — to start over. Omit it to stay stateless: you send the whole history yourself, exactly as before. The transcript is kept for **24 hours since its last use** (`CONV_KEEP`) and capped at the last **40 turns**.
- **`images`** => `list<['type' => string, 'data' => string]>` — images that go with the question (the last message): `data` is the image as base64 (a `data:` address is accepted too), `type` its media type. See [Images](#images).

Returns `['id' => string]` (a ticket), or `['error' => <code>]`: `not-ready`, `user-not-allowed` (the hub's own allow list, or the scenario's `allow` rule said no), `app-not-allowed`, `no-scenario`, `empty`, `busy`, and for images `no-images`, `too-many-images`, `image-too-large`, `image-type`.

#### Images

A question may carry up to **4 images** (`MAX_IMAGES`), each a **PNG, JPEG, GIF or WebP** of at most **5 MB** decoded (`MAX_IMAGE_BYTES`). AI-Hub checks them on the server whatever the app has checked: the kind is read from the image's own bytes (a declared `type` that does not match is replaced by the real one), and anything else is refused (`image-type`), as are a fifth image (`too-many-images`) and a larger one (`image-too-large`). With images the question's text may be empty — the images are the question. Shrink large photos in the browser before sending (a long side of about 2000 px is plenty for a model).

The images reach the model **with this question only**. A remembered conversation keeps a mark in their place — `[1 image] What colour is this?` — never the image, so a later question never sends them again; a history turn you send yourself may say how many images it had (`['role' => 'user', 'text' => '…', 'images' => 2]`) and is marked the same way. Text that appears in an image is material to work with, never an instruction to the model, as with a document.

How each engine passes them on: the Claude API as `image` content blocks, the Gemini API as `inline_data` parts, an OpenAI-compatible service as `image_url` parts holding a `data:` address (the model must be a vision model), and the Claude command line as one `stream-json` user message on stdin (`--input-format stream-json --output-format stream-json`, the answer read from its `result` event; not kept as a session on disk). **The Gemini command line takes no images** (it reads an image only through a file tool, and AI-Hub switches its tools off): `status()` then says `'images' => false` and a question with images is refused with `no-images` — show the person that this AI connection cannot send images.

#### `result(string $userId, string $id): array`

Returns `['state' => 'running'|'done'|'error'|'unknown', 'text' => string?, 'answer' => mixed?, 'tools' => string[]?, 'error' => string?]`. A `done` result is handed over once and then forgotten. Results are kept at most **30 minutes**.

#### `askNow(string $userId, string $app, string $scenario, array $messages, array $options = []): array`

Same inputs as `ask`, but it answers **inside the current request** and returns the same shape as `result()` (`state` `done` / `error`, with `text` / `answer` / `tools`). Use it from a background job, an `occ` command, or a Task Processing provider — anywhere no page is waiting.

#### `forgetConversation(string $userId, string $app, string $conversationId): void`

Drops the remembered transcript for one conversation — wire it to your app's "new conversation" so the server memory is cleared too. The key carries the user id, so a person can only ever clear their own.

#### `status(?string $app = null): array`

Returns `['ready' => bool, 'reason' => string, 'provider' => string, 'mode' => string, 'model' => string, 'search' => bool, 'images' => bool]`. Pass your app id to see the engine and model it will actually get (an app may be given its own). When not ready, `reason` is one of `no-key`, `no-cli`, `no-model`, `no-store`. `images` says whether images can go with a question (see [Images](#images)); an older AI-Hub leaves it out, so read a missing `images` as `false`.

#### `ask` error codes

`not-ready`, `user-not-allowed`, `app-not-allowed`, `no-scenario`, `empty`, `busy`, `no-images`, `too-many-images`, `image-too-large`, `image-type`.

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

**Remembering a conversation.** Give a `conversation` token and AI-Hub keeps the transcript for you:

```php
$cid = $cid ?? bin2hex(random_bytes(8));   // new per conversation; reuse to continue

$ticket = $hub->ask($userId, 'myapp', 'assistant', [
    ['role' => 'user', 'text' => $latestMessageOnly],   // the rest is remembered
], [
    'context' => $openDocumentText,
    'conversation' => $cid,
]);

// Starting over (your "new conversation"):
$hub->forgetConversation($userId, 'myapp', $cid);
```

The command-line engine (Claude Code) is handed the stored transcript with each question, like every other engine, and is run with `--no-session-persistence` and its auto memory off: it keeps nothing of the conversation on disk.

**A conversation lasts as long as the login it was held in.** AI-Hub keeps it under the person, the app, the token and the login (Nextcloud's own auth token for the browser session, or the app password an app signs in with): after a new login the same token is a new, empty conversation (the answer then says `'fresh' => true`, so a page still showing the earlier turns can clear them); logging out drops the conversations of that login; and one left unused for twelve hours is dropped as well. Keep the token where the browser tab keeps it (`sessionStorage`), so a reload goes on with the conversation and a new tab starts a new one. A caller with no login of its own — a background job, an `occ` command — keeps its conversations by the token alone, and the history it sends seeds one that has lapsed.

**Reading back, saving and summing up.** For an app's page, AI-Hub answers these itself (with the request token, like any page of the app; only ever the person's own conversation, of the login they are in):

| Request | Body / query | Answer |
|---|---|---|
| `GET /apps/ai_hub/conversation` | `app`, `conversation` | `{turns: [{role, text}]}` — empty when nothing is kept |
| `POST /apps/ai_hub/conversation/save` | `app`, `conversation` | `{name, path, fileId, url}` — the conversation as Markdown in the person's Files, under `AI-Hub/<app name>/`, named `YYYY-MM-DD HHmm <the start of the first question>.md` (` (2)`, ` (3)` … when taken) |
| `POST /apps/ai_hub/conversation/summary` | `app`, `conversation` | `{id}` — the model sums the conversation up once, on the app's own AI connection with no tools and no search; poll the next request |
| `GET /apps/ai_hub/conversation/result/{id}` | | `{state: 'running'}`, or `{state: 'done', text, file}` with `file` as for `save`, or `{state: 'error', error}` |
| `POST /apps/ai_hub/conversation/forget` | `app`, `conversation` | `{ok: true}` |

From PHP the same are `transcript()`, `saveTranscript()`, `summarize()` (then `result()`) and `forgetConversation()`. A scenario may say how its turns read in a saved conversation with `'transcript' => fn (string $role, string $text): ?string` — the app's own blocks taken out, `null` to leave a turn out.

### Over HTTP (OCS REST API)

Base path: `/ocs/v2.php/apps/ai_hub/api/v1`

| Method | Path | Body |
| --- | --- | --- |
| `GET` | `/status` | — |
| `POST` | `/ask` | `{"app": "...", "scenario": "...", "messages": [{"role": "user", "text": "..."}], "context": "...optional...", "search": false, "conversation": "...optional token...", "images": [{"type": "image/png", "data": "<base64>"}]}` |
| `GET` | `/result/{id}` | — |
| `POST` | `/forget` | `{"app": "...", "conversation": "..."}` |

`conversation` works exactly as in the PHP API: send the same token to continue a remembered conversation (then `messages` may hold only the latest message), and `POST /forget` drops it. `forget` returns `{"ok": true}`. `images` too works as in the PHP API (see [Images](#images)); a refused image answers 400 (`too-many-images`, `image-type`), 413 (`image-too-large`) or 503 (`no-images`).

**Only a scenario registered with `'ocs' => true` answers here.** Every logged-in user can reach this endpoint, so a scenario that is gated inside its app's own controller is not offered over OCS unless the app says so; `ask` then answers `ocs-not-allowed` (403). The scenario's `allow` rule applies here as everywhere.

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

# Continue the same conversation — send only the latest message plus the token.
curl -u alice:app-password-here \
  -H 'OCS-APIRequest: true' -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -X POST 'https://cloud.example.com/ocs/v2.php/apps/ai_hub/api/v1/ask' \
  -d '{"app":"myapp","scenario":"assistant",
       "messages":[{"role":"user","text":"And what did I just ask?"}],
       "conversation":"7f3a9c1b2d4e5f60"}'

# Start over: forget the conversation.
curl -u alice:app-password-here \
  -H 'OCS-APIRequest: true' -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -X POST 'https://cloud.example.com/ocs/v2.php/apps/ai_hub/api/v1/forget' \
  -d '{"app":"myapp","conversation":"7f3a9c1b2d4e5f60"}'
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

### The wire protocol: tool and answer blocks

This section documents what happens between AI-Hub and the model when a scenario has **tools** or an **answer shape**. You almost never need it: **an app that uses `registerScenario` does not format or parse these blocks — AI-Hub does.** It builds the system prompt that teaches the model the protocol, reads the model's blocks back, runs your tools, validates the answer, and hands your app only the finished `text`, `answer` and `tools`. The protocol is written down here so the mechanism is clear, and so a client in another language — one that drives a model directly and wants to offer the same scenario shape — knows exactly what a scenario's tools and answer mean.

Everything travels as fenced code blocks in ordinary chat messages, which is why the same scenario works on every engine, the command-line ones included.

**Calling a tool.** When a scenario registers tools, AI-Hub appends their descriptions and input JSON Schemas to the system prompt and tells the model to call one by replying with **exactly one** fenced block and nothing else:

````text
```ai-hub-tool
{"tool": "<name>", "args": { ... }}
```
````

AI-Hub parses that block, looks up the registered tool, and runs its PHP `run(string $userId, array $args)` callback **with the rights of the person who asked**. The return value (a string, or an array encoded as JSON) is fed back to the model as the next message, in the form `Result of <name>:` followed by the result (truncated to 100,000 characters). The model then continues, and may call another tool or give its answer.

- If the model names a tool that does not exist, or its `args` do not satisfy the tool's input schema, AI-Hub sends the reason back to the model so it can try again; a `run` callback that throws is reported back the same way.
- A question may make at most **`MAX_TOOLS` = 8** tool calls; beyond that the question fails rather than loop.

**Returning a structured answer.** When a scenario sets an `answer` JSON Schema, AI-Hub tells the model that its final reply must contain **exactly one** fenced block holding JSON that fits the schema, with any plain words for the person placed *before* the block:

````text
```ai-hub-answer
{ ... }
```
````

AI-Hub strips the block out of the reply (what is left becomes `text`), parses the JSON, and validates it against the schema. If the block is missing or the JSON does not fit, AI-Hub sends the problem back to the model and asks it to answer again **once**; if the second attempt still does not fit, the question fails with an error rather than return a guess. The validator understands `type` (including unions and `null`), `required`, `properties`, `items` and `enum`. When a scenario sets no `answer`, the reply is free text and the whole reply is returned as `text`.

A client that only calls `ask` / `result` (or the OCS endpoints) never sees these blocks: it receives `text` (the words for the person, blocks removed), `answer` (the validated JSON, or `null`) and `tools` (the names of the tools that ran).

**Limits**, with their real values in the code (`lib/Service/HubService.php`):

| Limit | Value | Meaning |
| --- | --- | --- |
| Tool calls per question | **8** (`MAX_TOOLS`) | How many tool calls one question may make before it fails. |
| Conversation turns | **40** (`MAX_TURNS`) | The most recent turns handed to the model; the caller history and any remembered transcript are both trimmed to this. |
| `context` size | **200,000 characters** | The per-question `context` is truncated to this. |
| Tool result size | **100,000 characters** | Each tool result fed back to the model is truncated to this. |
| Answer-shape retries | **1** | A final answer that misses the schema is bounced back once, then the question fails. |
| Result retention | **30 minutes** (`KEEP` = 1800 s) | How long a ticket's answer is kept. A `done` result is handed over once, then dropped. |
| Remembered conversation | **24 hours** (`CONV_KEEP` = 86400 s) | A named conversation is kept this long since its last use; each `ask` / `askNow` refreshes it. |
| Images per question | **4** (`MAX_IMAGES`) | PNG, JPEG, GIF or WebP; with this question only, a mark in the transcript. |
| Image size | **5 MB** (`MAX_IMAGE_BYTES`) | Each image, decoded. |

### Reference

Everything an integrator calls, in one place.

**`HubService` (PHP, `\OCA\AIHub\Service\HubService`)**

| Call | Signature | Returns |
| --- | --- | --- |
| Register a scenario | `registerScenario(string $app, string $name, array $spec)` | `void` (throws `InvalidArgumentException` on a bad name) |
| Ask (get a ticket) | `ask(string $userId, string $app, string $scenario, array $messages, array $options = [])` | `['id' => string]` or `['error' => <code>]` |
| Ask (answer inline) | `askNow(string $userId, string $app, string $scenario, array $messages, array $options = [])` | `['state' => 'done'\|'error', 'text'?, 'answer'?, 'tools'?, 'error'?]` |
| Poll for the answer | `result(string $userId, string $id)` | `['state' => 'running'\|'done'\|'error'\|'unknown', 'text'?, 'answer'?, 'tools'?, 'error'?]` |
| What can be asked now | `status(?string $app = null)` | `['ready' => bool, 'reason', 'provider', 'mode', 'model', 'search', 'images']` |
| Forget a conversation | `forgetConversation(string $userId, string $app, string $conversationId)` | `void` |
| Is a scenario known | `hasScenario(string $app, string $name)` | `bool` |
| List an app's scenarios | `scenariosOf(string $app)` | `string[]` |

`registerScenario` **`$spec`** keys:

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `system` | `string` | `''` | The system prompt. |
| `tools` | `array` | none | `name ⇒ ['description' => string, 'input' => <JSON Schema>, 'run' => callable(string $userId, array $args): string\|array]`. |
| `answer` | JSON Schema `array` \| `null` | `null` | The shape the final answer must fit; `null` / omitted = free text. |
| `search` | `bool` | `false` | Allow provider-side web search. |
| `language` | `string` \| `null` \| `false` | `null` | A language code (answer in it), `null` (follow the user), or `false` (your system prompt decides). |
| `allow` | `callable(string $userId): bool` | none | The app's own rule on who may ask; checked by AI-Hub before every question, on every way in. |
| `ocs` | `bool` | `false` | Whether the OCS endpoint may ask this scenario. |

`ask` / `askNow` **`$options`** keys:

| Key | Type | Meaning |
| --- | --- | --- |
| `context` | `string` | Added to the system prompt for this question only; capped at 200,000 characters. |
| `search` | `bool` | Overrides the scenario's `search` for this question. |
| `conversation` | `string` | A token (letters, digits, `_`, `-`; up to 64) that makes AI-Hub remember the transcript (see [PHP API](#from-an-app-on-the-same-server-php)). |
| `images` | `list<['type' => string, 'data' => string]>` | Up to 4 PNG / JPEG / GIF / WebP images (base64, 5 MB each) that go with the question; see [Images](#images). |

Each message, and the context, is capped at 200,000 characters.

`ask` **error codes**: `not-ready`, `user-not-allowed`, `app-not-allowed`, `no-scenario`, `ocs-not-allowed`, `empty`, `busy`, `no-images`, `too-many-images`, `image-too-large`, `image-type`.
`status` **`reason`** when not ready: `no-key`, `no-cli`, `no-model`, `no-store`.

**OCS endpoints** — base path `/ocs/v2.php/apps/ai_hub/api/v1` (send `OCS-APIRequest: true`; authenticate with a session + `requesttoken`, or Basic auth with an app password):

| Method | Path | Request body | Response `data` |
| --- | --- | --- | --- |
| `GET` | `/status` | — | `{"ready": bool, "reason": "...", "provider": "...", "mode": "...", "model": "...", "search": bool, "images": bool}` |
| `POST` | `/ask` | `{"app": "...", "scenario": "...", "messages": [{"role": "user"\|"assistant", "text": "...", "images"?: n}], "context"?: "...", "search"?: bool, "conversation"?: "...", "images"?: [{"type": "image/png", "data": "<base64>"}]}` | `{"id": "..."}` or `{"error": "<code>"}` |
| `GET` | `/result/{id}` | — | `{"state": "running"\|"done"\|"error"\|"unknown", "text"?: "...", "answer"?: ..., "tools"?: ["..."], "error"?: "..."}` |
| `POST` | `/forget` | `{"app": "...", "conversation": "..."}` | `{"ok": true}` |

Every OCS response is wrapped: `{ "ocs": { "meta": { "status", "statuscode", "message" }, "data": { ... } } }`, and `data` is exactly the PHP return above. The HTTP status for an `ask` error follows the [table above](#over-http-ocs-rest-api) (403 / 400 / 413 / 429 / 503).

## Administration

One settings page, for all apps:

- the engine, its key or its command-line tool, and the model, picked from the list the key can really use, with a connection test;
- **each app its own AI and model**: every app that connects is listed, and can be given an engine (Claude, Gemini, an OpenAI-compatible endpoint or the command-line tool) and a model of its own instead of the server-wide ones, each with its own connection test; Nextcloud's own Task Processing counts as one app, and the Base series (EditBase, CalcBase, RegiBase, FormulaBase, NetBase) and Talk-Bot are listed from the start, greyed out until installed; any other app that [declares AI-Hub support](#declaring-ai-hub-support) can be picked from a dropdown, and any app at all can be added by its id;
- **who** may use the hub (everyone, or chosen users), **how often** (questions per user per minute) and **how many at once**;
- **which apps** may ask — once the list is set, an app that is not on it gets nothing;
- planned: a log of questions by app and user (counts and errors, never the words), so a bill can be explained.

## Safety

- An app reaches only its own scenarios, and only the tools it registered. The model can run nothing else: no files, no shell, no network of the server's own.
- Who may ask a scenario is the app's own rule (`allow`), and AI-Hub enforces it on every way in; the OCS endpoint reaches only the scenarios an app opened to it.
- Web search, where allowed, runs on the provider's side.
- Text that comes from a document, a record or a web page is handed to the model as material, never as instructions; the scenario says so, and tools return data, not commands.
- What the model asks a tool to do runs with the rights of the person asking, through the app's own code. AI-Hub never writes to another app's data.
- Keys are stored encrypted in the server's configuration and never leave it.

## The apps that connect

- **Talk-Bot** keeps its name and its job — AI chat in a Talk conversation — and runs on AI-Hub: its engines, keys and limits move here, and its own settings page shrinks to what is about Talk. It requires AI-Hub.
- **EditBase**'s assistant, which reads other apps and changes the open document, becomes a scenario of AI-Hub. Its administrator settings stay where they are.
- **CalcBase**, the spreadsheet of the series, has an assistant that explains and writes formulas and changes cells when asked, through a scenario with an answer shape.
- **RegiBase, FormulaBase and NetBase** can register scenarios of their own when there is something for an assistant to do in them.
- **Your app** connects the same way, under its own app id — AI-Hub does not need to know it in advance.

## Requirements

Nextcloud 30–35, PHP 8.1 or later, and one of: an API key for Claude, Gemini or an OpenAI-compatible service, or Claude Code / the Gemini CLI installed on the server.

## Installation

From the App Store, under Apps, search for **AI-Hub** and press *Download and enable*. Then open *Administration settings → AI-Hub*, choose the engine and put in the key, and press *Test connection*.

## Status

0.1.0 — the first public release: the engines (the Claude, Gemini and OpenAI-compatible APIs, and the Claude and Gemini command lines), scenarios, the PHP and OCS ways in, Nextcloud's Task Processing providers, an engine, a model and an additional prompt for each app, images with a question, and conversations kept for the length of a login, with saving and summing up. EditBase uses it; the other Base apps and Talk-Bot follow.

## Licence

AGPL-3.0-or-later. © 2026 KTEC.
