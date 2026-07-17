# Kuras v2 delivery roadmap

## Working model

The complete product vision stays in this repository. Delivery happens in small milestones, each ending with automated evidence and a draft pull request. The client approves outcomes and business decisions; technical task decomposition is handled inside the project.

## Phase 0 — Foundation and decisions

Deliverables:

- Preserve the legacy prototype and document its release blockers.
- Confirm backend/WordPress separation and data ownership.
- Add durable AI/repository guidance.
- Establish local environment, formatting, static analysis, unit-test runner, CI, and branch protection expectations.
- Record remaining infrastructure and product decisions.

Acceptance:

- A new developer or AI agent can explain the system, run the baseline checks, and see why the legacy importer cannot be deployed unchanged.

## Phase 1 — Trusted LEA ingestion

Deliverables:

- Source discovery and download adapters.
- Immutable raw-source storage and import-run ledger.
- Versioned parser with real sanitized workbook fixtures.
- Source-date detection, column mapping, normalization, and anomaly validation.
- Transactional staging/publish flow, retries, idempotency, and actionable alerts.
- Backfill command for historical LEA files.

Acceptance:

- Re-running the same source makes no duplicate changes.
- Weekend or stale sources cannot be published under a false date.
- Schema drift and suspicious values fail safely while the last known-good data remains live.

## Phase 2 — Core data API

Deliverables:

- Stable station identity, aliases, geocoding provenance, and manual overrides.
- Versioned endpoints for filters, price sorting, TOP rankings, history, viewport maps, nearby stations, and statistics.
- API caching, pagination, rate limits, error contracts, and OpenAPI documentation.
- Integration and performance tests based on production-scale sample data.

Acceptance:

- All MVP queries return correct source dates and deterministic results within agreed performance budgets.

## Phase 3 — WordPress experience

Deliverables:

- `kuras-pricer` WordPress plugin and configurable API client.
- Mobile-first homepage, sortable table, detailed filters, map with GPS consent, rankings, station pages, and history charts.
- Pricer.lt visual tokens and editorial/ad integration.
- Cached Pricer news cards by approved category/tag.

Acceptance:

- The main user journeys work on representative mobile and desktop sizes, including graceful API failure and stale-data states.

## Phase 4 — SEO, accessibility, and growth

Deliverables:

- Indexable city/fuel/network landing pages, canonical URLs, sitemap, structured data, hreflang rules, and metadata.
- Accessibility review, keyboard/map alternatives, performance budgets, and analytics events.
- Optional alerts, saved stations, route links, and European aggregate comparisons after MVP evidence.

Acceptance:

- SEO pages contain useful server-rendered content, avoid duplicate combinations, and pass agreed accessibility/performance checks.

## Phase 5 — Production and AI-assisted maintenance

Deliverables:

- Automated staging/production deployments with rollback.
- Secrets management, monitoring, uptime checks, error tracking, import freshness dashboard, and backup restore tests.
- Automated dependency and security-update pull requests.
- Incident runbooks and scheduled AI diagnostics that prepare a proposed fix rather than changing production silently.

Acceptance:

- A failed import, API outage, deployment failure, stale source, or backup failure produces an actionable alert and a documented recovery path.

## Human decisions needed before their phase

- Production hosting constraints and budget.
- WordPress access and the exact Pricer theme/design system.
- Map-tile and geocoding provider choice/budget.
- Email/notification provider and incident channel.
- Legal confirmation for LEA data reuse, attribution, privacy, cookies, and alerts.
- MVP languages and which nice-to-have features move after launch.

