# AI SEO Audit (Craft CMS 4)

Analyzes live frontend HTML for entries in selected Craft sections using configurable LLM providers.

## Installation

```bash
composer update wideweb/ai-seo-audit
php craft plugin/install ai-seo-audit
php craft plugin/enable ai-seo-audit
```

## Configuration

1. Open **Settings → Plugins → AI SEO Audit**.
2. Choose **LLM provider** (`OpenRouter`, `OpenAI`, `Anthropic`, `Google Gemini`).
3. Set **LLM API Key** and **Language model** for the chosen provider.
4. Optionally override **API base URL** (leave empty to use provider default).
5. Fill provider-specific fields when needed:
   - OpenAI: `OpenAI organization`, `OpenAI project` (optional)
   - Anthropic: `Anthropic API version`
   - Gemini: `Gemini API version` (`v1beta` or `v1`)
6. Check the **sections** you want to include in audits.

Optional `.env` variables:

- `AI_SEO_AUDIT_LLM_PROVIDER` — overrides selected provider (`openrouter|openai|anthropic|gemini`)
- `AI_SEO_AUDIT_API_KEY` — overrides API key from settings
- `AI_SEO_AUDIT_MODEL` — overrides model from settings
- `AI_SEO_AUDIT_API_BASE` — overrides API base URL from settings
- `AI_SEO_AUDIT_VERIFY_SSL` — set to `false` to disable SSL verification for local fetches
- `AI_SEO_AUDIT_PSI_API_KEY` — overrides PageSpeed Insights API key from plugin settings
- `AI_SEO_AUDIT_PDF_MODELS` — comma-separated model hints treated as PDF-capable for client report export
- `AI_SEO_AUDIT_FORCE_PDF_EXPORT` — force PDF export on/off (`true|false`) regardless of model hints

## Usage

Open **AI SEO Audit** in the CP sidebar, confirm sections, and click **Start audit**.

### Clear old audits

- **CP:** **AI SEO Audit** → **Clear all audits** (above the runs table).
- **CLI:** `php craft ai-seo-audit/clear` (confirm when prompted).

Stop pending queue jobs first if an audit is stuck:

```bash
php craft queue/clear
```

Results are stored in:

- `ai_seo_audit_runs`
- `ai_seo_audit_results`

Run the queue worker so audits complete (one LLM request per page, with configurable delay):

```bash
php craft queue/run
```

The plugin sends only a compact SEO snapshot to the LLM (title, meta tags, headings, alt-text stats) — not full page HTML. Configure **Delay between pages** to match your provider rate limits.

## Scalable roadmap

For a production-grade implementation that supports thousands of pages, external SEO/performance signals, and client-ready remediation reports, see:

- `SCALABLE_AUDIT_BLUEPRINT.md`

## Phase 1 foundation (implemented)

- New normalized storage tables:
  - `ai_seo_audit_urls`
  - `ai_seo_audit_signals`
  - `ai_seo_audit_issues`
- Deterministic rules engine (`RuleEngineService`) now creates issues for each audited page.
- CSV export includes issue counts and issue codes per page.
- Run details page shows per-page issue count.

## Phase 1.1 (implemented)

- Staged queue flow:
  - `DiscoverAuditUrlsJob` discovers URLs first
  - page analysis jobs are queued only after discovery
- Optional sitemap URL discovery from plugin settings.
- CSV export now includes `entryCpUrl` (link to entry edit page in Craft CP when available).

## Phase 1.2 (implemented)

- Queue pipeline split into two stages:
  - fetch stage (`ProcessAuditPageJob`)
  - evaluate stage (`EvaluateAuditResultJob`)
- Deterministic issue evaluation and optional LLM analysis now run in evaluate stage after fetch is complete.
- Progress tracking treats `fetched` as in-progress until evaluation finishes.
- Added Issues screen in CP with filters (`severity`, `status`, `issueCode`, `ownerSuggestion`).
- Added separate filtered export for issues (`export-issues` CSV).
- Added clustered export for recurring issues (`export-clusters` CSV), grouped by issue/severity.
- Top clusters now use wider grouping (`issueCode + severity`) to highlight systemic problems.
- Added section filter on Issues screen to review top clusters for a specific section.

## Phase 2 (implemented)

- Added external metrics storage table:
  - `ai_seo_audit_external_metrics`
- Added optional integrations for business signals:
  - Google PageSpeed Insights API (performance score, CWV metrics) when API key is configured
  - Public W3C Nu HTML Validator endpoint (HTML errors/warnings, no API key)
  - Public robots/sitemap discovery checks (`/robots.txt`, `/sitemap.xml`)
- Large runs now use automatic sampling budgets for external APIs (to avoid hitting public endpoint limits):
  - full coverage up to 200 URLs
  - up to 150 PSI checks per run
  - up to 120 W3C HTML validation checks per run
  - up to 250 robots/sitemap checks per run
- `priorityScore` now combines deterministic severity with business impact signals.
- Added run-over-run trend comparison (total delta + severity delta) on run and issues screens.
