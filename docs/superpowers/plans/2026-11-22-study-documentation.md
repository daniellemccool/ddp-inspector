# Study documentation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Regroup Setup into three logical steps with removable uploads, accept the study setup export from Next, and add a Study page showing what participants saw and what was collected.

**Architecture:** Three stacked stories, one branch and PR each: `main ← setup-regroup ← study-setup-upload ← study-page`. Setup's POST actions stay in `src/Instance.php`; the study setup gets its own `src/StudySetup.php` (ingest with `ZipArchive`, unpacked once into `config/study-setup/`); rich text goes through one allowlist sanitiser in `src/RichText.php`; packaged files are served by `public/asset.php`; the page is `public/study.php`.

**Tech Stack:** PHP 8.3+ (no framework, no JS, no build step), `php-zip`, bash test scripts, Ansible templates.

**Spec:** `docs/superpowers/specs/2026-11-21-study-documentation-design.md`. Read it first; this plan argues from it.

## Global Constraints

- No Python anywhere, including ad-hoc checks. No JavaScript.
- On screen: **study setup** (from Next), **donation flow** (from the script selector), **donations**. Never "flow builder"; never call the donations an "export".
- "Next" links to `https://next.eyra.co/`, "script selector" to `https://datadonation.eu/software/script-selector/`; both `target="_blank" rel="noopener noreferrer"`.
- An `instance.json` written by today's Setup keeps working unchanged; no migration.
- No real study's content in tracked files or kept screenshots. Fixtures are made up ("Example study").
- Uploads are untrusted: no path named by an upload is written or served; HTML from an upload is only ever output through `rich_html()`; everything else through `h()`.
- Markup and CSS stay within the D3I look as `public/assets/style.css` derives it (read its header comment); `tokens.css` and `fonts/` are never edited. Narrow desktop windows matter, phones do not.
- Commits: conventional prefixes (`feat(setup):`, `fix(refresh):`, `docs(ops):` …), one commit per task, each ending with `Co-Authored-By: Claude Fable 5.2 <noreply@anthropic.com>`. Stage named files only: `git status` shows untracked sandbox dotfiles (`.bashrc`, `.claude/` …) that must never be added.
- Test commands (all must pass before each commit): `php tests/run.php` (ends `0 failed`), and when provisioning changes: `provisioning/scripts/test-refresh-script.sh`, `provisioning/scripts/test-inspector-templates.sh`, `provisioning/scripts/tier1.sh`.
- Push and PR (from CLAUDE.md): `git -c credential.helper= -c 'credential.helper=!gh auth git-credential' push https://github.com/daniellemccool/ddp-inspector.git <branch>`, then `gh pr create --repo daniellemccool/ddp-inspector --base <branch below in the stack> --head <branch>`.

**Screenshot recipe** (used at the end of each story; server and browser must run in ONE command): write a config returning `['storage_root' => '<abs temp dir>', 'default_n' => 15, 'base_path' => '']`, then
`DDP_INSPECTOR_CONFIG=<abs config> php -S 127.0.0.1:8110 -t public & sleep 1; firefox --headless --no-remote --profile <temp profile dir> --window-size 1280,1600 --screenshot <out.png> http://127.0.0.1:8110/setup.php; kill %1`.
Seed the temp storage root by calling the handlers from a small PHP script (as `tests/SetupTest.php` does), not through the browser. Screenshots go in a temp directory, never the repo.

## Review Focus

1. **A zip made by Next itself** (streamed, sizes after the data) is accepted; tests build zips with `ZipArchive`, which does not reproduce that layout. Pinned by the real-file checks in Tasks 7 and 12.
2. **Unbalanced HTML** in consent or helpdesk text (an unclosed `<ul>` or `<strong>`) must not swallow or restyle the rest of the Study page. Pinned in Task 8.
3. **A study setup with nothing in it** (`about`, `consent`, `helpdesk` null, no tasks, no `ro-crate-metadata.json`) renders a page without PHP warnings. Pinned in Task 11.
4. **Wrong-typed fields** in an uploaded `next-metadata.json` (a title that is an array, `chapters` that is a string, `tasks` that is an object) never cause a fatal error. Pinned in Task 11.
5. **Uploading a second study setup with fewer files** leaves none of the first one's files behind. Pinned in Task 6.

---

# Story 1: `setup-regroup` (branch exists, holds the spec)

### Task 1: Name and daily check saved on their own; "configured" needs a source

**Files:**
- Modify: `src/Instance.php` (`inst_configured()`, `inst_handle_setup_post()`)
- Test: `tests/InstanceTest.php`, `tests/SetupTest.php`

**Interfaces:**
- Produces: POST actions `save_name` (field `study_name`) and `save_cadence` (field `cadence`, `daily` or absent). `save_source` no longer reads `study_name` or `cadence` from the post; it keeps the saved values. `inst_configured()` is true in storage mode only when `instance.json` has `source_mode` in `yoda`, `rd-link`, `local`.

- [ ] **Step 1: Write the failing tests.** In `tests/InstanceTest.php` after the existing "instance.json -> configured" check:

```php
inst_save(['study_name' => 'Pilot']);
eq(inst_configured(), false, 'instance.json without a source -> not configured');
inst_save($inst);
```

In `tests/SetupTest.php`, before the first `save_source` (the instance is still empty there):

```php
$r = inst_handle_setup_post(['action' => 'save_name', 'csrf' => csrf_token(), 'study_name' => '  Example study  '], []);
eq($r['flash'][0]['kind'], 'ok', 'study name saved on its own');
eq(inst_load()['study_name'], 'Example study', 'study name trimmed and stored');
eq(inst_configured(), false, 'a name alone does not make the instance configured');
```

After the first successful yoda `save_source`:

```php
eq(inst_load()['study_name'], 'Example study', 'saving the source keeps the study name');
$r = inst_handle_setup_post(['action' => 'save_cadence', 'csrf' => csrf_token(), 'cadence' => 'daily'], []);
eq(inst_load()['cadence'], 'daily', 'daily check saved on its own');
eq(inst_load()['source_mode'], 'yoda', 'saving the daily check keeps the source');
```

Change the existing "re-save … renamed" block: post `'study_name' => 'IGNORED'` and assert `inst_load()['study_name'] === 'Example study'` ("save_source ignores a posted study name") and `inst_load()['cadence'] === 'daily'` ("saving the source keeps the daily check"). At the end of the file add: `save_cadence` without `cadence` stores `'off'`.

- [ ] **Step 2: Run** `php tests/run.php`. Expected: the new checks FAIL.
- [ ] **Step 3: Implement.** `save_name` and `save_cadence` merge one key into `inst_load() ?? []` and `inst_save()` it. Flash texts: `Saved ✓`, `Saved ✓ — new donations are checked every day.`, `Saved ✓ — new donations are not checked automatically.` `save_source` builds `study_name` and `cadence` from the saved instance (`''` and `'off'` when absent); its success text becomes `Saved ✓ — now fetch your donations in step 03.`
- [ ] **Step 4: Run** `php tests/run.php`. Expected: `0 failed`. Two kinds of existing test may need adjusting: ones that posted `study_name`/`cadence` through `save_source` and asserted on them, and ones that write an `instance.json` without a `source_mode` and expect pages to render (give those a `source_mode`).
- [ ] **Step 5: Commit** `feat(setup): save the study name and the daily check on their own`.

### Task 2: Remove a donation flow

**Files:**
- Modify: `src/Flows.php`, `src/Instance.php`
- Test: `tests/FlowsTest.php`, `tests/SetupTest.php`

**Interfaces:**
- Produces: `flows_remove(string $flowsDir, string $slug): bool`; POST action `remove_flow` (field `slug`).

- [ ] **Step 1: Write the failing tests.** `tests/FlowsTest.php`, after the successful ingest into `$flowsDir`:

```php
eq(flows_remove($flowsDir, '../facebook'), false, 'unsafe slug refused');
eq(flows_remove($flowsDir, 'nosuch'), false, 'unknown flow refused');
eq(flows_remove($flowsDir, 'facebook'), true, 'flow removed');
check(!is_dir("$flowsDir/facebook"), 'flow directory gone');
```

`tests/SetupTest.php`, after the final page-render checks (which expect the TikTok flow listed) and before the config is restored:

```php
$r = inst_handle_setup_post(['action' => 'remove_flow', 'csrf' => csrf_token(), 'slug' => 'tiktok'], []);
eq($r['flash'][0]['kind'], 'ok', 'flow removal accepted');
check(str_contains($r['flash'][0]['text'], 'TikTok'), 'removal message names the platform');
eq(flows_load_all(), [], 'no flows left');
$r = inst_handle_setup_post(['action' => 'remove_flow', 'csrf' => csrf_token(), 'slug' => 'tiktok'], []);
eq($r['flash'][0]['kind'], 'error', 'removing a flow twice is an error');
```

- [ ] **Step 2: Run** `php tests/run.php`. Expected: FAIL (`flows_remove` undefined).
- [ ] **Step 3: Implement.** `flows_remove` accepts only a slug matching `/^[a-z0-9]+$/` whose directory exists; deletes its files, then the directory. Texts: `Removed the <platform title> flow — upload it again to restore it.` / `That flow is not here any more.` In `flows_ingest_upload`'s messages replace "flow builder" with "script selector" (three places).
- [ ] **Step 4: Run** `php tests/run.php`. Expected: `0 failed`.
- [ ] **Step 5: Commit** `feat(setup): remove an uploaded donation flow`.

### Task 3: Setup page in three steps

**Files:**
- Modify: `public/setup.php`, `public/assets/style.css`, `src/Instance.php:210,219,241`, `provisioning/roles/refresh/templates/ddp-refresh.sh.j2:405`
- Test: `tests/SetupTest.php`, `provisioning/scripts/test-refresh-script.sh`

**Interfaces:**
- Consumes: actions from Tasks 1 and 2.
- Produces: the step structure Story 2 adds a part to. CSS classes `.optional` (the mark) and `button.quiet` (Remove).

- [ ] **Step 1: Write the failing tests.** In `tests/SetupTest.php`'s final page render:

```php
$p1 = strpos($html, 'Describe your study'); $p2 = strpos($html, 'Connect your donations'); $p3 = strpos($html, '>03</span> Fetch');
check($p1 !== false && $p2 !== false && $p3 !== false && $p1 < $p2 && $p2 < $p3, 'three steps in order');
check(!str_contains($html, 'flow builder'), 'the page says script selector, not flow builder');
check(str_contains($html, 'href="https://datadonation.eu/software/script-selector/" target="_blank" rel="noopener noreferrer"'), 'script selector linked');
check(substr_count($html, 'class="optional"') >= 2, 'the step and the flows part are marked optional');
check(preg_match('~name="action" value="remove_flow">\s*<input type="hidden" name="slug" value="tiktok">~', $html) === 1, 'each flow has a Remove form');
check(preg_match('~value="save_name">.*name="study_name".*value="save_source">~s', $html) === 1, 'the name has its own form, before the source form');
check(preg_match('~value="save_source">(?:(?!</form>).)*name="(study_name|cadence)"~s', $html) === 0, 'the source form holds neither name nor daily check');
check(preg_match('~value="save_cadence">(?:(?!</form>).)*name="cadence"~s', $html) === 1, 'the daily check has its own form');
```

In `provisioning/scripts/test-refresh-script.sh`, a new case after 1b, in that script's own style: an `instance.json` holding only `{"study_name":"x"}` with the request flag set ends with `phase` = `error` and a message containing `Connect your donations`; the same instance with no flag exits 0 and leaves the status file unchanged.

- [ ] **Step 2: Run** `php tests/run.php` and `provisioning/scripts/test-refresh-script.sh`. Expected: the new checks FAIL.
- [ ] **Step 3: Rebuild `public/setup.php`** to spec §6. Exact copy:
  - `01 Describe your study` + optional mark. Under it: `Nothing in this step is needed to browse donations.`
  - Study name field with its own `Save` (action `save_name`).
  - `<h3>Donation flows</h3>` + `from the script selector` (linked) + optional mark. `What was collected: the tables and what each variable means. Upload the zip exactly as the script selector downloaded it, one per platform.` With no flow stored: `Without a flow, tables keep their raw names and have no descriptions.` Each stored flow: name, `N data tables`, a `Remove` form (`button.quiet`, no `go_mark()`).
  - `02 Connect your donations`: the three choices under `Where are your donations stored?`, the Analyses block, `Save` (action `save_source`). No name field, no daily-check box.
  - `03 Fetch`: `Check connection and fetch donations`; then the `Check for new donations automatically every day` box in its own form with a `Save` (`button.quiet`, action `save_cadence`); then status and Technical log as today.
- [ ] **Step 4: Reword the four messages** that say "step 2" or "study setup": the three in `Instance.php` name `“Connect your donations”` instead of "step 2"; the refresh script's default case becomes `Setup is not finished — open Settings and fill in 'Connect your donations'.` Update any test asserting the old strings.
- [ ] **Step 5: CSS** for `.optional`, `button.quiet` and the flow rows, and one sentence in `style.css`'s header comment saying how they are derived.
- [ ] **Step 6: Run** all four test commands. Expected: all pass.
- [ ] **Step 7: Commit** `feat(setup): regroup Setup into describe, connect and fetch`.

### Task 4: Story 1 check and PR

- [ ] **Step 1:** Screenshot `setup.php` at 1280 and 900 wide (recipe above) with one flow uploaded and with none; read both images; fix anything broken or off the look.
- [ ] **Step 2:** Push `setup-regroup`; open the PR against `main`, body summarising Tasks 1–3 and listing the test results.

---

# Story 2: `study-setup-upload` (branch from `setup-regroup`)

### Task 5: `php-zip`, and a real message for an over-size upload

**Files:**
- Modify: `provisioning/roles/inspector/tasks/main.yaml` (apt list), `provisioning/deploy-ddp-inspector.yaml` (new var), `provisioning/roles/inspector/templates/nginx-location.conf.j2`, `provisioning/roles/inspector/templates/99-ddp-inspector.ini.j2` (comment), `provisioning/scripts/test-inspector-templates.sh`, `provisioning/docs/OPERATIONS.md` §8, `src/Instance.php`, `public/setup.php`, `tests/FlowsTest.php` (comment)
- Test: `tests/SetupTest.php`, `provisioning/scripts/test-inspector-templates.sh`

**Interfaces:**
- Produces: `inst_post_too_large(array $server, array $post, array $files, string $postMax): ?string`. Ansible var `inspector_body_max: "128m"` (nginx only); `inspector_upload_max` stays `64m` (PHP).

- [ ] **Step 1: Write the failing tests.** `tests/SetupTest.php`:

```php
$big = ['REQUEST_METHOD' => 'POST', 'CONTENT_LENGTH' => (string)(70 * 1024 * 1024)];
check(str_contains((string)inst_post_too_large($big, [], [], '64M'), '64 MB'), 'a refused body is reported as too large, with the limit');
eq(inst_post_too_large($big, ['action' => 'x'], [], '64M'), null, 'a post that arrived is not too large');
eq(inst_post_too_large(['REQUEST_METHOD' => 'POST', 'CONTENT_LENGTH' => '100'], [], [], '64M'), null, 'a small empty post is not too large');
eq(inst_post_too_large(['REQUEST_METHOD' => 'GET'], [], [], '64M'), null, 'GET is never too large');
```

`test-inspector-templates.sh`: pass `-e "inspector_body_max=128m"`; the nginx check becomes `client_max_body_size 128m;`; the PHP ini checks stay `64m`.

- [ ] **Step 2: Run** both. Expected: FAIL.
- [ ] **Step 3: Implement.** `inst_post_too_large` returns `That file is larger than this workspace accepts (64 MB).` (limit derived from `$postMax`, PHP shorthand `K`/`M`/`G`) when the method is POST, `$post` and `$files` are empty and the declared length exceeds the limit. `setup.php` calls it with `$_SERVER, $_POST, $_FILES, ini_get('post_max_size')` before the handler and shows the text as an error flash. nginx uses `inspector_body_max`; add `php-zip` to the apt list.
- [ ] **Step 4: Docs.** OPERATIONS §8: `php-zip` is now the app's dependency (study setup upload), not test-only; the apt line already installs it. Same correction in the `tests/FlowsTest.php` comment.
- [ ] **Step 5: Run** all four test commands. Expected: all pass.
- [ ] **Step 6: Commit** `feat(provisioning): install php-zip and report an over-size upload plainly`.

### Task 6: Study setup ingest and storage

**Files:**
- Create: `src/StudySetup.php`, `tests/StudySetupTest.php`
- Modify: `src/bootstrap.php` (require), `src/Instance.php` (`inst_paths()` gains `'setup' => "$root/config/study-setup"`, null in dev mode), `tests/run.php` (add `StudySetupTest.php` to the list)

**Interfaces:**
- Produces:
  - `setup_asset_name_ok(string $name): bool`: `/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/`.
  - `setup_ingest_upload(string $zipPath, string $destDir, string $origName = ''): array{ok:bool, message:string, name:?string, tasks:?int}`
  - `setup_remove(string $destDir): bool` (true when something was removed)
  - `setup_load(): ?array{meta:array, crate:?array, warnings:list<string>, uploaded:array, assets:list<string>}`: null when `inst_paths()['setup']` is null or holds no `next-metadata.json`. `warnings` are the skipped paths from `export-warnings.json` with the top folder stripped; `assets` the stored basenames, sorted.
  - On disk under `$destDir`: `next-metadata.json`, `ro-crate-metadata.json` (if in the zip and decodable), `export-warnings.json` (same), `assets/<basename>`, `upload-meta.json` `{uploaded_at, zip_name}`.

- [ ] **Step 1: Write the failing tests** in `tests/StudySetupTest.php` (scratch storage root as `tests/SetupTest.php` does it; new test files go at the end of the list in `tests/run.php` and restore `$GLOBALS['__cfg']` when done). A helper `make_setup_zip(string $path, array $entries): void`, defined in `tests/run.php` because Tasks 7 and 11 use it too, writes `name => bytes` with `ZipArchive`. The made-up metadata:

```php
$meta = ['format_version' => 1, 'assignment' => ['id' => 7, 'name' => 'Example study'], 'language' => 'en',
    'branding' => ['title' => 'News on a platform', 'subtitle' => null, 'logo' => 'assets/logo.png', 'header_image' => null],
    'about' => null, 'consent' => '<div>I agree.</div>', 'helpdesk' => null, 'privacy_statement_pdf' => null,
    'workflow' => ['tasks' => [
        ['type' => 'document', 'title' => 'How to request', 'description' => null, 'filename' => 'guide.pdf', 'file' => 'assets/document-1.pdf'],
        ['type' => 'data_donation', 'title' => 'Donate', 'description' => 'Extracts things', 'filename' => 'dd-example.zip']]]];
```

Assertions:
  - well-formed zip (`example/next-metadata.json`, `example/ro-crate-metadata.json`, `example/assets/logo.png`, `example/assets/document-1.pdf`): `ok`, `name === 'Example study'`, `tasks === 2`, message contains `Example study`; `setup_load()` returns `assets === ['document-1.pdf', 'logo.png']`, `meta['assignment']['id'] === 7`, `uploaded['zip_name']` set.
  - entries left out and counted in the message: `example/assets/.hidden`, `example/assets/sub/x.png`, `example/notes.txt`, `example/assets/a b.png`; none exists under `$destDir`.
  - rejected, previous setup intact afterwards: not a zip; two top folders; no `next-metadata.json`; metadata that is not a JSON object; `format_version` missing or a string; 501 entries.
  - `format_version` 2 is accepted.
  - a flow zip (root `documentation.txt`) is rejected with a message containing `Donation flows`.
  - **Review Focus 5:** ingest a second zip with only `next-metadata.json`; `setup_load()['assets'] === []` and `$destDir/assets/logo.png` no longer exists; no `*.new` or `*.old` directory remains beside `$destDir`.
  - `export-warnings.json` `{"skipped":[{"path":"example/assets/logo.png","reason":"x"}]}` → `warnings === ['assets/logo.png']`.
  - `setup_remove` returns true then false; `setup_load()` is null after.
  - `setup_asset_name_ok`: true for `logo.png`, `instruction-step-1-1-1.png`; false for `''`, `.x`, `a/b`, `..`, `a b`, a 101-character name.

- [ ] **Step 2: Run** `php tests/run.php`. Expected: FAIL (functions undefined).
- [ ] **Step 3: Implement** in `src/StudySetup.php`. Caps as constants: 64 MiB upload, 500 entries, 256 MiB unpacked (sum of declared sizes, and each entry read with a hard stop one byte past its declared size), 4 MiB per JSON file. Unpack into `$destDir . '.new'`, then swap (`$destDir` → `.old`, `.new` → `$destDir`, delete `.old`); any failure removes `.new` and leaves `$destDir` as it was. Messages:
  - no `ZipArchive`: `This workspace is missing a component (php-zip) — contact whoever set up this workspace.`
  - not a zip / wrong shape / no metadata: `That doesn't look like a study setup from Next. Upload the zip exactly as Next downloaded it (⋮ → Export).`
  - a flow: `That is a donation flow from the script selector — upload it under “Donation flows”.`
  - over a cap: `That zip is too large to be a study setup (more than 500 files, or more than 256 MB unpacked).`
  - cannot write: `Could not save the study setup — the storage volume may be full or read-only.`
  - success: `<name> — N task(s) ✓`, plus ` M file(s) in the zip were not recognised and were left out.` when M > 0.
- [ ] **Step 4: Run** `php tests/run.php`. Expected: `0 failed`.
- [ ] **Step 5: Commit** `feat(setup): read and store a study setup exported from Next`.

### Task 7: Study setup in Setup; name fill; Story 2 check and PR

**Files:**
- Modify: `src/Instance.php` (actions), `src/Flows.php` (one message), `public/setup.php`, `public/assets/style.css` if needed
- Test: `tests/SetupTest.php`

**Interfaces:**
- Consumes: Task 6's functions.
- Produces: POST actions `upload_setup` (file field `setup_zip`) and `remove_setup`.

- [ ] **Step 1: Write the failing tests** in `tests/SetupTest.php` (with `make_setup_zip` and the Task 6 metadata):
  - `upload_setup` into an instance with an empty name: flash `ok`; `inst_load()['study_name'] === 'Example study'`; `inst_configured()` unchanged by it.
  - with the name already `Mine`: after upload the name is still `Mine`.
  - `remove_setup`: flash `ok`, `setup_load() === null`, name unchanged; a second `remove_setup` is an error.
  - uploading a study setup zip as a **flow** (`upload_flow`): error whose text contains `Study setup`.
  - page render with a setup stored: contains `Example study`, `href="https://next.eyra.co/" target="_blank" rel="noopener noreferrer"`, a `remove_setup` form, and `class="optional"` at least three times; the study setup part comes before `Donation flows`. With none stored: contains `Without a study setup`.
- [ ] **Step 2: Run** `php tests/run.php`. Expected: FAIL.
- [ ] **Step 3: Implement.** Actions as specified; the name is filled only when the saved name trims to empty and the ingest returned a non-empty `name`. Removal text: `Removed the study setup — upload it again to restore it.` / `There is no study setup to remove.` In `flows_ingest_upload`, when `PharData` cannot open the file and `ZipArchive` finds a `*/next-metadata.json` in it: `That is a study setup from Next — upload it under “Study setup”.`
- [ ] **Step 4: The Setup part**, between the name and the flows part. Exact copy: `<h3>Study setup</h3>` + `from Next` (linked) + optional mark. `What participants saw: information pages, consent, instructions and tasks. In Next, open the study, choose ⋮ next to Publish, then Export, and upload that zip here.` Stored: `<name> · exported <j M Y>` (the crate's `datePublished`; without a crate, `uploaded <j M Y>`) and a `Remove` form. None stored: `Without a study setup, the inspector cannot show the consent, instructions or tasks.` Upload button: `Upload setup`.
- [ ] **Step 5: Run** all four test commands. Expected: all pass.
- [ ] **Step 6: Commit** `feat(setup): add the study setup to "Describe your study"`.
- [ ] **Step 7: Real-file check (Review Focus 1).** With a temp storage root and a small PHP script calling the handlers: upload `/home/dmccool/Downloads/tiktok_crime_data_donation_20261121.zip` as the study setup and `/home/dmccool/Downloads/build_1795282506.zip` as a flow. Expected: both `ok`; study name filled with `Tiktok crime data donation`; `setup_load()['assets'] === ['document-1.pdf']` and the stored PDF is 1166409 bytes; 2 tasks. Screenshot Setup with both stored at 1280 and 900 wide and read the images. Delete the temp storage root and screenshots afterwards.
- [ ] **Step 8:** Push `study-setup-upload`; open the PR against `setup-regroup`, noting the real-file check result.

---

# Story 3: `study-page` (branch from `study-setup-upload`)

### Task 8: Rich text sanitiser

**Files:**
- Create: `src/RichText.php`, `tests/RichTextTest.php`
- Modify: `src/bootstrap.php` (require), `tests/run.php`

**Interfaces:**
- Produces: `rich_html(string $html): string`: safe HTML for direct output.

Rules (spec §8): output tags are only `p br h4 h5 h6 strong em u s ul ol li blockquote pre code a`. Input mapping: `div`→`p`; `h1`→`h4`, `h2`→`h5`, `h3`–`h6`→`h6`; `b`→`strong`; `i`→`em`; `del`, `strike`→`s`. Every tag is emitted bare; `a` is emitted as `<a href="…" target="_blank" rel="noopener noreferrer">` only when its entity-decoded, trimmed `href` starts with `http://`, `https://` or `mailto:` (case-insensitive), otherwise the tag is dropped and its text kept. `script`, `style` and comments are dropped with their content; any other tag is dropped, text kept. In text, `<` and `>` become entities; existing entities pass through. Balancing: a stack of open tags; a closer closes back to its match, a closer with no match is dropped, opening a `p` closes an open `p`, and everything still open is closed at the end.

- [ ] **Step 1: Write the failing tests** as exact input → output pairs:

```php
$cases = [
  '<div>One.</div><div>Two.</div>' => '<p>One.</p><p>Two.</p>',
  '<!--block--><div>A<br><br></div>' => '<p>A<br><br></p>',
  '<h1><strong>Title</strong></h1>' => '<h4><strong>Title</strong></h4>',
  '<p onclick="x()" class="c">hi</p>' => '<p>hi</p>',
  '<script>alert(1)</script><p>ok</p><style>p{}</style>' => '<p>ok</p>',
  '<a href="mailto:a@example.org" target="_top">mail</a>' => '<a href="mailto:a@example.org" target="_blank" rel="noopener noreferrer">mail</a>',
  '<a href="javascript:alert(1)">x</a>' => 'x',
  '<a href=" JAVASCRIPT:alert(1)">x</a>' => 'x',
  '<a href="https://example.org/?a=1&amp;b=2">l</a>' => '<a href="https://example.org/?a=1&amp;b=2" target="_blank" rel="noopener noreferrer">l</a>',
  '<a title="><img src=x onerror=alert(1)>">t</a>' => '"&gt;t',   // tags end at the first >, quotes or not
  '<img src="x" onerror="alert(1)"><figure>cap</figure>' => 'cap',
  '<ul><li>a<li>b' => '<ul><li>a<li>b</li></li></ul>',            // Review Focus 2: closed at the end
  '<strong>bold</p></div>after' => '<strong>boldafter</strong>',   // Review Focus 2: stray closers dropped
  '1 < 2 > 0 &amp; &lt;b&gt;' => '1 &lt; 2 &gt; 0 &amp; &lt;b&gt;',
  '<pre><em>note</em> <a href="https://example.org">x</a></pre>' => '<pre><em>note</em> <a href="https://example.org" target="_blank" rel="noopener noreferrer">x</a></pre>',
  '<svg><script>x</script></svg><iframe src="//e"></iframe>' => '',
  '<p>a</p><script>never closed <p>b</p>' => '<p>a</p>',
  '' => '',
];
foreach ($cases as $in => $want) { eq(rich_html($in), $want, 'rich_html: ' . $in); }
foreach (array_keys($cases) as $in) {
    $out = rich_html($in);
    check(preg_match('~<(?!/?(?:p|br|h[456]|strong|em|u|s|ul|ol|li|blockquote|pre|code)>|a href="[^"<>]*" target="_blank" rel="noopener noreferrer">|/a>)~', $out) === 0, 'only rebuilt tags in output: ' . $in);
    foreach (['p', 'h4', 'h5', 'h6', 'strong', 'em', 'u', 's', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'a'] as $t) {
        eq(preg_match_all("~<$t[ >]~", $out), substr_count($out, "</$t>"), "balanced <$t>: $in");
    }
}
```

Tokenising, which the cases above pin: comments are removed first; a tag is `<`, an optional `/`, a letter, then everything up to the next `>` (quotes are not honoured, which is what makes the rebuild safe); any other `<` or `>` is text and is escaped; an unclosed `script` or `style` drops everything to the end.

- [ ] **Step 2: Run** `php tests/run.php`. Expected: FAIL (`rich_html` undefined).
- [ ] **Step 3: Implement** `rich_html` as a single pass over the input split into tags, comments and text, with no extension beyond core PHP.
- [ ] **Step 4: Run** `php tests/run.php`. Expected: `0 failed`.
- [ ] **Step 5: Commit** `feat(study): sanitise rich text from a study setup by allowlist`.

### Task 9: Serve packaged files

**Files:**
- Create: `public/asset.php`
- Modify: `src/StudySetup.php`
- Test: `tests/StudySetupTest.php`

**Interfaces:**
- Produces: `setup_asset_file(string $name): ?string` (absolute path, or null unless the name passes `setup_asset_name_ok` and the file exists in the stored assets); `setup_asset_type(string $name): array{type:string, inline:bool}`; URL `asset.php?f=<basename>`.

- [ ] **Step 1: Write the failing tests** (with the Task 6 example setup stored):

```php
check(setup_asset_file('logo.png') !== null, 'stored asset resolves');
foreach (['../next-metadata.json', 'next-metadata.json', 'nosuch.png', '', 'a/b.png'] as $bad) { eq(setup_asset_file($bad), null, 'asset refused: ' . json_encode($bad)); }
eq(setup_asset_type('g.PDF'), ['type' => 'application/pdf', 'inline' => true], 'pdf inline');
eq(setup_asset_type('s.svg'), ['type' => 'image/svg+xml', 'inline' => true], 'svg inline');
eq(setup_asset_type('x.html'), ['type' => 'application/octet-stream', 'inline' => false], 'html is a download');
eq(setup_asset_type('x.docx')['inline'], false, 'unknown type is a download');
eq(render_page('asset.php', ['f' => 'logo.png']), (string)file_get_contents(setup_asset_file('logo.png')), 'asset bytes served');
eq(render_page('asset.php', ['f' => '../next-metadata.json']), 'Not found', 'a refused name gets "Not found" and no content');
eq(render_page('asset.php', []), 'Not found', 'no name gets "Not found"');
```

`asset.php` must `return`, never `exit` (the tests include it, as they do every page); the not-found case sends status 404 with the body `Not found`.

- [ ] **Step 2: Run** `php tests/run.php`. Expected: FAIL.
- [ ] **Step 3: Implement.** Inline types: `pdf`, `png`, `jpg`/`jpeg`, `gif`, `webp`, `svg`; everything else `application/octet-stream` as a download. Headers on every asset: `Content-Type`, `Content-Length`, `X-Content-Type-Options: nosniff`, `Content-Disposition: inline|attachment; filename="<name>"`, `Cache-Control: private, max-age=3600`; for `svg` also `Content-Security-Policy: sandbox; default-src 'none'; style-src 'unsafe-inline'`. The page needs `cfg_ready()` but not `guard_configured()`.
- [ ] **Step 4: Run** `php tests/run.php`. Expected: `0 failed`.
- [ ] **Step 5: Commit** `feat(study): serve a study setup's packaged files`.

### Task 10: Codebook data from the flows

**Files:**
- Modify: `src/Flows.php`
- Test: `tests/FlowsTest.php`

**Interfaces:**
- Produces: `flows_parse_doc()` result gains `'source_url' => ?string`; each doc from `flows_load_all()` gains `'meta' => array` (the flow's `build-meta.json`: `commit`, `build_zip_name`, `uploaded_at`; `[]` when missing).

- [ ] **Step 1: Write the failing tests:**

```php
$withUrl = "# X\n\n## T\n\nd\n\n| Variable | Description |\n| - | - |\n| `A` | a |\n\n## Build information\n\nThis flow was generated from commit `abcdef1234567` of the repository.\n\nView the source code at: https://github.com/d3i-infra/data-donation-task/commit/abcdef1234567\n";
eq(flows_parse_doc($withUrl)['source_url'], 'https://github.com/d3i-infra/data-donation-task/commit/abcdef1234567', 'source address captured');
eq(flows_parse_doc(str_replace('https://github.com/', 'https://evil.example/', $withUrl))['source_url'], null, 'only a GitHub address is kept');
eq($doc['source_url'], null, 'a documentation file without the line has no source address');
```

and, after the ingest into the storage-mode scratch root: `flows_load_all()['facebook']['meta']['build_zip_name']` equals the uploaded build name; a flow directory without `build-meta.json` gives `meta === []`.

- [ ] **Step 2: Run** `php tests/run.php`. Expected: FAIL.
- [ ] **Step 3: Implement.** The address is kept only when it matches `~^https://github\.com/[A-Za-z0-9._/-]+$~`.
- [ ] **Step 4: Run** `php tests/run.php`. Expected: `0 failed`.
- [ ] **Step 5: Commit** `feat(flows): keep a flow's source address and upload details`.

### Task 11: The Study page

**Files:**
- Create: `public/study.php`, `tests/StudyPageTest.php`
- Modify: `src/StudySetup.php`, `src/bootstrap.php` (`page_top()` nav), `public/setup.php` (two lines of copy), `public/assets/style.css`, `tests/run.php`, `tests/PagesTest.php` (nav assertions)

**Interfaces:**
- Consumes: `setup_load()`, `rich_html()`, `asset.php?f=`, `flows_load_all()` with `source_url` and `meta`.
- Produces:
  - `setup_text(mixed $v): string`: the trimmed string, or `''` for anything that is not a string.
  - `setup_ref(mixed $ref, array $assets): ?string`: the basename when `$ref` is a string of the form `assets/<name>` and `<name>` is in `$assets`; otherwise null.
  - `setup_overview(array $setup): array{name:string, public_title:string, subtitle:string, language:string, authors:list<array{name:string, email:string}>, study_number:?int, exported_at:string, license:?array{name:string, url:string}, publisher:?array{name:string, url:string}, logo:?string, format_version:int}`. Reads the crate's root `Dataset` (`@id` `./`) and resolves its `author`, `license` and `publisher` references by `@id`; an author's email comes from `contactPoint.@id` starting `mailto:`. A licence or publisher `url` is kept only when it starts `https://` or `http://`. Missing parts are `''`, `[]` or null.
  - Nav: Participants · Study · Settings; Study appears whenever `inst_root() !== null`; section key `'study'`.

Page (spec §8). It starts like `index.php` (`cfg_ready()` check) but has no `guard_configured()`. Without a storage volume (developer mode) it shows only `This instance is configured by files on disk (developer mode).`, as Setup does. Section ids: `about`, `consent`, `privacy`, `tasks`, `helpdesk`, `collected`. Title band: `<span class="eyebrow">Study</span> <name>` where the name is the saved study name, else the setup's; just `Study` with neither. The overview block is the page's first element and carries class `niche`. Exact copy:
  - both halves missing: `Nothing describes this study yet. Add a study setup or a donation flow in Settings.` (Settings linked to `setup.php`), and nothing else.
  - no setup: `No study setup yet, so there is no consent, instructions or tasks to show. Add one in Settings.`
  - no flow: `No donation flow yet, so there is no codebook to show. Add one in Settings.`
  - newer format: `This study setup was exported in a newer format (version N); some parts may not be shown.`
  - export warnings: `Next could not include N file(s) in the export: <names>.`
  - a referenced file not stored: `not included in the export`.
  - headings: `What participants saw`, `About`, `Consent`, `Privacy statement`, `Tasks`, `Helpdesk`, `What was collected`. Overview: `Shown to participants as “<title>”` (only when it differs from the study name), `Next study <number>`, `setup exported <j M Y>`.
  - task type labels: `document`, `data donation`, `questionnaire`, `instruction manual`; any other type is shown by its own name with `_` as spaces.
  - a flow: `<platform> · built from commit <first 7>` (linked when `source_url` is set) `· uploaded <j M Y>`.
  - Setup's "without" lines gain the Study page: `Without a flow, tables keep their raw names and have no descriptions, and the Study page has no codebook.` / `Without a study setup, the Study page has no consent, instructions or tasks.`

- [ ] **Step 1: Write the failing tests** in `tests/StudyPageTest.php` (storage-mode scratch root; setups stored with `setup_ingest_upload`, flows by copying `tests/fixtures/flows/tiktok/documentation.txt` into `config/flows/tiktok/`). One full setup: the Task 6 metadata plus `about`, `helpdesk`, a `privacy_statement_pdf`, a `questionnaire` task with a link, an `instruction_manual` task with one chapter and two pages (one image stored, one referenced but not stored), an `unknown` task, a crate with two authors (one without a contact point), and consent `'<h1>Consent</h1><div onclick="x()">I agree <script>alert(1)</script></div>'`. Assertions:
  - nav: `Participants`, `Study`, `Settings` in that order, Study marked current; in dev mode (`tests/config.test.php`) there is no `study.php` link (add to `tests/PagesTest.php`).
  - the page renders with **no source configured** (no `guard_configured()` redirect text).
  - headings appear in the order About, Consent, Privacy statement, Tasks, Helpdesk, What was collected; "On this page" links only to present sections.
  - consent is sanitised: contains `<h4>Consent</h4>` and `<p>I agree </p>`; contains neither `onclick` nor `alert(1)`.
  - the document task links to `asset.php?f=document-1.pdf` labelled `guide.pdf`; the data donation task shows `dd-example.zip`; the questionnaire shows its link; the manual shows its chapter and page titles, an `<img` for the stored image and `not included in the export` for the other; the unknown task shows `unknown`.
  - overview: both author names, exactly one `mailto:` link, `Next study 7`, the licence name linked, the publisher's name, `Shown to participants as`, and the logo as `<img` with `src="asset.php?f=logo.png"`.
  - the privacy statement links to `asset.php?f=privacy-statement.pdf`.
  - developer mode (`tests/config.test.php`): the page shows the developer-mode notice and nothing else.
  - a title, filename or author name containing `<b>x</b>` appears escaped.
  - codebook: `TikTok`, every section title and variable name of the fixture; with `source_url` set the commit is a link.
  - only a flow → the "No study setup yet" line and the codebook; only a setup → the "No donation flow yet" line; neither → only the "Nothing describes this study yet" note, and none of the section headings.
  - `format_version` 2 → the newer-format note. `export-warnings.json` → the notice with the file name.
  - **Review Focus 3:** a setup whose metadata is only `{"format_version":1}` renders with the "What participants saw" heading, no section headings under it, and no PHP warning (run the render inside `set_error_handler` that fails the test on any warning or notice; restore after).
  - **Review Focus 4:** metadata with `branding` a string, `workflow.tasks` an object of scalars, a task whose `title` is an array and whose `chapters` is a string, `consent` an integer, and a crate whose `@graph` is a string: renders under the same no-warnings handler, with no fatal.
  - `setup_ref('assets/../x', ['x']) === null`; `setup_ref('https://e/x.png', ['x.png']) === null`; `setup_ref('assets/logo.png', ['logo.png']) === 'logo.png'`; `setup_ref(null, []) === null`.
- [ ] **Step 2: Run** `php tests/run.php`. Expected: FAIL.
- [ ] **Step 3: Implement** the helpers, then the page, then the nav link and the two Setup lines. Every value read from the metadata or the crate goes through `setup_text()` (or an `is_array` check) before use; rich fields through `rich_html()`; everything else through `h()`.
- [ ] **Step 4: CSS** for the overview, the "On this page" row, the rich-text blocks (`pre` wraps), tasks, manual pages (screenshots constrained in width) and the codebook tables, reusing existing table and meta styles; one sentence in the header comment.
- [ ] **Step 5: Run** `php tests/run.php`. Expected: `0 failed`.
- [ ] **Step 6: Commit** `feat(study): add the Study page`.

### Task 12: Story 3 check, docs and PR

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Real-file check (Review Focus 1).** As in Task 7 Step 7, upload the two files from `~/Downloads` into a temp storage root. Screenshot `study.php` at 1280 and 900 wide, tall enough for the whole page, and read the images. Expected: the consent text reads as in Next (three headings, two lists, the two highlighted notes, working mail links), the helpdesk text shows, task 1 links to the PDF and `asset.php?f=document-1.pdf` returns 1166409 bytes with `Content-Type: application/pdf` (check with `curl -sI` inside the same command as the server), task 2 shows `dd-uu-tiktok-2026_all_master_2026-06-25.zip`, four authors, the codebook has seven tables. Also screenshot the three empty states. Fix what is wrong; delete the temp storage root and screenshots.
- [ ] **Step 2: README.** Pages list gains the Study page; wording follows the spec's §5 (study setup, donation flow, script selector).
- [ ] **Step 3: Run** all four test commands. Expected: all pass.
- [ ] **Step 4: Commit** `docs: describe the Study page and the study setup`.
- [ ] **Step 5:** Update the local session notes (`CLAUDE.md`, gitignored, so no commit): the three branches and PRs, the Study page, `php-zip` as an app dependency, the new vocabulary.
- [ ] **Step 6:** Push `study-page`; open the PR against `study-setup-upload`, noting the real-file check result. Tell Danielle the merge order (`setup-regroup`, `study-setup-upload`, `study-page`) and that the live check after deploy is hers (runbook §8; its apt line already installs `php-zip`).
