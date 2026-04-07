<div class="wrap">
    <?php
    $authRows = [];
    if (is_array($connectionTest) && !empty($connectionTest['raw_xml']) && function_exists('simplexml_load_string')) {
        $xml = @simplexml_load_string((string) $connectionTest['raw_xml']);
        if ($xml instanceof SimpleXMLElement) {
            $agreements = $xml->xpath('//transport_agreement');
            if (is_array($agreements)) {
                foreach ($agreements as $agreement) {
                    if (!$agreement instanceof SimpleXMLElement) {
                        continue;
                    }
                    $agreementId = trim((string) ($agreement->agreement_id ?? $agreement->id ?? ''));
                    $agreementName = trim((string) ($agreement->agreement_name ?? $agreement->name ?? ''));
                    $products = $agreement->xpath('./products/product');
                    if (!is_array($products) || $products === []) {
                        $products = $agreement->xpath('.//product');
                    }
                    if (!is_array($products) || $products === []) {
                        $authRows[] = [
                            'agreement_id' => $agreementId,
                            'agreement_name' => $agreementName,
                            'product_id' => '',
                            'product_name' => '',
                            'services' => [],
                        ];
                        continue;
                    }
                    foreach ($products as $product) {
                        if (!$product instanceof SimpleXMLElement) {
                            continue;
                        }
                        $services = [];
                        $serviceNodes = $product->xpath('./services/service');
                        if (!is_array($serviceNodes) || $serviceNodes === []) {
                            $serviceNodes = $product->xpath('.//service');
                        }
                        if (is_array($serviceNodes)) {
                            foreach ($serviceNodes as $service) {
                                if (!$service instanceof SimpleXMLElement) {
                                    continue;
                                }
                                $serviceId = trim((string) ($service->service_id ?? $service->id ?? ''));
                                $serviceName = trim((string) ($service->service_name ?? $service->name ?? ''));
                                if ($serviceId !== '' || $serviceName !== '') {
                                    $services[] = trim($serviceId . ' ' . $serviceName);
                                }
                            }
                        }
                        $authRows[] = [
                            'agreement_id' => $agreementId,
                            'agreement_name' => $agreementName,
                            'product_id' => trim((string) ($product->product_id ?? $product->id ?? '')),
                            'product_name' => trim((string) ($product->product_name ?? $product->name ?? '')),
                            'services' => $services,
                        ];
                    }
                }
            }
        }
    }
    ?>
    <h1>Cargonizer</h1>

    <?php if (isset($_GET['updated'])) : ?>
        <div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
    <?php endif; ?>

    <?php if (isset($_GET['refreshed']) && is_array($refreshResult)) : ?>
        <div class="notice <?php echo esc_attr(!empty($refreshResult['ok']) ? 'notice-success' : 'notice-warning'); ?> is-dismissible">
            <p><?php echo esc_html(!empty($refreshResult['ok']) ? sprintf('Transport agreements refreshed. %d methods available.', (int) ($refreshResult['count'] ?? 0)) : 'No methods could be loaded from transport agreements.'); ?></p>
        </div>
    <?php endif; ?>

    <h2>Connection</h2>
    <table class="widefat striped" style="max-width:900px; margin-bottom: 16px;">
        <tbody>
            <tr>
                <th style="width: 220px;">Stored API key</th>
                <td><code><?php echo esc_html((string) ($maskedCredentials['api_key'] ?? '(not set)')); ?></code></td>
            </tr>
            <tr>
                <th>Stored sender ID</th>
                <td><code><?php echo esc_html((string) ($maskedCredentials['sender_id'] ?? '(not set)')); ?></code></td>
            </tr>
        </tbody>
    </table>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('lp_cargonizer_save'); ?>
        <input type="hidden" name="action" value="lp_cargonizer_save" />

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="lp-cargonizer-api-key">API key</label></th>
                <td>
                    <input id="lp-cargonizer-api-key" type="password" autocomplete="new-password" name="lp_cargonizer_settings[api_key]" value="" class="regular-text" placeholder="Leave blank to keep current key" />
                    <p class="description">Leave blank to keep existing key. Enter a new key to replace it.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="lp-cargonizer-sender-id">Sender ID</label></th>
                <td>
                    <input id="lp-cargonizer-sender-id" type="text" name="lp_cargonizer_settings[sender_id]" value="" class="regular-text" placeholder="Leave blank to keep current sender" />
                    <p class="description">Use the sender/user relation ID from Cargonizer Preferences exactly as shown.</p>
                </td>
            </tr>
        </table>

        <h2>Available methods</h2>
        <p>Refresh from Cargonizer to sync agreements and products, then configure per-method pricing.</p>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Enable</th>
                    <th>Method overview</th>
                    <th>Pricing</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($methods)) : ?>
                    <tr><td colspan="3">No methods stored yet. Use the refresh button below.</td></tr>
                <?php else : ?>
                    <?php foreach ($methods as $method) : ?>
                        <?php
                        $methodId = (string) ($method['method_id'] ?? '');
                        $methodPricing = isset($settings['method_pricing'][$methodId]) && is_array($settings['method_pricing'][$methodId]) ? $settings['method_pricing'][$methodId] : [];
                        $isEnabled = (string) ($method['enabled'] ?? 'yes') !== 'no';
                        $services = isset($method['services']) && is_array($method['services']) ? $method['services'] : [];
                        ?>
                        <tr>
                            <td style="width: 90px; vertical-align: top;">
                                <label>
                                    <input type="checkbox" name="lp_cargonizer_settings[enabled_methods][]" value="<?php echo esc_attr($methodId); ?>" <?php checked($isEnabled); ?> /> Enabled
                                </label>
                            </td>
                            <td style="vertical-align: top; min-width: 360px;">
                                <strong><?php echo esc_html((string) ($method['title'] ?? $methodId)); ?></strong>
                                <ul style="margin: 6px 0 0 18px;">
                                    <li><strong>Carrier:</strong> <?php echo esc_html(trim((string) ($method['carrier_name'] ?? '')) ?: 'n/a'); ?> (<?php echo esc_html(trim((string) ($method['carrier_id'] ?? '')) ?: 'n/a'); ?>)</li>
                                    <li><strong>Agreement:</strong> <?php echo esc_html(trim((string) ($method['agreement_name'] ?? '')) ?: 'n/a'); ?> (ID: <?php echo esc_html(trim((string) ($method['agreement_id'] ?? '')) ?: 'n/a'); ?>)</li>
                                    <li><strong>Agreement description:</strong> <?php echo esc_html(trim((string) ($method['agreement_description'] ?? '')) ?: 'n/a'); ?></li>
                                    <li><strong>Agreement number:</strong> <?php echo esc_html(trim((string) ($method['agreement_number'] ?? '')) ?: 'n/a'); ?></li>
                                    <li><strong>Product:</strong> <?php echo esc_html(trim((string) ($method['product_name'] ?? '')) ?: 'n/a'); ?> (ID: <?php echo esc_html(trim((string) ($method['product_id'] ?? '')) ?: 'n/a'); ?>)</li>
                                    <li><strong>Services:</strong> <?php echo esc_html($services !== [] ? implode(', ', array_map('strval', $services)) : 'n/a'); ?></li>
                                    <li><code><?php echo esc_html($methodId); ?></code></li>
                                </ul>
                            </td>
                            <td style="vertical-align: top; min-width: 450px;">
                                <p>
                                    <label>price_source
                                        <select name="lp_cargonizer_settings[method_pricing][<?php echo esc_attr($methodId); ?>][price_source]">
                                            <?php foreach (['estimated', 'net', 'gross', 'fallback', 'manual_norgespakke'] as $source) : ?>
                                                <option value="<?php echo esc_attr($source); ?>" <?php selected((string) ($methodPricing['price_source'] ?? 'estimated'), $source); ?>><?php echo esc_html($source); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                </p>
                                <p><label>discount_percent <input type="number" step="0.01" name="lp_cargonizer_settings[method_pricing][<?php echo esc_attr($methodId); ?>][discount_percent]" value="<?php echo esc_attr((string) ($methodPricing['discount_percent'] ?? 0)); ?>" /></label></p>
                                <p><label>fuel_surcharge (%) <input type="number" step="0.01" name="lp_cargonizer_settings[method_pricing][<?php echo esc_attr($methodId); ?>][fuel_surcharge]" value="<?php echo esc_attr((string) ($methodPricing['fuel_surcharge'] ?? 0)); ?>" /></label></p>
                                <p><label>toll_surcharge <input type="number" step="0.01" name="lp_cargonizer_settings[method_pricing][<?php echo esc_attr($methodId); ?>][toll_surcharge]" value="<?php echo esc_attr((string) ($methodPricing['toll_surcharge'] ?? 0)); ?>" /></label></p>
                                <p><label>handling_fee <input type="number" step="0.01" name="lp_cargonizer_settings[method_pricing][<?php echo esc_attr($methodId); ?>][handling_fee]" value="<?php echo esc_attr((string) ($methodPricing['handling_fee'] ?? 0)); ?>" /></label></p>
                                <p><label>vat_percent <input type="number" step="0.01" name="lp_cargonizer_settings[method_pricing][<?php echo esc_attr($methodId); ?>][vat_percent]" value="<?php echo esc_attr((string) ($methodPricing['vat_percent'] ?? 0)); ?>" /></label></p>
                                <p>
                                    <label>rounding_mode
                                        <select name="lp_cargonizer_settings[method_pricing][<?php echo esc_attr($methodId); ?>][rounding_mode]">
                                            <?php foreach (['none', 'nearest_1', 'nearest_10', 'price_ending_9'] as $mode) : ?>
                                                <option value="<?php echo esc_attr($mode); ?>" <?php selected((string) ($methodPricing['rounding_mode'] ?? 'none'), $mode); ?>><?php echo esc_html($mode); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                </p>
                                <p><label>delivery_to_pickup_point <input type="number" step="0.01" name="lp_cargonizer_settings[method_pricing][<?php echo esc_attr($methodId); ?>][delivery_to_pickup_point]" value="<?php echo esc_attr((string) ($methodPricing['delivery_to_pickup_point'] ?? 0)); ?>" /></label></p>
                                <p><label>delivery_to_home <input type="number" step="0.01" name="lp_cargonizer_settings[method_pricing][<?php echo esc_attr($methodId); ?>][delivery_to_home]" value="<?php echo esc_attr((string) ($methodPricing['delivery_to_home'] ?? 0)); ?>" /></label></p>
                                <p>
                                    <label>
                                        <input type="checkbox" name="lp_cargonizer_settings[method_pricing][<?php echo esc_attr($methodId); ?>][manual_norgespakke_include_handling]" value="1" <?php checked(!empty($methodPricing['manual_norgespakke_include_handling'])); ?> />
                                        manual_norgespakke_include_handling
                                    </label>
                                </p>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php submit_button('Save settings'); ?>
    </form>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:16px; margin-top: 8px;">
        <?php wp_nonce_field('lp_cargonizer_refresh_methods'); ?>
        <input type="hidden" name="action" value="lp_cargonizer_refresh_methods" />
        <?php submit_button('Refresh transport agreements', 'secondary', 'submit', false); ?>
    </form>

    <h2>Diagnostics</h2>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:16px;">
        <?php wp_nonce_field('lp_cargonizer_test_connection'); ?>
        <input type="hidden" name="action" value="lp_cargonizer_test_connection" />
        <?php submit_button('Run connection test', 'secondary', 'submit', false); ?>
    </form>

    <?php if (is_array($connectionTest)) : ?>
        <div class="notice <?php echo esc_attr(!empty($connectionTest['ok']) ? 'notice-success' : 'notice-error'); ?>">
            <p>
                <strong><?php echo esc_html((string) ($connectionTest['message'] ?? 'Autentiseringstest fullført.')); ?></strong><br />
                Status: <?php echo esc_html((string) ($connectionTest['status'] ?? 0)); ?>,
                Correlation ID: <code><?php echo esc_html((string) ($connectionTest['correlation_id'] ?? 'n/a')); ?></code>,
                Request ID: <code><?php echo esc_html((string) ($connectionTest['request_id'] ?? 'n/a')); ?></code><br />
                Endpoint: <code><?php echo esc_html((string) ($connectionTest['endpoint'] ?? 'n/a')); ?></code>,
                Final URL: <code><?php echo esc_html((string) ($connectionTest['final_url'] ?? 'n/a')); ?></code>
            </p>
            <?php if (!empty($authRows)) : ?>
                <h3>Resultater fra autentiseringstest (transportavtaler/produkter/tjenester)</h3>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Transportavtale-ID</th>
                            <th>Transportavtale</th>
                            <th>Produkt-ID</th>
                            <th>Produkt</th>
                            <th>Tjenester</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($authRows as $row) : ?>
                            <tr>
                                <td><code><?php echo esc_html((string) ($row['agreement_id'] ?? '')); ?></code></td>
                                <td><?php echo esc_html((string) ($row['agreement_name'] ?? '')); ?></td>
                                <td><code><?php echo esc_html((string) ($row['product_id'] ?? '')); ?></code></td>
                                <td><?php echo esc_html((string) ($row['product_name'] ?? '')); ?></td>
                                <td><?php echo esc_html(!empty($row['services']) ? implode(', ', array_map('strval', (array) $row['services'])) : 'Ingen'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <?php if (!empty($connectionTest['raw_xml'])) : ?>
                <details>
                    <summary>Rå XML-respons</summary>
                    <pre style="max-height: 320px; overflow:auto;"><?php echo esc_html((string) $connectionTest['raw_xml']); ?></pre>
                </details>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <table class="widefat striped" style="max-width:900px;">
        <tbody>
            <tr>
                <th>Last error</th>
                <td><pre><?php echo esc_html(wp_json_encode($diagnostics['last_error'] ?? [], JSON_PRETTY_PRINT)); ?></pre></td>
            </tr>
            <tr>
                <th>Cache status</th>
                <td><pre><?php echo esc_html(wp_json_encode($diagnostics['cache'] ?? [], JSON_PRETTY_PRINT)); ?></pre></td>
            </tr>
        </tbody>
    </table>
</div>
