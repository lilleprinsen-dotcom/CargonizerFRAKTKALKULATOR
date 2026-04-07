# Checkout modernization plan (phased)

This document turns the six requested phases into an implementation plan with explicit scope, guardrails, and exit criteria.

## Guiding principles
- Preserve customer-visible behavior until explicit rollout phases.
- Prefer additive, reversible changes with fast rollback paths.
- Measure before and after every phase (latency, error rate, quote mismatch).
- Keep legacy and new paths side-by-side until confidence gates are met.

## Phase 1 — Extract architecture + compatibility layer (no behavior change)

### Scope
- Separate responsibilities into stable seams:
  - Quote orchestration and checkout integration.
  - WooCommerce hook adapters (legacy vs HPOS signatures).
  - Transport/API client wrappers and settings access.
- Ensure existing entry points call through adapters/services rather than inline procedural logic.

### Deliverables
- Compatibility adapters for hook signatures and order-list table differences are centralized.
- No functional changes to returned shipping rates, metadata persistence, or admin rendering.
- Baseline metrics captured for:
  - Checkout shipping latency.
  - Quote failure rate.
  - Quote-to-order metadata consistency.

### Exit criteria
- Unit/integration tests pass with unchanged fixtures.
- Snapshot/contract checks show no rate-output delta under same inputs.
- Release tagged as “internal refactor/no behavior change”.

## Phase 2 — Introduce native shipping method classes behind feature flag

### Scope
- Add native shipping method class implementations aligned to WooCommerce method model.
- Keep legacy registry/path active by default.
- Gate new class-based path using a feature flag (option + filter override for safe toggling).

### Deliverables
- Dual registration path:
  - `legacy` (default)
  - `native_methods` (flagged)
- Runtime logging of selected path per request for traceability.
- Admin diagnostics indicating active shipping path.

### Exit criteria
- In staging, native classes produce equivalent rates/labels/taxes for selected scenarios.
- Flag can be toggled without plugin reinstall or cache flush side effects.

## Phase 3 — Add HPOS compatibility and run dual-store validation

### Scope
- Ensure complete compatibility declarations and runtime behavior with HPOS enabled.
- Validate order metadata persistence and admin order-column behavior in both storage modes.
- Test matrix across:
  - Legacy posts-based order storage.
  - HPOS custom order tables.

### Deliverables
- Explicit HPOS compatibility declaration in bootstrap path.
- Integration/E2E suite runs against both storage backends.
- Validation report covering create order, edit order, refunds/partial updates, and admin list rendering.

### Exit criteria
- No metadata loss/regression between stores.
- Identical checkout outcomes for representative carts across both stores.

## Phase 4 — Enable caching + API hardening + observability

### Scope
- Introduce safe quote caching with deterministic keys and conservative TTL.
- Harden external API integration:
  - Timeouts, retry policy, bounded backoff.
  - Circuit-breaker style fail-open/fail-safe behavior for checkout continuity.
- Add observability: structured logs, counters, and timing around quote flow.

### Deliverables
- Cache invalidation policy documented (inputs, TTL, bust conditions).
- Error taxonomy for API failures (client, server, timeout, validation).
- Dashboard/alerts for:
  - API error rate.
  - P95 checkout quote latency.
  - Cache hit ratio.
  - Rate mismatch anomalies.

### Exit criteria
- Measurable latency improvement with no quote correctness regression.
- Alerts validated in staging via synthetic failure drills.

## Phase 5 — Turn on new checkout rates in production gradually

### Scope
- Progressive delivery of new path with explicit rollout cohorts.
- Canary strategy by traffic segment/store cohort with live monitoring.

### Rollout steps
1. Enable for internal/test stores.
2. Expand to low-risk production cohort.
3. Increase gradually (for example: 5% → 25% → 50% → 100%).
4. Hold between steps for observation windows and checkpoint reviews.

### Guardrails
- Automatic rollback trigger thresholds for error/latency/conversion deltas.
- On-call runbook for manual disable of feature flag.
- Daily comparison report (new vs legacy) during rollout.

### Exit criteria
- Stable conversion and error metrics at 100% cohort.
- No unresolved severity-1/2 incidents tied to checkout rates.

## Phase 6 — Remove deprecated legacy paths after monitoring period

### Scope
- After sustained stability window, remove legacy code and flags.
- Clean up compatibility shims no longer needed.
- Keep migration notes and rollback strategy for one release cycle.

### Deliverables
- Deleted legacy registration and dead branch logic.
- Reduced operational overhead (single code path, fewer toggles).
- Post-removal documentation update and changelog entry.

### Exit criteria
- Monitoring window completed with no rollback signal.
- Support playbooks and docs updated to single-path architecture.

## Recommended release mapping
- **vNext.1**: Phase 1 + scaffolding for Phase 2 flags.
- **vNext.2**: Phase 2 + Phase 3 validation assets.
- **vNext.3**: Phase 4 (performance/reliability/observability).
- **vNext.4**: Phase 5 rollout execution.
- **vNext.5**: Phase 6 cleanup.

## Risks and mitigations
- **Risk:** Silent quote drift between legacy and new paths.
  - **Mitigation:** Dual-run comparator logs in staging and canary cohorts.
- **Risk:** HPOS edge-case metadata mismatch.
  - **Mitigation:** Cross-store E2E tests + adapter normalization layer.
- **Risk:** Cache serves stale rates.
  - **Mitigation:** Short TTL, deterministic key inputs, targeted invalidation.
- **Risk:** API instability during peak checkout.
  - **Mitigation:** Timeouts, retry caps, circuit-breaker behavior, and fallback path.
