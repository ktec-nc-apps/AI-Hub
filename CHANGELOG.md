# Changelog

All notable changes to AI-Hub are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [0.1.0] — 2026-10-08

### Added
- Saving a conversation (the owner, 2026-10-06): `/apps/ai_hub/conversation` reads a remembered
  conversation back, `…/save` writes it to the person's Files as Markdown (`AI-Hub/<app>/<date> <time>
  <first question>.md`, in their language and time zone, images as "[n images]"), `…/summary` has the
  model sum it up once and saves that, `…/forget` drops it; from PHP `transcript()`, `saveTranscript()`,
  `summarize()`. A scenario's optional `transcript` callable shapes how its turns read when saved.
- Images with a question: an app may send up to 4 PNG, JPEG, GIF or WebP images (5 MB each) with a question, checked again on the server, and every engine passes them on — the Claude, Gemini and OpenAI-compatible APIs, and the Claude command line through a stream-json message. The Gemini command line takes none, and `status()` says so (`images`). A remembered conversation keeps a mark in place of the images, never the images themselves. / 質問に画像（PNG・JPEG・GIF・WebP、1 枚 5MB まで、4 枚まで）を付けられるようにした。サーバーでも確かめ、各エンジンに渡す（Claude・Gemini・OpenAI 互換の API、Claude のコマンドラインは stream-json で）。Gemini のコマンドラインは画像を受け取れず、`status()` の `images` で分かる。記録する会話には画像そのものでなく印だけを残す。
- Per-app additional prompt: in the administration settings each connected app (and Nextcloud's Task Processing) can be given its own instructions, added to every question that app sends, after the app's own instructions. Text from documents and records still never counts as an instruction. / 管理者設定で、アプリごと（Nextcloud の Task Processing を含む）に追加プロンプトを書けるようにした。そのアプリが送るすべての質問に、アプリ自身の指示の後に加わる。文書やレコードの中の文は従来どおり指示として扱わない。

### Changed
- A remembered conversation lasts as long as the login it was held in (the owner, 2026-10-06): it is
  kept under the login (Nextcloud's auth token of the browser session, or the app password), so a
  new login starts a new conversation; logging out drops the conversations of that login; one unused
  for twelve hours (was a day) is dropped. The records kept the old way were removed. A caller with
  no login of its own is seeded from the history it sends when its record has lapsed. The answer
  says `fresh` when it began afresh, so a page can clear the turns it still shows.
- A scenario can carry the app's own rule on who may ask (`allow`), and AI-Hub checks it on every
  way in; the OCS endpoint answers only the scenarios an app opened to it (`ocs`), so an app's
  gate cannot be gone round by calling AI-Hub directly.
- The Claude API is given room for long answers (16,384 output tokens, up from 4,096), and an
  answer cut off at the limit is noted in the log.
- The Gemini command line tool is given its prompt on stdin, so a long context no longer runs into
  the kernel's argument limit.

### Fixed
- The Claude command line no longer leaves a file in its home for every question: it is run with
  `--no-session-persistence` and with its auto memory off (`CLAUDE_CODE_DISABLE_AUTO_MEMORY`), and no
  longer resumes sessions of its own (it is handed the stored transcript, as every engine is), so
  `.claude/projects` under its home stops growing.
- A remembered conversation is keyed by a hash of app, user and token: a user id holding `_`
  could collide with another user's conversation.
- When the Claude command line tool cannot resume its session, the dead session id is dropped
  instead of being tried again (and failing again) on every later turn.
- The per-session working folders of the command line tool are swept with their conversation.
- Each message handed to the model is capped, as the context already was.

- CalcBase, the spreadsheet of the Base series, is listed from the start among the apps that
  connect, as the others of the series are.

### Removed
- The "tools for administrators" setting, which no request ever used; the tools setting that
  remains applies to Nextcloud's own Task Processing and the connection test, and says so.

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
- Each app that connects is listed and can be given an AI and a model of its own, with a
  connection test per app; Nextcloud's own Task Processing counts as one app, and the Base
  series (EditBase, RegiBase, FormulaBase, NetBase) and Talk-Bot are listed from the start,
  greyed out until installed.
