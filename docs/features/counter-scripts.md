# Counter scripts

## Purpose and workflow

This read-only module gives counter staff consistent check-in prompts, oil upsell talk tracks, and objection handling. Content is assembled by a support class and rendered as a normal application screen; it does not persist customer data.

## Code map

- Route/controller/view: `scripts.index` in [`routes/web.php`](../../routes/web.php), [`ScriptController`](../../app/Http/Controllers/ScriptController.php), [`scripts/index.blade.php`](../../resources/views/scripts/index.blade.php)
- Content/component: [`CounterScripts`](../../app/Support/CounterScripts.php), [`counter-scripts component`](../../resources/views/components/counter-scripts.blade.php)
- Module: [`ScriptsModule`](../../app/Modules/Features/ScriptsModule.php)
- Tests: [`CounterScriptsTest`](../../tests/Feature/CounterScriptsTest.php)

## Permissions and invariants

- Requires the `scripts` module and `scripts.view` permission.
- Keep this feature read-only. If scripts become editable, add validation, authorization, persistence, audit behavior, and tests rather than accepting raw rendered HTML.

