# Staged rollout checklist

Reference: `docs/checkout-modernization-plan.md` for the full six-phase modernization program.

## 1) Staging soak
- Deploy candidate to staging with production-like WooCommerce and payment gateways.
- Run full test pyramid and manual checkout validation for at least 24h soak.
- Verify quote caching, checkout metadata writes, and admin order column rendering.

## 2) Load test
- Generate representative checkout traffic by zone and shipping method.
- Measure API latency, cache hit rate, and checkout error rate.
- Confirm no regression in shipping method registration or pricing calculations.

## 3) Production monitored rollout
- Start with a small rollout slice (e.g., 5% of stores/tenants, if applicable).
- Monitor logs, Woo order conversion, and shipping quote failures.
- Expand to 25%, 50%, then 100% with explicit go/no-go checkpoints.

## 4) Rollback plan
- Keep previous plugin build ready for immediate redeploy.
- Preserve DB settings/options; migrations are additive and rollback-safe.
- If rollback is triggered, retain observability checks for 24h post-rollback.
