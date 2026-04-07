<?php

namespace Lilleprinsen\Cargonizer\Admin;

use Lilleprinsen\Cargonizer\Checkout\CheckoutService;

final class AdminOrderController
{
    public function renderOrderEstimatorButton(): void
    {
        if (!$this->isSingleShopOrderEditScreen() || !current_user_can('manage_woocommerce')) {
            return;
        }

        echo '<p class="form-field form-field-wide lp-cargonizer-order-estimator">';
        echo '<button type="button" class="button button-secondary" id="lp-cargonizer-open-estimator">';
        echo esc_html__('Estimer fraktkostnad', 'lp-cargonizer');
        echo '</button>';
        echo '</p>';
    }

    public function renderOrderEstimatorModal(): void
    {
        if (!$this->isSingleShopOrderEditScreen() || !current_user_can('manage_woocommerce')) {
            return;
        }

        $orderId = isset($_GET['id']) ? absint($_GET['id']) : absint($_GET['post'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ($orderId <= 0) {
            return;
        }

        $nonces = [
            'get_order_estimate_data' => wp_create_nonce('lp_cargonizer_get_order_estimate_data'),
            'get_shipping_options' => wp_create_nonce('lp_cargonizer_get_shipping_options'),
            'run_bulk_estimate' => wp_create_nonce('lp_cargonizer_run_bulk_estimate'),
            'get_servicepartner_options' => wp_create_nonce('lp_cargonizer_get_servicepartner_options'),
        ];

        echo '<div id="lp-cargonizer-estimator-modal" class="lp-cargonizer-estimator-modal" style="display:none;"';
        echo ' data-order-id="' . esc_attr((string) $orderId) . '"';
        echo ' data-get-order-estimate-data-nonce="' . esc_attr($nonces['get_order_estimate_data']) . '"';
        echo ' data-get-shipping-options-nonce="' . esc_attr($nonces['get_shipping_options']) . '"';
        echo ' data-run-bulk-estimate-nonce="' . esc_attr($nonces['run_bulk_estimate']) . '"';
        echo ' data-get-servicepartner-options-nonce="' . esc_attr($nonces['get_servicepartner_options']) . '">';
        echo '<div class="lp-cargonizer-estimator-modal__backdrop" data-action="close"></div>';
        echo '<div class="lp-cargonizer-estimator-modal__content" role="dialog" aria-modal="true" aria-label="' . esc_attr__('Cargonizer fraktestimat', 'lp-cargonizer') . '">';
        echo '<div class="lp-cargonizer-estimator-modal__header">';
        echo '<h3>' . esc_html__('Cargonizer fraktestimat', 'lp-cargonizer') . '</h3>';
        echo '<button type="button" class="button-link" data-action="close" aria-label="' . esc_attr__('Lukk', 'lp-cargonizer') . '">✕</button>';
        echo '</div>';

        echo '<div class="lp-cargonizer-estimator-layout">';
        echo '<div class="lp-cargonizer-estimator-grid">';
        echo '<section><h4>' . esc_html__('Ordresammendrag', 'lp-cargonizer') . '</h4><div class="lp-cargonizer-order-summary"></div></section>';
        echo '<section><h4>' . esc_html__('Mottaker', 'lp-cargonizer') . '</h4><div class="lp-cargonizer-recipient-summary"></div></section>';
        echo '</div>';

        echo '<section><h4>' . esc_html__('Ordrelinjer', 'lp-cargonizer') . '</h4><div class="lp-cargonizer-order-lines"></div></section>';

        echo '<section>';
        echo '<h4>' . esc_html__('Kolli / Pakker', 'lp-cargonizer') . '</h4>';
        echo '<div class="lp-cargonizer-colli-controls">';
        echo '<button type="button" class="button" data-action="add-package">' . esc_html__('Legg til pakke', 'lp-cargonizer') . '</button>';
        echo '<span class="description" data-role="colli-validation"></span>';
        echo '</div>';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Beskrivelse', 'lp-cargonizer') . '</th>';
        echo '<th>' . esc_html__('L (cm)', 'lp-cargonizer') . '</th>';
        echo '<th>' . esc_html__('B (cm)', 'lp-cargonizer') . '</th>';
        echo '<th>' . esc_html__('H (cm)', 'lp-cargonizer') . '</th>';
        echo '<th>' . esc_html__('Vekt (kg)', 'lp-cargonizer') . '</th>';
        echo '<th>' . esc_html__('Volum (m³)', 'lp-cargonizer') . '</th>';
        echo '<th></th>';
        echo '</tr></thead><tbody class="lp-cargonizer-colli-body"></tbody></table>';
        echo '</section>';

        echo '<section>';
        echo '<h4>' . esc_html__('Fraktmetoder', 'lp-cargonizer') . '</h4>';
        echo '<div class="lp-cargonizer-method-actions">';
        echo '<button type="button" class="button button-small" data-action="select-all-methods">' . esc_html__('Velg alle', 'lp-cargonizer') . '</button>';
        echo '<button type="button" class="button button-small" data-action="clear-all-methods">' . esc_html__('Fjern alle', 'lp-cargonizer') . '</button>';
        echo '</div>';
        echo '<div class="lp-cargonizer-method-list"></div>';
        echo '</section>';

        echo '<section><h4>' . esc_html__('Resultater', 'lp-cargonizer') . '</h4><div class="lp-cargonizer-estimator-results"></div></section>';
        echo '</div>';

        echo '<div class="lp-cargonizer-estimator-modal__footer">';
        echo '<button type="button" class="button" data-action="close">' . esc_html__('Lukk', 'lp-cargonizer') . '</button>';
        echo '<button type="button" class="button button-primary" data-action="run-estimate">' . esc_html__('Kjør estimat', 'lp-cargonizer') . '</button>';
        echo '<button type="button" class="button" data-action="retry" style="display:none;">' . esc_html__('Prøv igjen', 'lp-cargonizer') . '</button>';
        echo '</div>';

        echo '</div></div>';

        $this->renderEstimatorAssets();
    }

    public function registerLegacyOrderColumns(array $columns): array
    {
        return $this->injectCargonizerColumn($columns);
    }

    public function registerHposOrderColumns(array $columns): array
    {
        return $this->injectCargonizerColumn($columns);
    }

    public function renderLegacyOrderColumn(string $column, int $postId): void
    {
        if ($column !== 'lp_cargonizer_shipment') {
            return;
        }

        $this->renderShipmentMetaForOrderId($postId);
    }

    /**
     * @param int|\WC_Order $order
     */
    public function renderHposOrderColumn(string $column, $order): void
    {
        if ($column !== 'lp_cargonizer_shipment') {
            return;
        }

        $orderId = $order instanceof \WC_Order ? $order->get_id() : (int) $order;
        $this->renderShipmentMetaForOrderId($orderId);
    }

    /**
     * @param \WC_Order|\WC_Order_Refund $order
     */
    public function renderOrderShipmentPanel($order): void
    {
        if (!$order instanceof \WC_Order) {
            return;
        }

        $this->renderShipmentMeta($order);
    }

    private function injectCargonizerColumn(array $columns): array
    {
        $newColumns = [];
        foreach ($columns as $key => $label) {
            if ($key === 'order_total') {
                $newColumns['lp_cargonizer_shipment'] = __('Cargonizer', 'lp-cargonizer');
            }
            $newColumns[$key] = $label;
        }

        if (!isset($newColumns['lp_cargonizer_shipment'])) {
            $newColumns['lp_cargonizer_shipment'] = __('Cargonizer', 'lp-cargonizer');
        }

        return $newColumns;
    }

    private function renderShipmentMetaForOrderId(int $orderId): void
    {
        if (!function_exists('wc_get_order')) {
            echo '—';
            return;
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof \WC_Order) {
            echo '—';
            return;
        }

        $this->renderShipmentMeta($order);
    }

    private function renderShipmentMeta(\WC_Order $order): void
    {
        $quoteId = $order->get_meta(CheckoutService::META_QUOTE_ID, true);
        $shipmentId = $order->get_meta(CheckoutService::META_SHIPMENT_ID, true);

        if ($quoteId === '' && $shipmentId === '') {
            echo '—';
            return;
        }

        $parts = [];
        if ($quoteId !== '') {
            $parts[] = sprintf(
                '%s: %s',
                esc_html__('Quote', 'lp-cargonizer'),
                esc_html((string) $quoteId)
            );
        }

        if ($shipmentId !== '') {
            $parts[] = sprintf(
                '%s: %s',
                esc_html__('Shipment', 'lp-cargonizer'),
                esc_html((string) $shipmentId)
            );
        }

        echo wp_kses_post(implode('<br>', $parts));
    }

    private function isSingleShopOrderEditScreen(): bool
    {
        if (!is_admin() || !function_exists('get_current_screen')) {
            return false;
        }

        $screen = get_current_screen();
        if (!$screen instanceof \WP_Screen) {
            return false;
        }

        if ($screen->base === 'post' && $screen->post_type === 'shop_order') {
            return true;
        }

        if ($screen->id === 'woocommerce_page_wc-orders' && isset($_GET['action']) && sanitize_key((string) wp_unslash($_GET['action'])) === 'edit') { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return true;
        }

        return false;
    }

    private function renderEstimatorAssets(): void
    {
        ?>
        <style>
            .lp-cargonizer-estimator-modal { position: fixed; inset: 0; z-index: 100000; }
            .lp-cargonizer-estimator-modal__backdrop { position: absolute; inset: 0; background: rgba(0,0,0,.45); }
            .lp-cargonizer-estimator-modal__content { position: relative; width: min(1100px, 94vw); max-height: 92vh; overflow: auto; margin: 4vh auto; background: #fff; border-radius: 4px; padding: 16px; }
            .lp-cargonizer-estimator-modal__header, .lp-cargonizer-estimator-modal__footer { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
            .lp-cargonizer-estimator-layout { display: grid; gap: 16px; }
            .lp-cargonizer-estimator-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
            .lp-cargonizer-method-list { max-height: 180px; overflow: auto; border: 1px solid #ddd; padding: 8px; }
            .lp-cargonizer-method-list label { display: block; margin-bottom: 4px; }
            .lp-cargonizer-colli-body input[type="number"], .lp-cargonizer-colli-body input[type="text"] { width: 100%; }
            .lp-cargonizer-colli-controls { display:flex; align-items:center; justify-content: space-between; margin-bottom:8px; }
            .lp-cargonizer-error { color: #b32d2e; }
            @media (max-width: 900px) { .lp-cargonizer-estimator-grid { grid-template-columns: 1fr; } }
        </style>
        <script>
            (function () {
                const modal = document.getElementById('lp-cargonizer-estimator-modal');
                const openBtn = document.getElementById('lp-cargonizer-open-estimator');
                if (!modal || !openBtn) return;

                const state = { orderData: null, methods: [], packages: [], selectedMethods: new Set(), lastPayload: null, methodOverrides: {} };
                const endpoints = {
                    orderData: '<?php echo esc_js(admin_url('admin-ajax.php')); ?>?action=lp_cargonizer_get_order_estimate_data',
                    methods: '<?php echo esc_js(admin_url('admin-ajax.php')); ?>?action=lp_cargonizer_get_shipping_options',
                    run: '<?php echo esc_js(admin_url('admin-ajax.php')); ?>?action=lp_cargonizer_run_bulk_estimate',
                    servicepartners: '<?php echo esc_js(admin_url('admin-ajax.php')); ?>?action=lp_cargonizer_get_servicepartner_options'
                };
                const summaryEl = modal.querySelector('.lp-cargonizer-order-summary');
                const recipientEl = modal.querySelector('.lp-cargonizer-recipient-summary');
                const linesEl = modal.querySelector('.lp-cargonizer-order-lines');
                const colliBody = modal.querySelector('.lp-cargonizer-colli-body');
                const colliValidation = modal.querySelector('[data-role="colli-validation"]');
                const methodsEl = modal.querySelector('.lp-cargonizer-method-list');
                const resultsEl = modal.querySelector('.lp-cargonizer-estimator-results');
                const retryBtn = modal.querySelector('[data-action="retry"]');

                const formatNum = (v, d = 2) => Number(v || 0).toLocaleString('no-NO', {minimumFractionDigits: d, maximumFractionDigits: d});
                const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (m) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
                const boolText = (v) => v ? 'Ja' : 'Nei';
                const fetchJson = async (url, body) => {
                    const response = await fetch(url, { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'}, body: new URLSearchParams(body) });
                    return response.json();
                };

                const renderOrderSummary = (o) => {
                    summaryEl.innerHTML = `<p><strong>Ordrenummer:</strong> #${esc(o.order_number || o.order_id)}</p><p><strong>Dato:</strong> ${esc(o.order_date || '')}</p><p><strong>Total:</strong> ${esc(o.total_formatted || '')}</p>`;
                };

                const renderRecipient = (r) => {
                    recipientEl.innerHTML = `<p><strong>Navn:</strong> ${esc(r.name || '')}</p><p><strong>Adresse:</strong> ${esc(r.address || '')}<br>${esc(r.postal || '')}</p><p><strong>E-post:</strong> ${esc(r.email || '')}</p><p><strong>Telefon:</strong> ${esc(r.phone || '')}</p>`;
                };

                const renderLines = (lines) => {
                    if (!Array.isArray(lines) || !lines.length) { linesEl.innerHTML = '<p>Ingen ordrelinjer.</p>'; return; }
                    linesEl.innerHTML = `<table class="widefat striped"><thead><tr><th>Produkt</th><th>Antall</th><th>Total</th></tr></thead><tbody>${lines.map((l) => `<tr><td>${esc(l.name)}</td><td>${esc(l.quantity)}</td><td>${esc(l.total)}</td></tr>`).join('')}</tbody></table>`;
                };

                const newPackage = (name = 'Pakke') => ({ description: name, length: 10, width: 10, height: 10, weight: 1 });
                const packageVolume = (p) => ((Number(p.length)||0) * (Number(p.width)||0) * (Number(p.height)||0)) / 1000000;
                const totalWeight = () => state.packages.reduce((sum, p) => sum + (Number(p.weight) || 0), 0);
                const totalVolume = () => state.packages.reduce((sum, p) => sum + packageVolume(p), 0);

                const validatePackages = () => {
                    if (!state.packages.length) {
                        colliValidation.textContent = 'Minst én pakke er påkrevd.';
                        colliValidation.classList.add('lp-cargonizer-error');
                        return false;
                    }
                    const hasInvalid = state.packages.some((p) => [p.length, p.width, p.height, p.weight].some((v) => Number(v) <= 0 || Number.isNaN(Number(v))));
                    if (hasInvalid) {
                        colliValidation.textContent = 'Alle kolli-mål og vekt må være numeriske verdier større enn 0.';
                        colliValidation.classList.add('lp-cargonizer-error');
                        return false;
                    }
                    colliValidation.textContent = `Antall kolli: ${state.packages.length} · Totalvolum: ${formatNum(totalVolume(), 4)} m³`;
                    colliValidation.classList.remove('lp-cargonizer-error');
                    return true;
                };

                const renderPackages = () => {
                    colliBody.innerHTML = state.packages.map((p, i) => `<tr data-index="${i}"><td><input type="text" data-key="description" value="${esc(p.description)}"></td><td><input type="number" min="0.01" step="0.01" data-key="length" value="${esc(p.length)}"></td><td><input type="number" min="0.01" step="0.01" data-key="width" value="${esc(p.width)}"></td><td><input type="number" min="0.01" step="0.01" data-key="height" value="${esc(p.height)}"></td><td><input type="number" min="0.01" step="0.01" data-key="weight" value="${esc(p.weight)}"></td><td>${formatNum(packageVolume(p),4)}</td><td><button type="button" class="button-link-delete" data-action="remove-package">Fjern</button></td></tr>`).join('');
                    validatePackages();
                };

                const renderMethods = (methods) => {
                    const enabled = (methods || []).filter((m) => m && m.enabled !== false);
                    state.methods = enabled;
                    if (!state.selectedMethods.size) enabled.forEach((m) => state.selectedMethods.add(m.method_id));
                    methodsEl.innerHTML = enabled.map((m) => `<label><input type="checkbox" data-method="${esc(m.method_id)}" ${state.selectedMethods.has(m.method_id) ? 'checked' : ''}> ${esc(m.title || m.method_id)}</label>`).join('') || '<p>Ingen aktive Cargonizer-metoder funnet.</p>';
                };

                const buildDefaultPackages = (lines, packages) => {
                    if (Array.isArray(packages) && packages.length) {
                        state.packages = packages.map((pkg) => ({
                            description: pkg.description || 'Pakke',
                            length: Number(pkg.length) || 0,
                            width: Number(pkg.width) || 0,
                            height: Number(pkg.height) || 0,
                            weight: Number(pkg.weight) || 0,
                        }));
                        renderPackages();
                        return;
                    }

                    const defaults = [];
                    (lines || []).forEach((line) => {
                        if (!line.separate_package) return;
                        const q = Math.max(1, Number(line.quantity) || 1);
                        for (let i = 0; i < q; i += 1) defaults.push(newPackage(line.separate_package_name || line.name || 'Pakke'));
                    });
                    state.packages = defaults.length ? defaults : [newPackage()];
                    renderPackages();
                };

                const buildDebugPanel = (result, errorText) => {
                    const estimate = result.estimate || {};
                    const debug = result.estimate_debug || {};
                    const selected = (debug.selected_source || {});
                    const calc = (debug.calculation || {});
                    const details = [];
                    if (errorText) details.push(`<p><strong>Feil:</strong> ${esc(errorText)}</p>`);
                    details.push(`<p><strong>Status:</strong> HTTP ${esc(estimate.http_status || 0)} · API OK: ${esc(boolText(estimate.ok === true))}</p>`);
                    details.push(`<p><strong>Kildevalg:</strong> ${esc(selected.source || 'ukjent')} (${esc(selected.fallback_reason || result.fallback_reason || ''))}</p>`);
                    details.push(`<p><strong>Valgt listpris:</strong> ${selected.value === null || selected.value === undefined ? 'n/a' : esc(formatNum(selected.value)) + ' kr'}</p>`);
                    if (result.fallback_rate !== null && result.fallback_rate !== undefined) {
                        details.push(`<p><strong>Fallback-sats:</strong> ${esc(formatNum(result.fallback_rate))} kr</p>`);
                    }
                    details.push(`<p><strong>Beregning:</strong> ${esc(calc.calculation_status || 'ukjent')}</p>`);
                    if (estimate.debug) details.push(`<pre>${esc(JSON.stringify(estimate.debug, null, 2))}</pre>`);
                    if (debug.errors) details.push(`<pre>${esc(JSON.stringify(debug.errors, null, 2))}</pre>`);
                    if (estimate.errors) details.push(`<pre>${esc(JSON.stringify(estimate.errors, null, 2))}</pre>`);
                    if (estimate.raw_xml) details.push(`<details><summary>Rå XML</summary><pre>${esc(estimate.raw_xml)}</pre></details>`);
                    if (estimate.request_xml) details.push(`<details><summary>Request XML</summary><pre>${esc(estimate.request_xml)}</pre></details>`);
                    return `<details><summary>Vis debug/detaljer</summary>${details.join('')}</details>`;
                };

                const runEstimate = async () => {
                    resultsEl.innerHTML = '<p>Henter estimater …</p>';
                    retryBtn.style.display = 'none';
                    if (!validatePackages()) {
                        resultsEl.innerHTML = '<p class="lp-cargonizer-error">Kolli-oppsettet må rettes før estimat kan kjøres.</p>';
                        return;
                    }
                    const methodIds = Array.from(state.selectedMethods);
                    if (!methodIds.length) {
                        resultsEl.innerHTML = '<p class="lp-cargonizer-error">Velg minst én fraktmetode.</p>';
                        return;
                    }

                    const payload = {
                        nonce: modal.dataset.runBulkEstimateNonce,
                        order_id: modal.dataset.orderId,
                        method_ids: JSON.stringify(methodIds),
                        packages: JSON.stringify(state.packages),
                        method_overrides: JSON.stringify(state.methodOverrides)
                    };
                    state.lastPayload = payload;

                    try {
                        const data = await fetchJson(endpoints.run, payload);
                        if (!data.success) throw new Error((data.data && data.data.message) || 'Ukjent feil');
                        const allResults = Array.isArray(data.data.results) ? data.data.results : [];
                        const normalized = allResults.map((r) => {
                            const estimate = r.estimate || {};
                            const req = estimate.requirements || {};
                            const errors = Array.isArray(estimate.errors) ? estimate.errors : [];
                            const errorText = errors.map((e) => e && e.message ? e.message : '').filter(Boolean).join(' · ');
                            const calc = (r.estimate_debug || {}).calculation || {};
                            const selected = ((r.estimate_debug || {}).selected_source || {});
                            const statusOk = estimate.ok === true && r.rate !== null;
                            const deliveryFlags = Array.isArray(r.delivery_flags) ? r.delivery_flags : [];
                            const listPrice = selected && selected.value !== undefined && selected.value !== null ? Number(selected.value) : null;
                            const fuelAmount = calc.fuel_amount !== undefined && calc.fuel_amount !== null ? Number(calc.fuel_amount) : null;
                            return { r, req, errorText, statusOk, deliveryFlags, calc, selected, listPrice, fuelAmount };
                        });
                        const successful = normalized.filter((x) => x.statusOk).sort((a, b) => Number(a.r.rate || 0) - Number(b.r.rate || 0));
                        const failed = normalized.filter((x) => !x.statusOk);

                        const successfulRows = successful.map(({ r, req, errorText, calc, listPrice, fuelAmount, deliveryFlags }) => {
                            const methodOverride = state.methodOverrides[r.method_id] || {};
                            const servicepartnerOptions = Array.isArray(r.servicepartner_options) ? r.servicepartner_options : [];
                            const showServicepartner = req.servicepartner_required === true;
                            const showSms = req.sms_required === true || r.supports_sms === true;
                            return `<tr>
                                <td>${esc(r.title || r.method_id)}<br><small>${esc(r.method_id)}</small></td>
                                <td>${esc(deliveryFlags.join(' / ') || 'n/a')}</td>
                                <td>${listPrice === null ? 'n/a' : `${formatNum(listPrice)} kr`}<br><small>${esc((((r.estimate_debug || {}).selected_source || {}).source) || r.price_source || 'n/a')}</small></td>
                                <td>${formatNum(calc.discount_percent || 0)}%</td>
                                <td>${formatNum(calc.fuel_percent || 0)}%</td>
                                <td>${fuelAmount === null ? 'n/a' : `${formatNum(fuelAmount)} kr`}</td>
                                <td>${formatNum(calc.toll_fee || 0)} kr</td>
                                <td>${formatNum(calc.handling_fee || 0)} kr</td>
                                <td><strong>${r.rate === null ? 'n/a' : formatNum(r.rate) + ' kr'}</strong></td>
                                <td>${errorText ? `<span class="lp-cargonizer-error">Advarsel</span>` : 'OK'}</td>
                                <td>${buildDebugPanel(r, errorText)}</td>
                            </tr>`;
                        }).join('');

                        const failedRows = failed.map(({ r, req, errorText }) => {
                            const methodOverride = state.methodOverrides[r.method_id] || {};
                            const servicepartnerOptions = Array.isArray(r.servicepartner_options) ? r.servicepartner_options : [];
                            const showServicepartner = req.servicepartner_required === true;
                            const showSms = req.sms_required === true || r.supports_sms === true;
                            const missingServicepartner = showServicepartner && !String(methodOverride.service_partner || '').trim();
                            const missingSms = req.sms_required === true && !methodOverride.sms_enabled;
                            const servicepartnerSelect = showServicepartner ? `<div><label>Servicepartner:
                                <select data-action="set-servicepartner" data-method-id="${esc(r.method_id)}">
                                    <option value="">Velg …</option>
                                    ${servicepartnerOptions.map((sp) => `<option value="${esc(sp.id)}" ${String(methodOverride.service_partner || '') === String(sp.id) ? 'selected' : ''}>${esc(sp.name || sp.id)}</option>`).join('')}
                                </select></label></div>` : '';
                            const smsToggle = showSms ? `<label><input type="checkbox" data-action="toggle-sms" data-method-id="${esc(r.method_id)}" ${methodOverride.sms_enabled ? 'checked' : ''}> SMS-varsel</label>` : '';
                            const retryOne = (missingServicepartner || missingSms || showServicepartner || showSms)
                                ? `<button type="button" class="button button-small" data-action="retry-one" data-method-id="${esc(r.method_id)}">Prøv metode på nytt</button>`
                                : '';
                            return `<tr>
                                <td>${esc(r.title || r.method_id)}<br><small>${esc(r.method_id)}</small></td>
                                <td><span class="lp-cargonizer-error">${esc(errorText || 'Ingen pris returnert')}</span></td>
                                <td>${servicepartnerSelect}${smsToggle ? `<div>${smsToggle}</div>` : ''}${retryOne}</td>
                                <td>${buildDebugPanel(r, errorText)}</td>
                            </tr>`;
                        }).join('');

                        resultsEl.innerHTML = `
                            <h5>Vellykkede metoder (sortert på laveste sluttsum)</h5>
                            <table class="widefat striped">
                                <thead><tr><th>Metode</th><th>Leveringsmodus</th><th>Listepris / kilde</th><th>Rabatt %</th><th>Drivstoff %</th><th>Drivstoffbeløp</th><th>Bompenger</th><th>Håndtering</th><th>Sluttpris</th><th>Status</th><th>Debug / detaljer</th></tr></thead>
                                <tbody>${successfulRows || '<tr><td colspan="11">Ingen vellykkede metoder.</td></tr>'}</tbody>
                            </table>
                            <details ${failed.length ? '' : 'style="display:none;"'}>
                                <summary>Feilede metoder (${failed.length})</summary>
                                <table class="widefat striped">
                                    <thead><tr><th>Metode</th><th>Status/feil</th><th>Tiltak</th><th>Debug / detaljer</th></tr></thead>
                                    <tbody>${failedRows || '<tr><td colspan="4">Ingen feilede metoder.</td></tr>'}</tbody>
                                </table>
                            </details>`;
                    } catch (e) {
                        resultsEl.innerHTML = `<p class="lp-cargonizer-error">Kunne ikke hente estimat: ${esc(e.message || e)}</p>`;
                        retryBtn.style.display = '';
                    }
                };

                const retrySingleMethod = async (methodId) => {
                    if (!methodId) return;
                    state.selectedMethods = new Set([methodId]);
                    await runEstimate();
                };

                const loadModalData = async () => {
                    resultsEl.innerHTML = '<p>Laster ordredata …</p>';
                    const [orderDataRes, methodsRes] = await Promise.all([
                        fetchJson(endpoints.orderData, { nonce: modal.dataset.getOrderEstimateDataNonce, order_id: modal.dataset.orderId }),
                        fetchJson(endpoints.methods, { nonce: modal.dataset.getShippingOptionsNonce, order_id: modal.dataset.orderId })
                    ]);
                    if (!orderDataRes.success) throw new Error((orderDataRes.data && orderDataRes.data.message) || 'Kunne ikke laste ordredata.');
                    if (!methodsRes.success) throw new Error((methodsRes.data && methodsRes.data.message) || 'Kunne ikke laste fraktmetoder.');

                    state.orderData = orderDataRes.data;
                    renderOrderSummary(orderDataRes.data.order_summary || {});
                    renderRecipient(orderDataRes.data.recipient || {});
                    renderLines(orderDataRes.data.lines || []);
                    buildDefaultPackages(orderDataRes.data.lines || [], orderDataRes.data.packages || []);
                    renderMethods(methodsRes.data.methods || []);
                    resultsEl.innerHTML = '<p>Klar for estimat.</p>';
                };

                openBtn.addEventListener('click', async () => {
                    modal.style.display = 'block';
                    try { await loadModalData(); } catch (e) { resultsEl.innerHTML = `<p class="lp-cargonizer-error">${esc(e.message || e)}</p>`; retryBtn.style.display = ''; }
                });

                modal.addEventListener('click', (event) => {
                    const actionTarget = event.target.closest('[data-action]');
                    if (!actionTarget) return;
                    const action = actionTarget.dataset.action;
                    if (action === 'close') modal.style.display = 'none';
                    if (action === 'add-package') { state.packages.push(newPackage()); renderPackages(); }
                    if (action === 'remove-package') {
                        const row = event.target.closest('tr');
                        const idx = Number(row && row.dataset.index);
                        if (!Number.isNaN(idx)) { state.packages.splice(idx, 1); renderPackages(); }
                    }
                    if (action === 'select-all-methods') { state.methods.forEach((m) => state.selectedMethods.add(m.method_id)); renderMethods(state.methods); }
                    if (action === 'clear-all-methods') { state.selectedMethods.clear(); renderMethods(state.methods); }
                    if (action === 'run-estimate') runEstimate();
                    if (action === 'retry' && state.lastPayload) runEstimate();
                    if (action === 'retry-one') retrySingleMethod(actionTarget.dataset.methodId || '');
                });

                colliBody.addEventListener('input', (event) => {
                    const input = event.target;
                    const row = input.closest('tr');
                    if (!row) return;
                    const idx = Number(row.dataset.index);
                    const key = input.dataset.key;
                    if (Number.isNaN(idx) || !key || !state.packages[idx]) return;
                    state.packages[idx][key] = input.value;
                    if (['length', 'width', 'height', 'weight'].includes(key)) {
                        row.children[5].textContent = formatNum(packageVolume(state.packages[idx]), 4);
                    }
                    validatePackages();
                });

                methodsEl.addEventListener('change', (event) => {
                    const input = event.target;
                    if (!input || !input.dataset || !input.dataset.method) return;
                    if (input.checked) state.selectedMethods.add(input.dataset.method);
                    else state.selectedMethods.delete(input.dataset.method);
                });

                resultsEl.addEventListener('change', async (event) => {
                    const target = event.target;
                    if (!target || !target.dataset) return;
                    const methodId = target.dataset.methodId || '';
                    if (!methodId) return;
                    if (!state.methodOverrides[methodId]) state.methodOverrides[methodId] = {};

                    if (target.dataset.action === 'set-servicepartner') {
                        state.methodOverrides[methodId].service_partner = target.value || '';
                        return;
                    }

                    if (target.dataset.action === 'toggle-sms') {
                        state.methodOverrides[methodId].sms_enabled = !!target.checked;
                    }
                });

                resultsEl.addEventListener('focusin', async (event) => {
                    const target = event.target;
                    if (!target || !target.dataset || target.dataset.action !== 'set-servicepartner') return;
                    if (target.dataset.loaded === '1') return;
                    const methodId = target.dataset.methodId || '';
                    const destination = ((state.orderData || {}).package || {}).destination || {};
                    try {
                        const response = await fetchJson(endpoints.servicepartners, {
                            nonce: modal.dataset.getServicepartnerOptionsNonce,
                            method_id: methodId,
                            destination: JSON.stringify(destination)
                        });
                        if (!response.success) return;
                        const opts = (response.data && response.data.servicepartners) || [];
                        if (!Array.isArray(opts)) return;
                        target.innerHTML = `<option value="">Velg …</option>${opts.map((sp) => `<option value="${esc(sp.id)}">${esc(sp.name || sp.id)}</option>`).join('')}`;
                        target.dataset.loaded = '1';
                    } catch (e) {
                        // no-op
                    }
                });
            })();
        </script>
        <?php
    }
}
