# Changelog

All notable changes to AI-Hub are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [0.1.7] — 2026-11-08

### Added
- What each app's assistant may do, set per app in the administration settings: read data in the app, search the internet, add, change, delete, and the administrators' operations on servers and devices (the last one off unless turned on). What is unticked there is not done, whatever the app's own settings allow: the tools for it are not offered to the model at all, and it tells the person how to do it themselves. An app says for each of its tools whether it reads, adds, changes, deletes or acts on servers and devices (`kind`); a tool that says nothing runs only when adding, changing and deleting are all allowed.
- The record of what the tools really did: an answer now comes with `actions` — each tool call, its kind, whether it worked, and the app's own account of it made from its data (`_report` in the tool's result) — so an app can show "What was actually done" apart from the model's words.
- An additional prompt for every app, in the administration settings, added to every question before each app's own additional prompt (an app's own wins where the two differ).

### Changed
- Rules against made-up answers, for every app and every engine: say only what the question, the app or a tool gave; never claim an action a tool did not report as done; work numbers out with a tool; do not summarise or shorten what was asked for in full, and say so when a list is cut. The instructions are laid out the same way for every engine — the app's instructions, the tools, the app's data, these rules, the administrator's instructions, and a short check at the end.
- When an answer may claim that something was done — a change was tried, or its words say so — the model is shown the record of its tool calls and asked to correct anything the record does not bear out.
- Whatever a model writes after asking for a tool is dropped, so it cannot make the tool's result up.
- The answer is written in the language of the person's latest message, even when the app's data is in another language.
- The Claude command line tool runs on AI-Hub's instructions alone (`--system-prompt`, in safe mode, with no skills, plugins, hooks or CLAUDE.md of its own), no longer on top of its own instructions for a coding assistant.

### Fixed
- Translations reviewed: the Japanese in full, and every language for wrong meanings. "Test connection" is now named as the button says.

## [0.1.3] — 2026-11-07

### Added
- Signing in again from the administration settings: when the Claude command line tool's sign-in has
  run out, "Sign-in of the Claude command line tool" shows its state and walks through signing in
  again (open the link, sign in, paste the code), without a shell on the server.
- Default model and effort in the administration settings: pick the model every app uses unless it
  has its own (from the list, or type a name under "Other"), whatever the command line tool is set
  to, and how hard Claude thinks before answering (low to max, or the model's default). The effort
  goes to the Claude API (`output_config.effort`) and the command line tool (`--effort`); Gemini and
  OpenAI-compatible services ignore it. "Load models" fills the same list.
- AI-Hub is listed in the App Store's AI category as well.

### Changed
- A sign-in that has run out ("Failed to authenticate: OAuth session expired …") is now recognized as
  one: the person asking is told, in their own language, to ask an administrator to sign in again,
  and the connection test points to the sign-in, instead of the tool's raw English message coming
  back as an answer.
- Nextcloud's own Task Processing (the Assistant) now goes through the same allowlist of users and the
  same limits on questions a minute and at once as the apps. "Allowed apps" does not shut it out.
- A system prompt too long to pass as an argument (a long document's context) goes to the Claude
  command line tool on stdin, so the tool no longer fails to start.

### Fixed
- A question with many tool calls could be lost while still running, and its slot under the limit on
  questions at once could lapse early and let one more through: both are now kept as long as the
  question can run.
- A conversation cut to its last turns always starts with a question, which the model's API requires.
- Summing up a saved conversation follows the app's rule on who may ask.
- Remembered conversations are no longer swept away with old answers.

## [0.1.1] — 2026-10-19

### Added
- The administration settings in twenty-one languages: twelve more, Arabic, Czech, Persian, Hindi,
  Indonesian, Polish, European Portuguese, Russian, Thai, Turkish, Ukrainian and Vietnamese.

### Fixed
- Translations corrected after a review: the Japanese labels (ready, what each app gets, comma), the
  Arabic image count for zero, "Home-Verzeichnis" in German, one form of address in Spanish, and others.

## [0.1.0] — 2026-10-08

### Added
- Saving a conversation (the owner, 2026-10-06): `/apps/ai_hub/conversation` reads a remembered
  conversation back, `…/save` writes it to the person's Files as Markdown (`AI-Hub/<app>/<date> <time>
  <first question>.md`, in their language and time zone, images as "[n images]"), `…/summary` has the
  model sum it up once and saves that, `…/forget` drops it; from PHP `transcript()`, `saveTranscript()`,
  `summarize()`. A scenario's optional `transcript` callable shapes how its turns read when saved.
- Images with a question: an app may send up to 4 PNG, JPEG, GIF or WebP images (5 MB each) with a question, checked again on the server, and every engine passes them on — the Claude, Gemini and OpenAI-compatible APIs, and the Claude command line through a stream-json message. The Gemini command line takes none, and `status()` says so (`images`). A remembered conversation keeps a mark in place of the images, never the images themselves. / 質問に画像（PNG・JPEG・GIF・WebP、1 枚 5 MB まで、最大 4 枚）を添付できるようになりました。画像はサーバー側でも検証し、すべてのエンジンに渡します（Claude・Gemini・OpenAI 互換の各 API と、Claude のコマンドラインツール。コマンドラインツールには stream-json 形式のメッセージで渡します）。Gemini のコマンドラインツールは画像を受け付けません。そのことは `status()` の `images` で分かります。記憶する会話には画像そのものは残さず、画像の代わりに「[画像 n 枚]」という目印だけを残します。
- Per-app additional prompt: in the administration settings each connected app (and Nextcloud's Task Processing) can be given its own instructions, added to every question that app sends, after the app's own instructions. Text from documents and records still never counts as an instruction. / 管理者設定で、接続するアプリごと（Nextcloud の Task Processing を含む）に追加プロンプトを設定できるようになりました。そのアプリが送るすべての質問に、アプリ自身の指示の後に加えます。文書やレコードの中の文は、これまでどおり指示として扱いません。

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
