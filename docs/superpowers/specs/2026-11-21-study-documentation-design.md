# DDP Inspector: study documentation (Setup regrouped, study setup upload, Study page) (design)

**Date:** 2026-11-21
**Status:** draft, awaiting approval
**Builds on:** `2026-07-29-ddp-inspector-catalog-component-design.md` (Setup
wizard, flow uploads, refresh machinery) and the D3I look (PR #16/#17:
`page_top()`, the steps, `style.css`).

## 1. Why

Two packages document a data donation study, and D3I presents them as a
pair (news article "Ready to reproduce: export your data donation study",
datadonation.eu, 2026-11-09):

- the **study setup**, exported from Next (study page, ⋮ → Export): an
  RO-Crate zip holding what participants saw: branding, the About and
  Helpdesk pages, the consent text, the privacy statement, and the tasks in
  order (documents, instruction manuals, questionnaires, the donation task);
- the **donation flow** from the script selector: the flow itself plus its
  codebook (`documentation.txt`): which tables are collected and what each
  variable means.

The inspector already takes the donation flow (to title and describe tables
on participant pages) but shows the codebook nowhere as a whole, cannot take
the study setup at all, and has no page that says what the study is. A
researcher browsing donations has no way to look up what participants agreed
to or what a variable means.

Setup also has three concrete problems:

- a flow uploaded by mistake cannot be removed;
- its steps are not logical groups: the study name sits with "where are your
  donations stored", and the optional flow upload is step 01;
- it calls the script selector the "flow builder" (its old name).

## 2. Goals, non-goals, anti-goals

Goals
- Setup's steps are logical groups, in an order that reads as one path:
  describe the study, connect the donations, fetch.
- An uploaded flow can be removed. So can the study setup.
- A researcher can upload the study setup zip exactly as Next downloads it.
- A **Study** page, third in the navigation, shows the study's
  documentation: what participants saw (from the study setup) and what was
  collected (the codebook, from the donation flows).
- Study setup and donation flows are both visibly optional, and each says
  what is lost without it.
- The three things a researcher handles are told apart by name, everywhere
  on screen: **study setup** (what participants saw), **donation flow**
  (what was collected), **donations** (what participants gave).

Non-goals
- Matching a donation task in the study setup to an uploaded flow. The page
  shows both and claims nothing about whether they are the same build.
- Editing the study setup, or holding more than one. One study, one setup;
  a new upload replaces the old one.
- Showing the header image (it is an external URL in the export, not a
  packaged file).
- Validating the RO-Crate against the specification. The app reads the
  fields it shows and tolerates the rest.
- The per-enrichment directories feature (CLAUDE.md open items), changes to
  how donations are fetched, phone layouts.
- Any JavaScript. The app has none today.

Anti-goals (a failure even if it technically works)
- Rendering HTML from an upload without sanitising it.
- Writing a path named by an upload to disk, or serving a file by a name the
  app did not validate.
- An uploaded asset able to run script in the inspector's origin.
- Overwriting a study name the researcher typed.
- Existing workspaces needing a migration or a re-save: an `instance.json`
  written by today's Setup keeps working unchanged.
- Calling the donations an "export" on screen (that word now belongs to the
  study setup).
- Python anywhere (repo rule).

## 3. Constraints

- PHP 8.3 on workspaces (8.5 locally), no framework, no build step.
- The app reads zips with `PharData` today because `php-zip` is not
  installed on workspaces. `PharData` cannot read a Next export ("cannot
  read local file header": Next streams its zips, with sizes after the
  data). `ZipArchive` reads it correctly (verified 2026-11-21 on a real
  export). `unzip` reads it completely only with its zip-bomb check off.
- Upload ceiling is 64 MB (`inspector_upload_max`, nginx and PHP).
- Storage is the volume (`storage_root`), outside the web root. nginx
  serves `public/` and runs any `public/*.php`.
- Everything is behind SRC-Nginx's SRAM login; the uploader is a researcher
  of the study. Uploads are still treated as untrusted input.
- No real study's content in tracked files (commit 43b6d42): fixtures are
  made up.

## 4. Approaches

How the app opens the study setup zip was the one real choice.

- **A. `php-zip` (`ZipArchive`), chosen by Danielle.** In-process; each
  entry's size is known before it is read; reads are capped; nothing named
  by the upload touches disk. Costs one apt package in the install role and
  a one-time root command on a running workspace.
- **B. Shell out to `unzip`.** Already installed, no root. Needs the
  zip-bomb check off for a browser upload (so own size caps), one process
  per file, and parsing of `unzip`'s listings.
- Dropped: a zip parser written in PHP (too much code to trust); changing
  Next's exporter (exports in this form already exist).

Chosen: A, because it is the smaller and safer code, and its cost is one
package.

The rest had one sensible way each; these are Claude's calls, agreed in
outline by Danielle:

- The setup is unpacked once, at upload, into `config/study-setup/` under
  names the app chooses. (Reading the zip on every page view would need the
  zip kept and re-opened per request.)
- Rich text is sanitised by rebuilding every tag from an allowlist, with no
  extra PHP extension. (`DOMDocument` would need `php-xml` on workspaces;
  showing plain text would lose the headings, lists and links of the
  consent form, whose form matters.)
- Packaged files (PDFs, screenshots, logo) are served by a small PHP page,
  because they live outside the web root.

## 5. Names and wording (decided)

| On screen | What it is | Where it comes from |
|---|---|---|
| **Study setup** | What participants saw: information pages, consent, instructions, tasks | Next: open the study, ⋮ → Export |
| **Donation flow** | What was collected: the tables and what each variable means | The script selector, one per platform |
| **Donations** | What participants gave | Research Drive, Yoda, or a folder |

- "flow builder" becomes "script selector" in every message and label.
- "Next" in the study setup part links to `https://next.eyra.co/`; "script
  selector" in the flows part links to
  `https://datadonation.eu/software/script-selector/`. Both open in a new
  tab.
- The donations are never called an "export" on screen.

## 6. Setup page (decided)

Three steps, in this order. Step numbers stay in the discs.

```
01  Describe your study                                   optional
    Study name [____________________]              Save ····→

    Study setup · from Next                               optional
    What participants saw. In Next: ⋮ → Export.
      Example study · exported 21 Nov 2026                Remove
      [Choose file]                        Upload setup ····→

    Donation flows · from the script selector             optional
    What was collected. One per platform.
      Netflix  3 data tables                              Remove
      TikTok   7 data tables                              Remove
      [Choose file]                         Upload flow ····→

02  Connect your donations
    Research Drive / Yoda / a folder on this workspace
    Analysis database (a path in that folder)      Save ····→

03  Fetch
    Check connection and fetch donations ····→
    [ ] Check for new donations automatically every day   Save
    Status · Technical log
```

Step 01, Describe your study
- The whole step is marked optional, and so are its two upload parts.
- Study name has its own Save. It is shown under the wordmark in the header
  (as today).
- Study setup part: one line saying what it is and where to get it; if one
  is stored, its name and export date with a Remove button; the upload
  form. Without one, a line says what is lost: the Study page has no
  consent, instructions or tasks.
- Donation flows part: as today plus a Remove button per flow. Without any,
  a line says what is lost: tables keep their raw names and have no
  descriptions, and the Study page has no codebook.
- Uploading a study setup fills the study name from it (the setup's study
  name, `assignment.name`) only when the saved name is empty. It never
  overwrites a name.
- Remove has no confirmation step (uploading again restores it). The result
  message says what was removed. Remove is a quiet action: it does not look
  like the primary buttons and carries no arrow mark.

Step 02, Connect your donations
- The three storage choices and the analysis database block, unchanged in
  content, with one Save. The study name and the daily-check box are no
  longer in this form.

Step 03, Fetch
- "Check connection and fetch donations" as today, then the daily-check box
  with its own small Save, then status and the technical log as today.

Behaviour that changes underneath (Claude's calls)
- Saving a name, or uploading a study setup into an empty instance, writes
  `instance.json` before any source exists. So "configured" changes from
  "`instance.json` exists" to "`instance.json` names a valid
  `source_mode`". Pages that need donations keep redirecting to Setup until
  step 02 is saved.
- Saving step 02 keeps the saved study name and the saved daily-check
  setting (today a missing checkbox means "off"; that would silently switch
  the daily check off).
- The refresh script already handles an instance without a source (a timer
  tick with the daily check off exits quietly; a requested run reports that
  setup is incomplete). That case has no test yet; add one.
- "Study setup" now means the export from Next, and the steps are
  renumbered, so existing messages that use either are reworded: the refresh
  script's "The study setup looks incomplete … 'Where are your donations
  stored?' step" and the three probe messages in `Instance.php` that say
  "step 2". They name the step by its title ("Connect your donations").

## 7. Study setup: what it holds and how it is stored

Format (from Next's `Systems.Assignment.SetupExporter`, format version 1).
The zip has one top folder containing:

- `next-metadata.json`:
  `format_version`, `assignment {id, name}`, `language`,
  `branding {title, subtitle, logo, header_image}`, `about`, `consent`,
  `helpdesk` (rich text, HTML, or null), `privacy_statement_pdf`,
  `workflow.tasks[]`. Each task has `title`, `description`, `type`, and by
  type:
  - `document`: `filename` (the original name), `file`;
  - `data_donation`: `filename` (the script archive the study ran);
  - `questionnaire`: `link`;
  - `instruction_manual`: `manual_title`, `manual_description`,
    `chapters[] {title, pages[] {title, description, image}}`;
  - `unknown` or any other string: no further fields.
  File references (`logo`, `privacy_statement_pdf`, `file`, `image`) are
  `assets/<name>` paths or null.
- `ro-crate-metadata.json`: an RO-Crate 1.1 graph. The app uses the root
  `Dataset` (`name`, `datePublished`, `identifier`, `inLanguage`, `author`
  references, `license` reference, `publisher` reference) and the `Person`
  (`name`, `contactPoint` → `mailto:`), licence and publisher entities those
  references point at.
- `assets/…`: the packaged files.
- `export-warnings.json`, only when Next could not include a file:
  `{skipped: [{path, reason}]}`. `next-metadata.json` still refers to such a
  file; the crate does not list it.

Ingest (decided in outline; details are Claude's calls)
- New `src/StudySetup.php`. Reads the upload with `ZipArchive`. If the class
  is missing, the message says the workspace is missing a component
  (`php-zip`) and to contact whoever set it up.
- Accepts: exactly one top folder; `next-metadata.json` in it, decoding to
  an object with an integer `format_version`. `ro-crate-metadata.json` and
  `export-warnings.json` are optional. A format version above 1 is accepted;
  the Study page then notes that some parts may not be shown.
- Stores under `config/study-setup/`: the three JSON files under their fixed
  names, assets under `assets/<basename>`, and `upload-meta.json`
  (`uploaded_at`, the uploaded file's name). An asset is stored only if it
  sits directly in the top folder's `assets/` and its basename matches a
  strict pattern (letters, digits, `.`, `_`, `-`; no leading dot); others
  are left out and counted in the result message.
- Caps: the upload at most 64 MB, at most 500 entries, at most 256 MB
  unpacked in total (from the declared sizes, enforced again while
  reading), each JSON file at most 4 MB.
- Over the upload limit (Claude's call). Today the 64 MB check in PHP can
  never fire: nginx answers a larger body with its own bare 413 page, and a
  body PHP itself refuses arrives with an empty form, which Setup reports as
  "That form has expired". A setup carries every screenshot and PDF, so the
  limit is reachable (the one real export seen is 1.1 MB; a long manual
  could be tens of MB). So: nginx's limit for the inspector is set to twice
  PHP's (128 MB against 64 MB), so a realistic overshoot reaches PHP; and
  Setup recognises the refused-body case (a POST whose declared length
  exceeds `post_max_size`) and says the file is larger than this workspace
  accepts, with the limit. This covers the flow upload too. Beyond 128 MB
  the bare 413 remains.
- Asset names are chosen by Next (`logo`, `privacy-statement`,
  `document-N`, `instruction-step-T-C-P`, plus the source's extension), so
  the strict pattern does not drop real files. Paths in
  `export-warnings.json` include the top folder; `reason` is an internal
  term string and is not shown.
- Replaces atomically enough that a failed upload leaves the previous setup
  intact: unpack beside it, then swap.
- Result message on success names the study and counts the tasks, in the
  style of the flow upload's.
- Removing deletes `config/study-setup/`. The study name is left as it is.

Flow removal deletes `config/flows/<slug>/`, for a slug that matches the
existing slug pattern and exists.

## 8. Study page (decided)

`public/study.php`; navigation becomes Participants · Study · Settings.
The Study link appears whenever the instance has a storage volume (as
Settings does). The page does not require a configured source: the
documentation is independent of the donations.

```
STUDY
Example study
──────────────────────────────────────────────────────────────
Shown to participants as "News on a platform" · English
a.author, b.author
Next study 406 · setup exported 21 Nov 2026 · CC BY 4.0

On this page: Consent · Tasks · Helpdesk · What was collected

What participants saw                      (from the study setup)
  About              only if the study has one
  Consent            full text, formatted as in Next
  Privacy statement  link to the PDF, if any
  Tasks, in order
    1  How to request your data    document · open PDF
    2  Donate your data            data donation
       dd-example_all_master_2026-06-25.zip
       "Extracts …"
  Helpdesk

What was collected                         (from the donation flows)
  TikTok · built from commit f75c41a · uploaded 21 Nov 2026
    Watch history: TikTok videos you have watched.
      Date   Timestamp of when the video was watched.
      Link   URL of the watched TikTok video.
    …
```

- Title band: "Study" as the eyebrow over the study name (the pattern the
  participant page uses); just "Study" when there is no name.
- Overview (in the niche under the band): the participant-facing title
  (`branding.title`, and subtitle if any) when it differs from the study
  name; the language; the authors, each a `mailto:` link when the crate has
  an address; the Next study number (from `assignment.id`), the export date,
  the licence (linked), the publisher. The logo is shown if packaged.
  The licence is shown as the crate states it, unqualified. Next writes
  CC BY 4.0 into every export; Danielle (2026-11-21): CC BY 4.0 is a
  requirement of running a study on Next when supported by ODISSEI, so it is
  the study setup's licence, not a placeholder.
- "On this page" links to the sections that are present.
- **What participants saw**, in the order a participant met things: About,
  Consent (in full, not collapsed), Privacy statement, Tasks in order,
  Helpdesk. Absent parts are omitted, not shown empty.
- Tasks: number, title, description, and by type:
  - document: a link that opens the packaged file, labelled with its
    original filename;
  - data donation: the script archive's filename, as text;
  - questionnaire: its link;
  - instruction manual: its title and description, then chapters and pages
    in order, each page with its text and screenshot;
  - anything else: the type's name.
- A referenced file that is not in the package shows "not included in the
  export" in its place. If `export-warnings.json` is present, a notice lists
  the files Next left out.
- **What was collected**: per uploaded flow, the platform, the build commit
  (linked to its source when the documentation gives a GitHub address), the
  upload date, then every table with its description and its variables with
  theirs. The script selector writes that address as a "View the source code
  at: https://github.com/…/commit/<hash>" line in `documentation.txt`; the
  parser does not capture it yet and the test fixture lacks the line.
- With only one half uploaded, the other half is one line saying what is
  missing, linking to Settings. With neither, the page is that note alone.
- The page makes no claim that the donation task's script and an uploaded
  flow are the same build; both names are simply visible.

Rich text (Claude's call, security-relevant)
- `about`, `consent`, `helpdesk` and manual page text are HTML written in
  Next's editor (Trix, whose default block is `<div>` and which emits
  `<!--block-->` comments). They go through one sanitiser that emits only:
  paragraph-level blocks (`p`, and `div` treated as one, so adjacent blocks
  do not run together) and line breaks, headings (demoted so they sit below the page's own
  section headings), bold/italic/underline/strike, lists, blockquote, `pre`
  and `code`, and links. Every tag is re-emitted without attributes; a link
  keeps only an `http`, `https` or `mailto` address and opens in a new tab
  with `rel="noopener noreferrer"`. `script`, `style` and HTML comments are
  dropped with their content; any other tag is dropped and its text kept. No `<` or `>`
  from the input reaches the output except through that rebuild.
- Titles, descriptions, names and filenames are plain text and escaped.

Packaged files (Claude's call, security-relevant)
- `public/asset.php?f=<basename>` serves a file from
  `config/study-setup/assets/`, for a basename that passes the same strict
  pattern and exists. PDFs and common image types are served inline with
  their own content type and `X-Content-Type-Options: nosniff`; any other
  type is sent as a download. SVG must not be able to run script when opened
  directly.

## 9. Provisioning and docs

- `php-zip` joins the apt list in the inspector role.
- A workspace that is updated rather than rebuilt needs it once, by hand
  (root): `sudo apt-get install -y php-zip`, then restart php-fpm. The
  runbook's updating section (`provisioning/docs/OPERATIONS.md` §8) says so.
- README and runbook wording follow §5.
- `tests/FlowsTest.php` notes `php-zip` as a test-only dependency; it is now
  an app dependency.

## 10. Testing

- Unit tests in the existing harness (`tests/run.php`) for: the new Setup
  actions (name alone, daily check alone, step 02 keeping both, flow and
  setup removal, name fill and no overwrite, "configured" needing a source);
  ingest (accepts a well-formed setup, rejects wrong shapes, caps, unsafe
  names, missing `ZipArchive` message); the sanitiser (attributes, script,
  nested and malformed tags, link schemes); the asset page's name check;
  the Study page (both halves, each alone, neither, every task type,
  missing file, export warnings, newer format note).
- Fixtures are made up. Test zips are built with `ZipArchive`; that does not
  reproduce Next's streamed layout (the builder may add a streamed fixture
  if one can be made cheaply without Python).
- So a real pair is checked at the end of stories 2 and 3, locally, by the
  builder: `~/Downloads/tiktok_crime_data_donation_20261121.zip` (study
  setup) and `~/Downloads/build_1795282506.zip` (flow), uploaded through
  Setup into a storage root in a temporary directory, with local PHP 8.5.
  Story 2's check: the upload is accepted and the name is filled. Story 3's:
  the Study page shows the real consent text correctly formatted and the PDF
  opens. Nothing from those files enters tracked files or screenshots kept
  in the repo.
- The live workspace is the last check, by Danielle, after merge and deploy
  (runbook §8, with `php-zip` installed first).
- Screenshots of Setup and the Study page with headless Firefox, as for the
  look work, for Danielle's review.

## 11. Stories

One branch and PR per story, stacked:
`main ← setup-regroup ← study-setup-upload ← study-page`.

1. **setup-regroup**: the three steps, name and daily check saved on their
   own, flow removal, links and wording, optional marks, "configured" needs
   a source. Needs nothing installed. (The study setup part of step 01
   arrives with story 2.)
2. **study-setup-upload**: `php-zip`, ingest and storage, the study setup
   part of step 01, name fill, removal, runbook note.
3. **study-page**: the page, the sanitiser, the asset page, the navigation
   link, the codebook.

## 12. Left to the builder

- Markup and CSS for the optional marks, the quiet Remove button, the
  sub-parts of step 01 and the Study page, within the D3I look as
  `style.css` already derives it.
- Function names, the exact strict-name pattern, the heading demotion
  mapping, the exact wording of messages (within §5).
- Whether the Setup POST handlers stay in `Instance.php` or move.
