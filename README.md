# AI-Hub 🔌

**One AI gateway for every app in your Nextcloud: an app hands AI-Hub a scenario and a question, and gets back an answer it can act on.**
**Nextcloud のすべてのアプリのための AI ゲートウェイ。アプリはシナリオと質問を渡すだけで、そのまま使える答えを受け取ります。**

> A personal project, written for my own apps and shared in case it is useful to someone.
> Self-hosted; the key, the model and every question stay under your own control.
> 自分のアプリのために作った個人プロジェクトで、どなたかの役に立てばと思い公開しています。
> セルフホストで、キー・モデル・やり取りはすべてあなたの管理下に置かれます。

[English ↓](#english) · [日本語 ↓](#japanese)

---

<a id="english"></a>
## English

### What it is

Every app that wants to use an AI model has to solve the same things: where the key is kept, which model answers, how long to wait, who may ask, how many times, what the model is allowed to do, and how to turn its words into something the app can act on. AI-Hub solves them once, for all the apps on the server.

An app does not talk to a model. It registers a **scenario** — what the assistant is, what it may do, and what shape its answers take — and then sends the user's words. AI-Hub chooses the engine, runs the conversation, runs the tools the scenario allows, checks the answer against the shape, and hands it back. The same scenario works whatever the administrator has connected: Claude, Gemini, an OpenAI-compatible service, or a command-line subscription on the server.

AI-Hub is also a **provider for Nextcloud's own Task Processing API**, so Nextcloud Assistant, Talk, Mail and any app written against the standard API can use the connected models without knowing AI-Hub exists.

### Three ways in

1. **From an app on the same server (PHP).** Register a scenario once; then `ask()` with the conversation and get a ticket, and `result()` when the answer is there. Long answers are worked out in a request of their own, so a page never waits for a model.
2. **Over HTTP (OCS).** `POST /ocs/v2.php/apps/ai_hub/api/v1/ask` and `GET …/result/{id}`, with the user's session or an app password — for the browser side of an app, or for a script.
3. **Through Nextcloud's Task Processing API.** AI-Hub registers providers for *text to text* and *chat*; the other text tasks the server defines (summary, translation, headline, proofreading …) follow. Nothing to write: the apps that already use the standard API simply gain a model.

### A scenario

A scenario is what an app registers, in PHP, under a name of its own:

- **the system prompt** — who the assistant is and how it answers; the app may add to it per question (the open document, the records in view);
- **tools** — each with a name, a description, the shape of its arguments (JSON Schema) and a PHP callback. AI-Hub runs the tool loop: the model asks, the callback runs with the rights of the person asking, the result goes back to the model;
- **the shape of the answer** — plain text, or a JSON Schema the answer must satisfy. An answer that does not fit is sent back to the model once, then refused rather than guessed at;
- **what it may reach** — web search (on the provider's side, so the internet and never the server's own network), and nothing else unless a tool says so;
- **the language** to answer in, or "follow the user".

```php
$hub = \OCP\Server::get(\OCA\AIHub\Service\HubService::class);

$hub->registerScenario('editbase', 'assistant', [
    'system' => 'You are the assistant built into EditBase …',
    'tools' => [
        'read_regibase' => [
            'description' => 'Read the records of a RegiBase collection (never the secret fields).',
            'input' => ['type' => 'object', 'properties' => ['collection' => ['type' => 'integer']]],
            'run' => fn (string $uid, array $args) => $regibase->records($uid, $args['collection']),
        ],
    ],
    'answer' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string'], 'actions' => ['type' => 'array']]],
    'search' => true,
]);

$ticket = $hub->ask($uid, 'editbase', 'assistant', $messages, ['context' => $documentText]);
// later
$reply = $hub->result($uid, $ticket);   // ['state' => 'done', 'answer' => [...]] or 'running' / 'error'
```

### Engines

- **Anthropic Claude** and **Google Gemini** through their own APIs, with tool use and web search where the provider offers them.
- **Any OpenAI-compatible endpoint** — OpenRouter, DeepSeek, Mistral, Groq, OpenAI itself, or a server of your own running Ollama, vLLM or LM Studio.
- **A command-line subscription**: where Claude Code or the Gemini CLI is installed on the server, AI-Hub drives it, so a flat-rate plan answers everyone with no per-token bill. Off by default; tools are given to it only as the scenario says.

The engines are the ones Talk-Bot has run since 2026; they move here, and Talk-Bot becomes a client.

### Administration

One settings page, for all apps:

- the engine, its key or its command-line tool, and the model, picked from the list the key can really use, with a connection test;
- **each app its own AI and model**: every app that connects is listed, and can be given an engine (Claude, Gemini, an OpenAI-compatible endpoint or the command-line tool) and a model of its own instead of the server-wide ones, each with its own connection test; Nextcloud's own Task Processing counts as one app, and the Base series (EditBase, RegiBase, FormulaBase, NetBase) is listed from the start, greyed out until installed;
- **who** may use the hub (everyone, or chosen users), **how often** (questions per user per minute) and **how many at once**;
- **which apps** may ask — once the list is set, an app that is not on it gets nothing;
- planned: a log of questions by app and user (counts and errors, never the words), so a bill can be explained.

### Safety

- An app reaches only its own scenarios, and only the tools it registered. The model can run nothing else: no files, no shell, no network of the server's own.
- Web search, where allowed, runs on the provider's side.
- Text that comes from a document, a record or a web page is handed to the model as material, never as instructions; the scenario says so, and tools return data, not commands.
- What the model asks a tool to do runs with the rights of the person asking, through the app's own code. AI-Hub never writes to another app's data.
- Keys are stored encrypted in the server's configuration and never leave it.

### What becomes of Talk-Bot and EditBase

- **Talk-Bot** keeps its name and its job — AI chat in a Talk conversation — and runs on AI-Hub: its engines, keys and limits move here, and its own settings page shrinks to what is about Talk. It requires AI-Hub.
- **EditBase**'s assistant, which reads other apps and changes the open document, becomes a scenario of AI-Hub. Its administrator settings stay where they are.
- **RegiBase, FormulaBase and NetBase** can register scenarios of their own when there is something for an assistant to do in them.

### Requirements

Nextcloud 30–35, PHP 8.1 or later, and one of: an API key for Claude, Gemini or an OpenAI-compatible service, or Claude Code / the Gemini CLI installed on the server.

### Installation

From the App Store, under Apps, search for **AI-Hub** and press *Download and enable*. Then open *Administration settings → AI-Hub*, choose the engine and put in the key, and press *Test connection*.

### Status

0.0.1 — the first shape: the engines, scenarios, the PHP and OCS ways in, the text-to-text and chat providers, and the settings page. Talk-Bot and EditBase move onto it next; the version stays below 0.1.0 until they do.

---

<a id="japanese"></a>
## 日本語

### 概要

AI のモデルを使いたいアプリは、どれも同じことを解かなければなりません。キーをどこに置くか、どのモデルが答えるか、どれだけ待つか、誰が何回まで聞けるか、モデルに何を許すか、そしてモデルの言葉をアプリが扱える形にどう変えるか。AI-Hub はこれを一度だけ解き、サーバー上のすべてのアプリに提供します。

アプリはモデルと直接やり取りしません。**シナリオ**（アシスタントが何者で、何をしてよく、どんな形で答えるか）を登録し、あとは利用者の言葉を送るだけです。AI-Hub がエンジンを選び、会話を回し、シナリオが許した道具を動かし、答えが形に合っているかを確かめて返します。管理者がつないだ物が Claude でも Gemini でも OpenAI 互換のサービスでも、サーバー上のコマンドライン定額契約でも、同じシナリオがそのまま動きます。

AI-Hub は **Nextcloud 本体の Task Processing API の提供元（Provider）** でもあります。Nextcloud Assistant・Talk・Mail など、本体の標準の API で書かれたアプリは、AI-Hub の存在を知らなくても、つないだモデルを使えるようになります。

### 3つの入口

1. **同じサーバーのアプリから（PHP）**：シナリオを一度登録し、`ask()` に会話を渡して受付番号を受け取り、答えができたら `result()` で受け取ります。長い処理は別の要求で回すので、画面がモデルを待つことはありません。
2. **HTTP から（OCS）**：`POST /ocs/v2.php/apps/ai_hub/api/v1/ask` と `GET …/result/{id}`。利用者のセッションかアプリパスワードで、アプリの画面側のプログラムや外部のスクリプトから使えます。
3. **Nextcloud 本体の Task Processing API から**：文章→文章と会話の提供元として登録します。本体が定義するほかの文章の仕事（要約・翻訳・見出し・校正など）は追って加えます。何も書かずに、標準の API を使っている既存のアプリにモデルが加わります。

### シナリオ

シナリオは、アプリが PHP で、自分の名前のもとに登録する物です。

- **システムプロンプト**：アシスタントが何者で、どう答えるか。質問ごとにアプリが足せます（開いている文書、見ているレコードなど）。
- **道具**：名前・説明・引数の形（JSON Schema）・PHP の呼び戻し。モデルが道具を求め、呼び戻しが聞いた人の権限で動き、結果がモデルに戻る往復は AI-Hub が回します。
- **答えの形**：ただの文か、答えが満たすべき JSON Schema。合わない答えは一度だけモデルに差し戻し、それでも合わなければ推測せずに断ります。
- **届く範囲**：Web 検索（提供元の側で行うので、届くのはインターネットだけで、サーバー自身の網には届きません）。それ以外は、道具が言わない限り何もありません。
- **答える言語**：固定か、利用者に合わせるか。

### エンジン

- **Anthropic Claude** と **Google Gemini**：それぞれの API で。道具の利用と Web 検索は提供元が持つ範囲で。
- **OpenAI 互換のサービス**：OpenRouter・DeepSeek・Mistral・Groq・OpenAI 本体、または Ollama・vLLM・LM Studio を動かす自前のサーバー。
- **コマンドラインの定額契約**：サーバーに Claude Code か Gemini CLI が入っていれば AI-Hub がそれを動かし、トークンごとの請求なしに定額で全員に答えます。既定では切。道具はシナリオが言う物だけ渡します。

エンジンは Talk-Bot が 2026 年から動かしてきた物をここへ移します。Talk-Bot は利用者側になります。

### 管理

すべてのアプリに共通の設定画面を一つ：

- エンジン、そのキーかコマンドラインの道具、モデル（キーで実際に使える物の一覧から選ぶ）、接続テスト。
- **アプリごとに別の AI とモデル**：接続してきたアプリを一覧に並べ、サーバー共通とは別のエンジン（Claude・Gemini・OpenAI 互換・コマンドラインの道具）とモデルを与えられます。行ごとに接続テストもできます。Nextcloud 本体の Task Processing も一つのアプリとして数え、Base シリーズ（EditBase・RegiBase・FormulaBase・NetBase）は最初から並び、未インストールの間は灰色で表示します。
- **誰が**使えるか（全員か、選んだユーザー）、**何回まで**（利用者ごとに1分あたり）、**同時にいくつまで**。
- **どのアプリが**聞いてよいか。一覧を書けば、無いアプリには何も渡しません。
- （予定）アプリ別・利用者別の記録（回数と誤りだけ。言葉は残しません）。請求の説明に使えます。

### 安全

- アプリが届くのは自分のシナリオと、自分が登録した道具だけ。モデルはそれ以外を動かせません。ファイルも、シェルも、サーバー自身の網もありません。
- Web 検索は、許した場合も提供元の側で行います。
- 文書・レコード・Web ページから来た文は、材料としてモデルに渡し、指示としては扱いません。シナリオにそう書き、道具は命令ではなくデータを返します。
- モデルが道具に求めた処理は、聞いた人の権限で、そのアプリ自身のコードを通して動きます。AI-Hub が他のアプリのデータに書き込むことはありません。
- キーはサーバーの設定に暗号化して保存し、外へは出しません。

### Talk-Bot と EditBase はどうなるか

- **Talk-Bot** は名前と役目（Talk の会話での AI チャット）を保ったまま AI-Hub の上で動きます。エンジン・キー・制限はこちらへ移し、Talk-Bot の設定画面は Talk に関わる物だけになります。AI-Hub を必要とします。
- **EditBase** のアシスタント（他のアプリを参照し、開いている文書を直す物）は AI-Hub のシナリオになります。EditBase の管理者設定は今の場所のままです。
- **RegiBase・FormulaBase・NetBase** も、アシスタントにさせることができた時に、自分のシナリオを登録できます。

### 動作要件

Nextcloud 30〜35、PHP 8.1 以上。それに、Claude・Gemini・OpenAI 互換サービスのいずれかの API キー、またはサーバーに入れた Claude Code / Gemini CLI。

### 導入

App Store の「アプリ」で **AI-Hub** を探し、「ダウンロードして有効にする」を押します。次に「管理者設定 → AI-Hub」でエンジンを選んでキーを入れ、「接続テスト」を押します。

### 現在の状態

0.0.1 — 最初の形。エンジン、シナリオ、PHP と OCS の入口、文章→文章と会話の提供元、設定画面。次に Talk-Bot と EditBase をこの上へ移します。それまで版は 0.1.0 未満のままです。

---

## Licence

AGPL-3.0-or-later. © 2026 KTEC.
