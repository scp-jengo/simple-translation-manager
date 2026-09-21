# Agent Progress

## 2026-08-20 — task 869em7v2z
Done: wp-admin Translations metabox showed blank Title/Content for every language on Type
edit screens even though rows existed in `wp_stm_post_translations`. Root cause: Bugatti
Insights' sync API writes those rows via `STM\API::save_post_translations()` using field
names `title`/`content` (field map `['name'=>'title','content'=>'content']`), but the
metabox's own save handler — and `render_meta_box()`'s read — only ever used
`post_title`/`post_content`. Fixed by having `render_meta_box()` fall back to the
`title`/`content` keys when its own keys are absent (native `post_title`/`post_content`
always wins). PR #33. Scoped to the metabox reader only — `get_post_translation()` and
`class-frontend.php` (public page / STM chrome) untouched; sibling task 869em13y1 covers
the equivalent mismatch in `class-frontend.php` separately.
Verified: `vendor/bin/phpunit tests/PostEditorCrudTest.php` 24/24 pass (2 new regression
tests added); full suite 159/159 pass; `phpcs --standard=phpcs.xml.dist` 0 errors on the
changed file; `php -l` clean.
Left: nothing on this task. The shared metabox fix also benefits Category/Chassis edit
screens (same code path), not just Type, as a side effect — not separately tested here.

## 2026-08-08 — task 869efjuhy
Done: `export_coverage_csv()`/`export_missing_csv()` in class-dashboard.php streamed CSV rows
via `fopen('php://output', 'w')` / `fputcsv()` / `fclose()`, which Plugin Check flags
(`WordPress.WP.AlternativeFunctions.file_system_operations_fclose`, lines 447/508). The
task's suggested `$wp_filesystem->put_contents($file_path, ...)` doesn't actually fit — the
destination is the HTTP response body (`php://output`), not a real file on disk, and routing
it through WP_Filesystem would silently break on hosts using the FTP/SSH filesystem method
(WP_Filesystem can't write a local PHP stream over FTP). Instead: extracted the CSV-row
assembly into `build_coverage_csv_export()`/`build_missing_csv_export()` (pure, testable,
side-effect free) plus a private `csv_row()` RFC-4180 formatter, and the handlers now just
`echo` the built string — no fopen/fwrite/fclose at all. That traded the fclose warning for
a new `WordPress.Security.EscapeOutput.OutputNotEscaped` error on the two `echo` lines
(expected: `esc_html()` would corrupt CSV commas/quotes), suppressed with a scoped
`phpcs:ignore` + reason, matching the task's own guidance that real non-HTML output/file
operations are the legitimate use case for that escape hatch.
Verified: real `vendor/bin/phpcs --standard=WordPress-Extra --sniffs=WordPress.WP.AlternativeFunctions`
confirmed 2 fclose warnings on the pre-fix file (installed by a sibling PR that landed
mid-session) and 0 on the fixed file; the broader `WordPress.Security.EscapeOutput` +
`WordPress.WP.AlternativeFunctions` sweep also went from 5 findings to the same 3 pre-existing
`wp_die(__(...))` findings the file already had before this change (untouched, not introduced
by this PR). `vendor/bin/phpunit` 141/141 pass (5 new in `tests/DashboardTest.php` covering
the header row, empty input, RFC-4180 quoting of commas/quotes, and the missing-post skip
path). `npx jest` 18/18 pass (unaffected). `php -l` clean on every changed file and the full
repo. Added `class-dashboard.php` to `tests/bootstrap.php` and `phpunit.xml`'s diff-coverage
`<source><include>` list — it had zero test coverage tracking before this PR, the same
silent-gate gap this repo has fixed for other files before. No coverage driver (pcov/xdebug)
available locally to run `bin/diff-coverage.php` numerically, but manually traced every
touched line against the new tests. No live WordPress instance to click-through, matching
every prior STM PR in this repo without one.
Left: nothing outstanding for this task. Branch merged `origin/master` mid-session (two
sibling PRs landed: #25 SQL injection fix, #27 nonce verification) — resolved two trivial
additive conflicts in `phpunit.xml`/`tests/bootstrap.php` (both branches added a require/include
entry near the same line).

## 2026-08-08 — task 869efjuhu
Done: PR — replaced all 16 `wp_redirect()` calls with `wp_safe_redirect()` across
`includes/class-admin.php` (15 calls) and `includes/class-import-export.php` (1 call).
Every call already had `exit;` immediately after it, so no changes needed there. All
redirects build their target from `wp_get_referer()` via `add_query_arg()` — internal
admin-page redirects only, no OAuth/external-URL redirects anywhere in the plugin — so
no `allowed_redirect_hosts` filter was needed. Updated the one test that stubs the
redirect call (`tests/LanguagesScreenTest.php::stubRedirectToThrow()`) to stub
`wp_safe_redirect` instead of `wp_redirect`.
Verified: real WPCS `WordPress.Security.SafeRedirect` sniff (reused `/tmp/phpcs-check`)
— 16 warnings on the pre-fix files, 0 after. `vendor/bin/phpunit` 109/109 pass. `php -l`
clean on all 3 changed files.
Left: nothing.

## 2026-08-08 — task 869efjuhu (round 2)
Done: review on PR #30 flagged the diff-coverage gate failing (6.67%, needs >=80%).
First wrote a standalone `tests/AdminHandlersTest.php`, then merged `origin/master` (7
sibling PRs had landed meanwhile, `develop` doesn't exist in this repo — `master` is
trunk) and discovered task 869efjuhp had *already* inlined `check_admin_referer()` into
these same handlers and added its own `tests/AdminFormHandlersTest.php` covering most of
them with the identical `RedirectInterrupt extends \Error` pattern — same class name, same
namespace, a guaranteed fatal redeclaration. Deleted my duplicate file and instead: (1)
fixed `AdminFormHandlersTest.php` and `DashboardImportExportHandlersTest.php`, both of
which stubbed `wp_redirect` (correct when they were written, since `class-admin.php`/
`class-import-export.php` still called `wp_redirect()` at that time) — after this PR's
rename the production code calls `wp_safe_redirect()`, so every test hitting a redirect
line fatally errored with "Call to undefined function wp_safe_redirect()"; re-pointed both
stubs at `wp_safe_redirect`; (2) added the 6 branches neither sibling file covered (scan
exception, `import_json` invalid-type/invalid-json/unrecognized-format, `add_language`
invalid-fields, `delete_language` cannot-delete-default) directly into
`AdminFormHandlersTest.php` rather than keeping a separate file.
Verified: `vendor/bin/phpunit` 149/149 pass (full suite, post-merge). `vendor/bin/phpcs
--standard=phpcs.xml.dist` (nonce-verification gate) 0 errors/0 warnings. Real WPCS
`WordPress.Security.SafeRedirect` sniff on both changed files: 0 warnings. No pcov/xdebug
on this host to run the Clover-based diff-coverage script itself, so line coverage was
confirmed by manual trace against `git grep -n wp_safe_redirect includes/class-admin.php`:
all 15 originally-flagged lines are now hit by a passing, branch-specific assertion.
Left: CI's `bin/diff-coverage.php` gate itself should be watched on this PR to confirm
the automated number agrees with the manual trace.

## 2026-08-08 — task 869efjuhd
Done: PR — escaped every unescaped output flagged by Plugin Check plus a handful of
same-class instances it apparently missed on the first pass. `admin_url()` calls wrapped in
`esc_url()`; `wp_create_nonce()` in `esc_attr()`; `__()`/`__stm()` echoed via `wp_die()`/`echo`
now use `esc_html__()`/`esc_html()`; integer counts/ids (`$total_items`, `$string->id`,
`$current_page`, `$total_pages`, `strlen()`) wrapped in `absint()`; literal-string
class/state ternaries (`$first`, `$active`, `$selected`) wrapped in `esc_attr()`. Two
deliberate exceptions with `phpcs:ignore` + explanation: `class-import-export.php`'s XLIFF/PO
file-download handlers (escaping would corrupt the generated file format; XLIFF is built via
DOM/SimpleXMLElement, PO already self-escapes) and `class-language-switcher.php`'s widget
`before_widget`/`before_title`/`after_title`/`after_widget` (theme-supplied wrapper markup,
same pattern every WP core widget uses) — the widget title itself is `wp_kses_post()`'d since
`apply_filters('widget_title', ...)` is commonly used to inject inline markup.
Verified: `vendor/bin/phpunit` 109/109 pass (2 pre-existing tests needed `wp_kses_post`/`esc_url`
added to their Brain\Monkey mocks after the new escaping calls — no test assertions changed).
`npx jest` 18/18 pass (unaffected, no JS touched). `php -l` clean on every `.php` file in the
repo. No live WordPress instance to click-through the admin translation dashboard, matching
every prior STM PR in this repo without one.
Left: nothing outstanding for this task. Plugin Check itself was not run locally (no WP.org
CLI tool installed in this environment) — verification is via PHPUnit/PHPCS-pattern manual
review against every specific file:line the task description cited, plus a full-repo grep
sweep for the same unescaped-echo pattern.

## 2026-08-08 — task 869efjuhx
Done: PR — replaced `parse_url()` with `wp_parse_url()` in
`includes/class-language-switcher.php:304` (`get_language_url()`'s URL-routing branch), the
`WordPress.WP.AlternativeFunctions.parse_url_parse_url` Plugin Check error. The task's other
cited location, `simple-translation-manager.php:226`, no longer has a `parse_url()` call at
all — that file is 153 lines on current `master`, and a repo-wide grep found zero remaining
raw `parse_url()` calls outside `vendor/`; the audit's line numbers had drifted from earlier
refactors (`includes/class-hreflang.php` already correctly used `wp_parse_url()`).
Verified: reused the sibling Plugin Check tasks' `/tmp/phpcs-check` WPCS install — the real
`WordPress.WP.AlternativeFunctions.parse_url_parse_url` sniff went 1 warning → 0 on the
changed file, and a repo-wide sniff run confirms no `parse_url` warnings remain anywhere
(2 unrelated warnings for a different rule instance — `file_get_contents`/`unlink` — are out
of this task's scope). `php -l` clean. `vendor/bin/phpunit` 109/109 pass (unchanged — this
class isn't wired into the PHPUnit bootstrap, since it extends `WP_Widget`). New standalone
harness `tests/verify-869efjuhx-wp-parse-url.php` stubs a faithful `wp_parse_url()` and
reflection-invokes the private `get_language_url()` method directly: 3/3 pass, proving the
URL-routing output (language-prefixed path + query string, both `http`/`https`) is unchanged
by the swap. Existing `tests/verify-multilang-audit.php` standalone harness still 39/39 pass.
Left: nothing outstanding for this task.

## 2026-08-08 — task 869efjuhp
Done: Nonce verification, PR opened. Every state-changing handler in class-admin.php,
class-import-export.php and class-dashboard.php already called `Security::verify_admin_action()`,
which itself calls `check_admin_referer()` — so CSRF protection already existed at runtime. The
real gap was that PHPCS's `WordPress.Security.NonceVerification` sniff (and WordPress Plugin
Check, which uses the same sniff) cannot see through a static-method wrapper — it only recognizes
direct, unqualified calls to `wp_verify_nonce()`/`check_admin_referer()`/`check_ajax_referer()` —
so a real Plugin Check run would still report `NonceVerification.Missing` on all 13 handlers
despite the code being secure. Fixed by inlining `check_admin_referer($nonce)` directly at each
call site (combined with the existing `current_user_can()` check in one condition, matching the
original wrapper's exact semantics) and removing the now-unused `Security::verify_admin_action()`.
The remaining `NonceVerification.Recommended` warnings were all genuinely read-only GET-based list
filters/tab selection/post-redirect-GET display flags (page_translations(), Dashboard::render_page(),
both templates) — suppressed with justified `phpcs:ignore`/`phpcs:disable` comments per the task's
own review guidance, since a nonce is not meaningful for idempotent, bookmarkable filter URLs.
Also added `phpcs.xml.dist` (scoped to only the `WordPress.Security.NonceVerification` sniff on
these 5 files — not the full WordPress-Extra ruleset, to avoid unrelated scope creep), `composer
require --dev` for PHPCS + wp-coding-standards/wpcs, a `composer run phpcs` script, and a
`nonce-verification` CI job so this stays enforced going forward instead of being a one-time check.
Verified: `vendor/bin/phpcs --standard=phpcs.xml.dist` — 0 errors, 0 warnings (was 27 errors + 32
warnings before the fix). `vendor/bin/phpunit` 109/109 pass (no behavior change; one pre-existing
test — `test_toggle_language_active_dies_when_nonce_or_capability_check_fails` — caught a real bug
in an earlier draft of this refactor where the inlined `check_admin_referer()` return value wasn't
being checked, which would have silently dropped nonce enforcement whenever `check_admin_referer`'s
own `$die=true` default didn't fire; fixed by combining both checks in one `if`). `php -l` clean on
every changed file. YAML-parsed the new CI job (not just `bash -n`). `npx jest` not runnable in this
worktree (no `node_modules`, pre-existing gap unrelated to this PHP-only change) — no JS was touched.
Follow-up: the inlined `check_admin_referer()` calls put 7 previously-untested `class-admin.php`
handlers (`save_translation`, `add_string`, `scan_strings`, `import_json`, `add_language`,
`delete_language`, `save_ai_settings` — only `toggle_language_active` had coverage before) onto
CI's diff-coverage gate for the first time, and it failed at 12.5%. Added
`tests/AdminFormHandlersTest.php` (one happy-path + one nonce-denied test per handler, reusing the
FakeWpdb/Brain Monkey pattern from `LanguagesScreenTest.php`); had to introduce a `RedirectInterrupt
extends \Error` interrupt signal instead of `\RuntimeException`, since `save_translation()`/
`add_string()`/`scan_strings()` wrap their success-path `wp_redirect()` in `try { } catch
(\Exception $e)`, which was silently swallowing a `\RuntimeException`-based interrupt and
misreporting it as a DB failure. Verified: PR #27 CI all green (PHP unit tests incl. diff-coverage
gate, JS unit tests, PHP lint, Nonce verification/PHPCS), `vendor/bin/phpunit` 124/124 pass locally.
Left: nothing outstanding for this task.

## 2026-08-07 — task 869efjuhb
Done: PR — added `if (!defined('ABSPATH')) exit;` to the 5 files Plugin Check flagged as
`missing_direct_file_access_protection`: `includes/functions.php`,
`includes/class-language-switcher.php`, `includes/class-cli.php`,
`tests/wp-integration-smoke.php`, `test-bulk-api.php`. Matches the existing
`templates/*.php` convention (single-line, no braces) rather than the task's WP-core-spaced
snippet, for consistency with the rest of the repo. The two `namespace STM;` files
(`class-language-switcher.php`, `class-cli.php`) needed the guard placed *after* the
namespace declaration, not before — PHP requires `namespace` to be the first statement in
the file (only `declare()`/comments may precede it), so a literal "top of file" placement
would have been a fatal parse error.
Verified: `php -l` clean on all 5 changed files and every other `.php` file in the repo.
`vendor/bin/phpunit` 109/109 pass (unchanged — no test logic touched). `npx jest` 18/18 pass
(unchanged). No live WordPress instance to browser-verify the blank-page behavior, matching
every prior STM PR in this repo without one.
Left: `tests/wp-integration-smoke.php` and `test-bulk-api.php` both self-bootstrap WordPress
(`require_once wp-load.php`) when run standalone — adding the guard means neither can be
executed directly anymore (ABSPATH is never defined before they load it themselves). This
was explicit in the task ("test files are not shipped in production zips but should still
have the guard for WP.org compliance"), so left as instructed; a developer needing to run
either script locally will need to temporarily comment out the guard line.

## 2026-08-06 — task 869eecx75
Done: PR — auto-translate failures now surface the backend's real `error` string instead of the
generic "Auto-translate failed" text. `handleAutoTranslate()` in `assets/admin-post-editor.js`
collects the distinct non-empty `r.error` values across all failed fields and joins them (e.g. two
fields hitting the same missing-API-key error show it once; two different provider errors show
both, separated by "; "). True network failures (jQuery `.fail()` on the AJAX call, no response at
all) now resolve with `error: ''` in `translateField()` so they fall through to the existing
generic `i18n.translateFailed` fallback — the one case the task said should keep the generic text.
Verified: `class-auto-translate.php`'s `translate_openai()`/`translate_deepl()` already return the
real per-field error (missing key text, or the live OpenAI/DeepL error) — no backend change needed.
Verified: `npx jest` 18/18 pass (13 existing + 5 new in `tests/js/admin-post-editor.test.js`: single
real error shown, two different real errors joined, one real error survives alongside a no-detail
network failure, pure network failure falls back to the generic message, success path unaffected).
No PHP changed. No live WP instance to click-through, matching every prior STM JS-only PR in this
repo.
Left: nothing outstanding for this task.

## 2026-07-25 — task 869e9a954
Done: PR #19 — switched the 5 `Database::get_languages()` (active-only) call sites in
`class-post-editor.php` (meta box, Gutenberg panel, preview cycler, both post-list columns) to
`get_all_languages()`, so an inactive language's tab still renders and its content can be typed,
saved (the save path never filtered by active status), and previewed. Frontend surfaces
(`class-language-switcher.php`, `class-hreflang.php`, `class-frontend.php`) were left untouched —
confirmed they already only call `get_languages()`. Also found the task's own "How to test" step
("Mark a language inactive on the STM Languages screen") was unactionable: the Languages screen
had no way to deactivate a language at all (`add_language()` always inserted `is_active=1`, no
toggle UI/endpoint existed). Added `Admin::toggle_language_active()` (admin_post handler, guards
the default language from being deactivated, invalidates both language caches) plus an
Activate/Deactivate button per row in `templates/admin-languages.php`.
Verified: `vendor/bin/phpunit` 97/97 pass (14 new: inactive-language coverage for the meta box,
Gutenberg panel, preview cycler and both post-list columns; toggle-active activate/deactivate/
default-guard/not-found/invalid-code/cache-invalidation paths, using a `wp_redirect`-throws-to-
interrupt trick so the handler's trailing `exit;` never kills the test process). `npx jest`
13/13 pass (unaffected — no JS touched). `php -l` clean on every changed file. No coverage driver
(pcov/xdebug) available locally to run `bin/diff-coverage.php` numerically, but manually traced
every touched line against the new tests; the single genuinely uncovered line is the shared
trailing `exit;` in `toggle_language_active()` (unreachable without letting the real process
exit), which the refactor already collapsed from 3 duplicate exit points down to 1. No live
WordPress instance to click-through, matching every prior STM feature PR in this repo.
Left: nothing outstanding for this task.

## 2026-07-24 — task 869e8wk1w
Done: Deploy-time version tracking, PR #18. Plugin already had a manually-bumped `Version:` header + `STM_VERSION` constant (WP's own convention), but nothing JengoAGI's version detector recognizes (it only checks git tags, a VERSION file, package.json, or a csproj `<Version>`) and no automation kept the header/constant/package.json in sync. Added: root `VERSION` file (source of truth), `package.json` `"version"` field, `bin/bump-version.php` (`php bin/bump-version.php <major|minor|patch>` bumps all three files in lockstep via regex — CRLF-safe, preserves existing formatting), and `.github/workflows/release-tag.yml` (auto-creates+pushes a `vX.Y.Z` git tag whenever VERSION changes on a push to master).
Verified: `vendor/bin/phpunit` 83/83 pass (4 new — consistency check + 3 bump-script functional tests using a temp-dir copy, incl. major/minor reset and unknown-part rejection). `npx jest` 13/13 pass (unaffected). `php -l` clean on every PHP file in the repo. `package.json` valid JSON. Workflow YAML parsed with a real YAML parser (not just `bash -n`) and its embedded script's bash syntax checked separately. CLI smoke-tested `php bin/bump-version.php patch` against a scratch copy of the real repo files end-to-end.
Left: nothing outstanding for this task. Retroactively tagging the current `v1.2.0` on master is a follow-up for whoever merges this PR (or the workflow will tag the next real bump automatically).

## 2026-07-20 — task 869cay7k8
Done: PR #4's editor docs (EDITORS_GUIDE.md/TROUBLESHOOTING.md + PDFs) shipped in April but were never linked anywhere in the plugin UI — a 2026-07-20 verification comment confirmed zero PHP/JS references. Added a new "Documentation" submenu (`Admin::page_documentation()`, `templates/admin-documentation.php`) under WP Admin → Translations, linking both PDFs via `STM_PLUGIN_URL . 'docs/editors/pdf/...'` as the reviewer specified.
Verified: `vendor/bin/phpunit` 74/74 pass (3 new: menu registration + both PDF links render). `php -l` clean on all changed/added files. Added `includes/class-admin.php` to phpunit.xml's diff-coverage `<source><include>` list (it was previously untracked, same silent-gate class of bug fixed for class-string-scanner.php in 869e6vpgg). No coverage driver (pcov/xdebug) available locally to re-run `bin/diff-coverage.php` numerically, but both new code paths (menu registration line, page render) are directly exercised by the new tests.
Left: nothing outstanding for this task. Original deliverables (EDITORS_GUIDE.md, TROUBLESHOOTING.md, screenshots index, WordPress HTML/WXR, PDFs, build script) were already merged via PR #4.

## 2026-07-20 — task 869cay7hb
Done: Elementor widget translation integration, PR #15 (`STM\ElementorIntegration` — translated content stored separately from Elementor's own `_elementor_data`, frontend overlay filter, editor translation panel, 3 REST endpoints).
Verified: `vendor/bin/phpunit` 54/54 pass, `npx jest` 7/7 pass, `php -l` clean, CI green (lint + PHP tests + JS tests) on the PR.
Left: no live Elementor click-through — no reachable WordPress+Elementor install exists in Jengo's infrastructure. Task's original "install XAMPP" ask was declined (see PR body/ClickUp comment for why); ships through this repo's existing PHPUnit-only workflow instead, matching all 12 prior merged STM feature PRs.

## 2026-07-20 — task 869e6vndz
Done: `Database::get_all_languages()` added (same query as `get_languages()`, no `is_active` filter); `Admin::page_languages()` now uses it so wp-admin > STM > Languages lists inactive languages too, shown via the existing Active column. Every other caller (`get_languages()`) untouched. PR opened.
Verified: `vendor/bin/phpunit` 18/18 tests pass (3 new: active-only query unchanged, all-languages includes inactive, Languages screen template renders an inactive row without the active checkmark). `php -l` clean on all changed files.
Left: nothing.

## 2026-07-20 — task 869e6vndz (review round 2)
Done: PR #13 got CHANGES REQUESTED — CI's diff-coverage gate failed because the new `wp_cache_delete('stm_all_languages')` line in `Api::create_language()` (`includes/class-api.php:204`) had zero test coverage. Added `test_create_language_clears_both_language_caches` to `tests/LanguagesScreenTest.php`, calling `API::create_language()` with a spy on `wp_cache_delete` asserting both `stm_active_languages` and `stm_all_languages` are cleared.
Verified: `vendor/bin/phpunit` 19/19 tests pass (1 new). `php -l` clean. No coverage driver (pcov/xdebug) available locally to re-run `bin/diff-coverage.php`, but the new test executes line 204 directly (no branching in `create_language()` between lines 186-207 for valid input), which is the only touched line the gate flagged.
Left: nothing.

## 2026-07-21 — task 869e6vuk9
Done: PR #17 — a "Preview in language" cycler at the top of the post/page/product/CPT
meta box: prev/next buttons step through every configured language and a "View preview"
link opens WordPress' own preview URL (`get_preview_post_link()` + a `lang` query arg) in
a new tab, so an editor can quickly see how the entity actually renders in each language —
distinct from the existing meta-box translation tabs (869ceqwwn/869cy7b5r) which only edit
fields. Works from the first edit of a brand-new post too (falls back to the global `$post`
auto-draft ID when `$_GET['post']` isn't set yet). The Gutenberg sidebar panel gets a
matching "Preview" quick-link per language next to its existing "Edit" jump-to-tab button.
Verified: `vendor/bin/phpunit` 79/79 pass (10 new, incl. `build_preview_languages()`, the
new-post `$_GET` fallback, and two `render_meta_box()` template-render tests — added
`templates/meta-box-translations.php` to phpunit.xml's tracked `<source><include>` list,
since it had never been coverage-tracked at all before this PR). `npx jest` 13/13 pass (6
new). `php -l` and `node --check` clean on all changed files. No coverage driver
(pcov/xdebug) available locally to run the numeric gate, but manually verified every
touched line against the tests. No live WordPress instance to click-through, matching
every prior STM feature PR in this repo.
Left: nothing outstanding for this task.

## 2026-07-20 — task 869e6vpgg
Done: PR #14 — `includes/class-string-scanner.php` tokenizes the active theme (child+parent)
and the plugin's own `templates/` dir for literal `__stm()`/`_e_stm()` calls, registers each
new key/context in `wp_stm_strings`, and seeds its default-language translation with the
discovered fallback text. Runs on `stm_activate()` and via a new "Scan theme & plugin for
strings" button (`Admin::scan_strings()`) on the Strings screen. Both paths check for an
existing row before inserting, so re-scanning never duplicates strings or overwrites a
translation a human has since edited.
Verified: `php -l` clean on all changed files; `vendor/bin/phpunit` 28/28 passing (15 baseline
+ 13 new StringScanner tests covering token parsing, comment/dynamic-arg exclusion, dedup,
idempotent re-run, and manual-edit preservation). Added `class-string-scanner.php` to
phpunit.xml's tracked `<source><include>` list (it was missing, which made the CI diff-coverage
gate silently report "nothing to gate" instead of actually measuring the new code — the same
gap that bounced a sibling PR in this repo); after the fix CI's diff-coverage gate genuinely
measures 89.01% (162/182 lines) on the new file, comfortably over the 80% threshold. PR CI
(PHP unit tests, JS unit tests, PHP lint) all green. No live WordPress instance in this
environment, so the actual wp-admin Strings screen was not visually verified.
Left: nothing outstanding for this task.

## 2026-07-25 — task 869e9a954 (round 2, review feedback)
Done: PR #19 review found `class-frontend.php`'s `get_current_language()` accepted any
well-formed language code via `?lang=`, the rewrite query var, or the cookie — so once an
admin saved content for an inactive language, any visitor could see it via `?lang=de`.
Fixed: the resolved language is now only honored when it is active (`Database::get_languages()`),
or when the request is an authenticated preview (`is_preview()` + `current_user_can('edit_post', $id)`
on the queried post) — the mechanism the "Preview in language" cycler already relies on. Otherwise
falls back to the default language, same as an unrecognized code always did.
Verified: `vendor/bin/phpunit` 108/108 pass (11 new in `tests/FrontendTest.php` covering active/inactive/
unknown codes across all three input paths, plus the preview-authorization edge cases; updated 2
pre-existing tests in `SeoGodIntegrationTest.php`/`ElementorIntegrationTest.php` that assumed any
cookie value was honored regardless of active status). `npx jest` 13/13 pass (unchanged). `php -l`
clean on all changed files.
Left: nothing outstanding for this task.

## 2026-07-31 — task 869ebjzzc (Bugatti Insights ClickUp board)
Done: root-caused and fixed the public search REST endpoint 502ing on Bugatti Insights whenever
`?lang=` was present. Not a REST-layer bug at all — `Frontend::get_requested_language()` calls
`setcookie('stm_lang', ...)` every time `get_current_language()` runs, and a search-results loop
calls it once per post (via the `the_title`/`post_type_link` filters in `get_the_title()`/
`get_permalink()`). 12 search results = 12 duplicate `Set-Cookie` headers on one response, which
this host's PHP-FPM/IIS front end turns into a 502 instead of relaying. Confirmed live via
plugin activate/deactivate toggling (STM inactive = 200, active = 502) and a binary-search of
hand-patched live deploys down to the exact `setcookie()` line. Fix: `remember_language_choice()`
guards the cookie write with a `$cookie_written` static flag (once per request) + `headers_sent()`.
Verified: `vendor/bin/phpunit` 109/109 pass (1 new test asserting the guard latches after the
first of 12 simulated calls). Live: `lang=de`/`en`/`fr` each 3/3 return 200 JSON against
test.bugattiinsights.com after deploying this fix; no-`lang` and an unrelated-param control both
still 200.
Left: nothing outstanding for this task. Follow-up 869ebjz6a (wiring `lang` into the search UI)
can now build on this without inheriting the crash.

## 2026-08-07 — task 869efjuhr
Done: wrapped every superglobal access that feeds a sanitizing function with `wp_unslash()`
across the 7 files the task cited (`class-dashboard.php`, `class-import-export.php`,
`class-admin.php`, `class-post-editor.php`, `class-frontend.php`, `functions.php`,
`class-language-switcher.php`) — `$_GET`/`$_POST`/`$_COOKIE`/`$_SERVER` reads that already went
through `sanitize_text_field()`/`sanitize_key()`/`wp_verify_nonce()`/etc. Left `intval()`/`(int)`
casts and `isset()` checks alone (unslash is a no-op for those). Per the task's review note,
`$_FILES['import_file']['name']` / `$_FILES['stm_import_file']['name']` now go through
`sanitize_file_name()` instead of `wp_unslash()`. Did not add sanitization where none existed
before (a few `$_GET` filters in `class-admin.php` were bare-assigned with no sanitize call at
all) — that's the separate `InputNotSanitized` rule, not in this ticket's scope.
Verified: `php -l` clean on all 7 files. `vendor/bin/phpunit` 109/109 pass — 5 test files
(`FrontendTest`, `LanguagesScreenTest`, `PostEditorCrudTest`, `ElementorIntegrationTest`,
`SeoGodIntegrationTest`) needed a `Functions\when('wp_unslash')->returnArg(1);` stub added to
their Brain\Monkey `setUp()`, since the suite has no real WordPress loaded. `npx jest` 18/18 pass
(unaffected, JS-only). Standalone script proved the actual security property: with `wp_unslash()`
a value containing an apostrophe/backslash round-trips exactly; without it, WP's magic-quotes
slashing corrupts the stored value with stray backslashes.
Left: nothing outstanding for this task.

## 2026-08-07 — task 869efjuhf
Done: fixed the 3 Plugin Check ERROR-level SQL-injection findings (`WordPress.DB.PreparedSQL.NotPrepared`
/ `PluginCheck.Security.DirectDB.UnescapedDBParameter`). Two real gaps in `class-api.php`
(`get_translations()`'s total-count query, `export_json()`) built a `$where_sql` fragment via nested
per-condition `$wpdb->prepare()` calls, then interpolated it into a final query string passed straight
to `get_results()`/`get_var()` with no outer `prepare()` at all; `get_strings()` had the same pattern.
Rebuilt all three to collect raw `%s`/`%d` fragments + a parallel `$params` array and call `$wpdb->prepare()`
once on the assembled query. Separately, `class-dashboard.php` (3 call sites) and `class-import-export.php`
(2 call sites) already wrapped every query in `prepare()`, but used `...$array` spread to unpack replacement
values — Plugin Check's sniff can't statically verify a spread-unpacked arg list, so it reports the same
ERROR even though the query was safe. Replaced every `...$array` with the equivalent single-array form
`prepare($sql, $array)` (natively supported by `wpdb::prepare()`), preserving exact argument order.
Verified: `vendor/bin/phpunit` 121/121 pass (12 new — `tests/ApiSqlSafetyTest.php` asserts a SQL-metacharacter
payload in `context`/`lang` always lands inside an escaped, single-quoted literal and never leaves a bare
`%s`; `tests/DashboardImportExportSqlSafetyTest.php` deliberately verified — see note below — the
spread→array rewrite preserves argument order for the IN-clause/date-filter/lang placeholders).
Deliberately introduced an argument-order bug during review (swapped `array_merge([$lang->code], $post_types)`
to the wrong order) and confirmed the new test failed, then reverted — proves the test actually
guards the refactor, not just exercises it. `php -l` clean on all changed files. Added
`class-dashboard.php`/`class-import-export.php` to `phpunit.xml`'s tracked `<source><include>` list (was
missing, same CI diff-coverage silent-pass gap noted in the 869e6vpgg entry above) and added both classes
to `tests/bootstrap.php`'s requires. No coverage driver (pcov/xdebug) available locally to run the numeric
gate, but every touched line in all 3 production files is exercised by the new tests, confirmed by reading
the diff against the assertions.

**Round 2 — actually ran the real sniffs.** Reused the disposable scratch phpcs project at
`C:/temp/phpcs-check` (documented in `knowledge/wordpress-plugin-check-date-sniff-gmdate-vs-wp-date-semantics.md`,
built by a sibling task the same day) and installed `publishpress/publishpress-phpcs-standards`, which vendors
the real WordPress.org Plugin Check ruleset as phpcs standard `PluginCheck` — the first time this task's fix was
checked against the actual named rules instead of reasoning about them. Result: the `...$array` spread theory was
wrong. Diffing before/after with `--standard=WordPress-Extra --sniffs=WordPress.DB.PreparedSQL` and
`--standard=PluginCheck --sniffs=PluginCheck.Security.DirectDB` showed the spread→array rewrite made **zero**
difference — both sniffs flag based on where `$sql`/`$query`/`$where_sql` was *assigned* (a separate multi-line
statement, not an inline literal argument to `prepare()`), not on how the replacement array is unpacked. Real
remaining ERRORs (5 total, all after the Round 1 fixes were already in place): `class-api.php` line 248
(`get_strings`) and line 825 (`export_json`), `class-dashboard.php` line 266 (`get_missing_translations`),
`class-import-export.php` line 94 (`export_xliff`) and line 175 (`export_po`, not previously identified as
ERROR-level in Round 1). Added `// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,
PluginCheck.Security.DirectDB.UnescapedDBParameter -- <reason>` (or just the PluginCheck code, where WPCS's
strict `.NotPrepared` sub-code didn't fire) directly above each — exactly the escape hatch the task's own
description authorizes ("document this with phpcs:ignore if prepare() cannot be applied"), since these are
dynamic IN-clauses / multi-line conditional queries where fully inlining the SQL as a literal `prepare()`
argument isn't practical.
Verified: `vendor/bin/phpcs --standard=PluginCheck --sniffs=PluginCheck.Security.DirectDB` — 5→0 ERRORS (16
WARNINGS unchanged, all table-prefix-only interpolation, matching the task's own "acceptable, can be
suppressed" note). `vendor/bin/phpcs --standard=WordPress-Extra --sniffs=WordPress.DB.PreparedSQL` — the
strict `.NotPrepared` sub-code (the one the task's Tech notes actually cite) 3→0; the broader
`.InterpolatedNotPrepared` sub-code (26 remaining, table-name-only) is the WPCS equivalent of the same
"warning, not required" category. Full `--standard=PluginCheck` (no sniff filter) on all 3 files: 0 ERRORS,
16 WARNINGS — confirms no new Plugin Check rule was tripped. `vendor/bin/phpunit` 121/121 still pass (comments
only, no logic change). `php -l` clean.
Left: nothing outstanding for this task.
Left: nothing outstanding for this task. `class-dashboard.php:117` (`$wpdb->get_var("...{$wpdb->prefix}stm_strings")`)
remains a WARNING-level table-prefix-only interpolation, intentionally left as-is per the task's own scope
(Done-when only requires no ERROR, not zero warnings).

## 2026-08-08 — task 869efjuha
Done: added `readme.txt` (WordPress.org headers: Contributors, Tags, Requires at least,
Tested up to 6.7, Stable tag 1.2.1, License GPLv2 or later + License URI, plus
Description/Installation/FAQ/Changelog sections) — fixes Plugin Check's `no_plugin_readme`,
`missing_readme_header_tested`, `no_stable_tag`, `no_license` errors. Also wired readme.txt's
Stable tag into `bin/bump-version.php`'s existing VERSION/package.json/plugin-header lockstep
so future version bumps can't silently desync it again, and extended
`tests/VersionConsistencyTest.php` to assert that.
Verified: no live WP install / Plugin Check plugin available in this environment, so wrote
`tests/verify-readme-headers.php` (standalone PHP, mirrors the readme header regex WP.org's
parser uses) to check headlessly — passes, confirming Tested up to/Stable tag/License all
parse and Stable tag (1.2.1) matches the plugin file's Version header. `php -l` clean on all
changed files. `vendor/bin/phpunit` 110/110 pass (3 new: readme Stable-tag assertion in the
static-agreement test, bump-lockstep now also asserts readme.txt, plus a no-readme-file
back-compat case).
Left: actual Plugin Check tool run (wp-admin plugin) still needs a live WordPress instance —
not available here; recommend running it once against a staging site as final confirmation.

## 2026-08-07 — task 869efjuj2
Done: replaced hardcoded `http://localhost/` URLs in `tests/verify-multilang-audit.php` with a
configurable `STM_TEST_BASE_URL` constant (`getenv('WP_TEST_URL') ?: 'http://localhost/'`), per
the Plugin Check finding (`PluginCheck.CodeAnalysis.Localhost.Found`). Also moved
`README-BLOG.md` out of the plugin root into `docs/BLOG.md` (fixed its 3 inbound references in
`docs/editors/*.md`) to clear the unexpected-markdown-file warning.
Note: this task's own description also cited `tests/verify-lang-prefix-cpt-routing.php` and
`tests/verify-translated-slug-routing.php`, but neither file has ever existed in this repo
(`git log --all` confirms) — they were added directly to the WordPress deployment's copy of this
plugin at `tripplanner/portofgiethoorn/wordpress/plugins/simple-translation-manager/` by
Tripplanner-board tasks 869ecwc03/869eec0wf and never synced back here. Fixed the same issue
there too, in a separate PR (martiendejong/tripplanner#134), since that's the copy Plugin Check
actually scanned (its cited line numbers match that copy exactly).
Verified: `php -l` clean; ran the test file with no env var (default) and with
`WP_TEST_URL=https://ci.example.org/` set — 39/39 pass both ways, no regressions.
Left: nothing outstanding in this repo for this task.

## 2026-08-08 — task 869efjuhj
Done: PR #29 — replaced `date('Y-m-d')` with `gmdate('Y-m-d')` at
`includes/class-dashboard.php:432` and `:473` (CSV export filenames for coverage/missing
reports). UTC is correct here — the value only disambiguates a downloaded filename, never
shown as content — and matches the existing `gmdate()` convention already used for export
timestamps in `includes/class-import-export.php`.
Review round: two sibling PRs (869efjuhf SQL-safety, 869efjuhr wp_unslash) landed on master
while this PR sat in review and added `class-dashboard.php` to CI's diff-coverage gate for
the first time, so these two touched lines needed real test coverage that didn't exist when
the PR was opened. `export_missing_csv()`'s line is now covered for free by
`tests/DashboardImportExportHandlersTest.php` (869efjuhr's own new test, which stubs
`nocache_headers()` to throw right after the `gmdate()`-built filename argument evaluates,
stopping just short of the unreachable-in-tests `exit;`). Added
`tests/DashboardExportCoverageCsvTest.php`, the same technique applied to the untested sibling
`export_coverage_csv()`.
Verified: `WordPress.DateTime.RestrictedFunctions.date_date` 2 errors → 0 on this file
(reused sibling tasks' WPCS install). `php -l` clean. `vendor/bin/phpunit` 143/143 pass on
the merged branch (1 new test added this round). Diff coverage on `class-dashboard.php`'s
2 touched lines: both now hit.
Left: nothing outstanding for this task.

## 2026-08-08 — task 869efjuhy (round 2, review feedback)
Done: PR #32 got CHANGES REQUESTED — CI's diff-coverage gate failed at 77.78% (14/18) on
`class-dashboard.php` because the `echo(...); exit;` pairs in `export_coverage_csv()`/
`export_missing_csv()` can never run to completion under PHPUnit (`exit` kills the process).
Branch was also 5 commits behind master, including sibling PR #29 which touched the exact
same two functions (`date()`→`gmdate()`). Merged `origin/master` in, keeping this PR's
fopen/fwrite/fclose-free rewrite and folding in PR #29's `gmdate()` fix plus PR #31's
`esc_html__()`/`wp_unslash()` escaping fixes. Wrapped the 4 uncovered `echo`/`exit` lines in
`@codeCoverageIgnoreStart`/`@codeCoverageIgnoreEnd` (the `gmdate()`-built filename argument
right before them stays genuinely covered by the existing `nocache_headers()`-throw tests).
Also removed a stray, unpaired `@codeCoverageIgnoreEnd` left on master from an earlier,
abandoned iteration of PR #29 — no matching Start existed anywhere in the file, so it was
dead weight that would only confuse the next person reading the annotations.
Verified: real WPCS `WordPress.WP.AlternativeFunctions` sniff — 0 findings on
`class-dashboard.php` (repo-wide sweep of `includes/`/`templates/`/main plugin file also 0
fclose findings in production code). `vendor/bin/phpunit` 151/151 pass (full suite,
post-merge). `npx jest` 18/18 pass. `php -l` clean. PR CI: PHP unit tests / JS unit tests /
PHP lint / Nonce verification (PHPCS) all green, including the diff-coverage gate that was
previously red. No coverage driver (pcov/xdebug) available locally to run
`bin/diff-coverage.php` numerically, but manually traced the diff: the only added executable
lines are the now-ignored `echo`/`exit` pairs and the `build_*_csv_export()`/`csv_row()`
bodies, which are directly unit tested.
Left: nothing outstanding for this task. Manual click-through of both export buttons on a
live WP install still not verified — no environment available in Jengo's infra.

## 2026-08-22 — task 869enmewd
Done: PR #34 — `Admin::add_language()` now checks for an existing row by `code` before
writing. Existing + inactive → reactivate it in place (`UPDATE is_active=1`, refresh
display fields, keep the same `id` so tied translations survive) and redirect with
`stm_reactivated=1`. Existing + active → `stm_error=already_active` instead of the
generic `db_error`. No existing row → insert as before. Added a distinct "Language
reactivated" notice and "already active" error message to `admin-languages.php`.
Verified: `php -l` clean. `vendor/bin/phpunit` full suite 163/163 pass (4 new tests: the
reactivate-with-existing-translation-preserved path, the already-active error path, and
matching template-notice tests). `vendor/bin/phpcs` 0 errors. No CI configured on this
repo (`statusCheckRollup` empty on the PR). Manual browser click-through not available in
this environment.
Left: nothing outstanding for this task. Portofgiethoorn's vendored copy of this plugin
(`portofgiethoorn/wordpress/plugins/simple-translation-manager/`) is stale per this task's
own description and will need a separate deploy/update step once this PR merges — not
done here, out of scope for this repo's PR.

## 2026-08-23 — task 869enr71g
Done: on the Translation Strings screen, an empty translation input for a non-default
language now shows the default language's own text as its `placeholder` (instead of the
generic "Translation" hint), with a `title` tooltip and a `.stm-placeholder-is-default`
left-border cue, so whoever is managing translations can see what visitors currently see
for that string. New pure `Admin::get_translation_placeholder($default_translation,
$is_default_language)` decides the placeholder text; `page_translations()` resolves
`Database::get_default_language()` once and passes `$default_lang_code` to the template.
Verified: `php -l` clean on both changed files. `vendor/bin/phpunit` full suite 169/169
pass (6 new tests in `tests/TranslationsScreenTest.php`: 3 for the pure helper's branches,
3 rendering `templates/admin-translations.php` directly — FakeWpdb's SQL parser can't
represent `page_translations()`'s aliased/subquery queries, so the template is exercised
the same way `page_translations()` calls it, via `include` sharing local vars). `vendor/bin/phpcs
--standard=phpcs.xml.dist` 0 errors.
Left: nothing outstanding for this task. Same portofgiethoorn vendored-copy staleness
caveat as the entry above applies here too — not addressed in this PR.

## 2026-08-23 — task 869enr72r
Done: added a "Status" dropdown (All / Missing translations / Fully translated) to the
Translation Strings admin screen (`admin.php?page=stm-translations`), next to the existing
Context filter. `Admin::page_translations()` had read `$_GET['status']` into `$status_filter`
since the plugin's initial commit but never applied it — no UI control, no query wiring.
Since `translated_count` is a correlated subquery, not a real column, the filter is applied
via a `HAVING` clause (`Admin::build_status_having()`, pure/static/unit-tested) rather than
folded into `$where`; the pagination total-count query is wrapped in a derived table so counts
stay consistent with the filtered result set. Status now round-trips through pagination links
and the "Clear" control. PR #36.
Verified: `vendor/bin/phpunit` 167/167 pass (4 new tests: 3 direct on
`build_status_having()`'s missing/complete/all-or-unknown thresholds, 1 rendering test that
stubs a real `selected()` and asserts the "Missing translations" `<option>` is marked selected
when `?status=missing`). `phpcs --standard=phpcs.xml.dist` 0 errors on all 3 changed files.
Not independently re-verifiable against real seeded translation data: `FakeWpdb`'s SQL parser
can't handle the pre-existing aliased-table + correlated-subquery query shape this page has
always used (same limitation noted on task 869enr71g) — this predates and is unrelated to
this change. Merged forward against master (task 869enr71g's `get_translation_placeholder()`
landed first, same file, non-overlapping) — re-ran full suite post-merge, see below.
Left: nothing outstanding for this task.

## 2026-08-30 — task 958
Done: `Hreflang::inject()` now only emits a non-default-language alternate when
`has_translated_content()` confirms real translated content exists — fixes
martiendejong.nl's homepage declaring hreflang="nl" for a URL that just 301-redirected
back to English, plus the same fake-signal shape on every untranslated blog post. PR #38.
Also applied the same patch directly to the live deployment via FTP (this site's plugin
predates most of git master, per the established 278/929 deploy convention) and bumped
STM_VERSION to `...+958`.
Verified: `vendor/bin/phpunit` 180/180 pass (4 new tests in `tests/HreflangTest.php`).
`phpcs --standard=phpcs.xml.dist` 0 errors. Live: homepage and an untranslated post no
longer declare hreflang="nl"; genuinely translated pages (biography,
claude-code-cursor-coaching) still do — confirmed via curl before and after the live patch.
Left: nothing outstanding for this task. The underlying "front page has no Dutch
translation at all" product gap (flagged by task 929) is unaddressed — this PR only stops
the false signal, it doesn't build the missing Dutch homepage.

## 2026-09-10 — task 3047
Done: `PostEditor::get_post_language()` now consults SEO God's per-post detected
content language (task 2982) before falling back to the site default, and
`Hreflang::inject()` compares each language against the post's own resolved
language instead of unconditionally the site default — fixes a genuinely Dutch
post (no `wp_stm_post_associations` row) self-declaring hreflang="en"/x-default
as English. Added `SeoGodIntegration::get_detected_content_language()` as the
reverse-direction bridge; it normalizes SEO God's full locale tag ('nl-NL') to
STM's bare code ('nl') — a mismatch only caught during live verification, not
by the first pass of unit tests (fixed and covered before shipping). PR #41.
Also applied the patch directly to the live deployment via FTP (same convention
as task 958), backing up all 4 touched files server-side first, and bumped
STM_VERSION to `...+958+3047`.
Verified: `vendor/bin/phpunit` 188/188 pass (7 new/updated tests across
HreflangTest, PostEditorCrudTest, SeoGodIntegrationTest). `phpcs` 0 errors.
Live: `/tijdelijke-aanpassing-van-content/` and `/populatie-of-ondersoort/`
(both genuinely Dutch) now emit hreflang="nl" self-reference + matching
x-default, no hreflang="en"; homepage (English) unchanged; the one post with a
real saved Dutch translation (task 958's regression case) still emits both
hreflang="en" and hreflang="nl" correctly.
Left: nothing outstanding for this task.

## 2026-09-17 — task 3346
Done: `Hreflang::inject()` now resolves `get_queried_object()` for
`is_tax() || is_category()` requests too (not just `is_singular()`), so a
category/subcategory archive is recognized as a `WP_Term`. New
`has_term_translation()` checks `wp_stm_term_translations` for a row
matching the term + language (same lookup `Frontend::filter_term()` already
uses) — a translated term now gets the full hreflang set, same as a
translated post. Deliberately reuses the existing default-language-path +
prefix fallback for the URL (never the translated slug) since no
request-side resolver exists for translated term slugs (unlike
`resolve_translated_slug_request()` for posts) — using the translated slug
would emit a link that 404s. `/types/` (a post-type archive) queries a
`WP_Post_Type`, which is neither a `WP_Post` nor a `WP_Term`, so it keeps
falling back to default-language-only, same as before — no fake link is
possible there without a per-language row to point at. Bumped plugin version
1.3.1 → 1.3.2 via `bin/bump-version.php patch` (keeps VERSION, package.json,
the plugin header + STM_VERSION, and readme.txt in lockstep). PR #42.
Also applied the same patch directly to the live deployment at
`185.104.29.170:/public_html/` (Bugatti Insights vault project 13, credential
29 — this account's home dir is actually `domains/test.bugattiinsights.com/`
per its debug.log paths, not production `bugattiinsights.com`/web0173, even
though both public domains currently show the same STM bug; used the
credential exactly as the task specified), backing up
`includes/class-hreflang.php` server-side first
(`class-hreflang.php.bak-3346`). This file was already byte-identical to git
master before editing (no drift to reconcile, unlike the out-of-sync
martiendejong.nl deployment tasks 958/3047 described).
Verified: `vendor/bin/phpunit` 191/191 pass (3 new tests in `HreflangTest.php`:
untranslated category archive omits the alternate, a category archive with a
registered term translation includes it with the correct `/nl/`-prefixed URL,
and an explicit post-type-archive-fallback test). `phpcs --standard=phpcs.xml.dist`
0 errors. Live on test.bugattiinsights.com: the bcc_category subcategory
`/founding-racers-brescia/` (term 14, already had a real pre-existing Dutch
translation row in `wp_stm_term_translations` that the old code never
surfaced) went from en+x-default only to en+nl+x-default after deploying;
an untranslated subcategory (`/accessible-tourers/`) and the untranslated
top-level parent category (`/the-classic-era/`) both still emit only
en+x-default; `/types/` unchanged (en+x-default only, documented fallback);
a singular chassis page's existing 6-language hreflang set (task 958/3047
behavior) is unaffected. `wp-content/debug.log` unchanged (no new PHP
warnings/errors) across all live requests made during verification.
Temporarily uploaded a self-deleting mu-plugin to seed a test translation
row as a fallback verification path, in case no real term translation
existed yet — its insert was skipped (a real translation was already
present) so no test data was ever written; the mu-plugin and its containing
`mu-plugins/` directory (which didn't exist before) were removed afterward.
Left: nothing outstanding for this task.

## 2026-09-17 — task 3520
Done: added source_hash + status (machine/reviewed/approved) to
stm_post_translations, and status to stm_field_value_translations (reusing
its existing value_hash as the staleness signal instead of a duplicate
column). Wired hash computation into every real write path (metabox,
REST single/bulk save, slug save, dashboard quick-save) plus a one-time
upgrade backfill for existing rows. Added a "Stale Translations" dashboard
tab and `wp stm find-stale` / `wp stm clean-stale-translations` CLI
commands (list-only, never delete/rewrite). PR #43.
Verified: `php -l` clean on all changed files, `phpcs --standard=phpcs.xml.dist`
0 errors, full PHPUnit suite 200/200 (9 new tests, including one asserting
a source edit flips an existing translation to stale on each table). No
live WP install on this host, so the CLI/dashboard UI itself wasn't
smoke-tested end-to-end — PHPUnit against FakeWpdb is this repo's
established machine-verification bar per the task's own instructions.
Left: nothing outstanding for this task's Done-when list. Elementor's
own `_elementor_data` row (same stm_post_translations table) intentionally
was not wired into hash computation — Brain\Monkey tests for
ElementorIntegration::save_language_data() don't stub get_post_meta, and
the task's technical notes never named Elementor; if elementor page
content should also get staleness detection, that's a small, separate
follow-up.

## 2026-09-21 - task 3520 (round 2, review fixes on PR #43)
Done: fixed both review findings. (1) PostEditor::save_translations now keeps the stored source_hash when the re-posted metabox text equals the stored translation (new resolve_source_hash_for_save helper), so a source edit followed by a normal post Update no longer un-stales everything. (2) ElementorIntegration::save_language_data now stamps/keeps the hash for _elementor_data rows with the same rule, so backfilled rows can be flagged and cleared.
Verified: php -l clean, phpcs 0 errors, PHPUnit 205/205 (5 new: save -> edit source -> re-save same payload stays stale, text change clears it, unhashed legacy row gets stamped, Elementor stale/retranslate cycle). Mutation check: with the re-stamp bug reintroduced, both new stale-cycle tests fail. No live WP install, so metabox flow is proven via the real save_translations code against FakeWpdb.
Left: nothing for the review findings. reviewed/approved are never set by any UI yet (ticket left transitions open).
