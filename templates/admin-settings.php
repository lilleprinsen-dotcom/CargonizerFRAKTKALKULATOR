<div class="wrap">
    <h1>Cargonizer</h1>

    <?php if (isset($_GET['updated'])) : ?>
        <div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('lp_cargonizer_save'); ?>
        <input type="hidden" name="action" value="lp_cargonizer_save" />

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="lp-cargonizer-api-key">API key</label></th>
                <td>
                    <input id="lp-cargonizer-api-key" type="password" autocomplete="new-password" name="lp_cargonizer_settings[api_key]" value="<?php echo esc_attr($settings['api_key'] ?? ''); ?>" class="regular-text" />
                    <p class="description">Stored securely and masked in diagnostics and logs.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="lp-cargonizer-sender-id">Sender ID</label></th>
                <td><input id="lp-cargonizer-sender-id" type="text" name="lp_cargonizer_settings[sender_id]" value="<?php echo esc_attr($settings['sender_id'] ?? ''); ?>" class="regular-text" /></td>
            </tr>
        </table>

        <?php submit_button('Save settings'); ?>
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
                <strong><?php echo esc_html((string) ($connectionTest['message'] ?? 'Connection test finished.')); ?></strong><br />
                Status: <?php echo esc_html((string) ($connectionTest['status'] ?? 0)); ?>,
                Correlation ID: <code><?php echo esc_html((string) ($connectionTest['correlation_id'] ?? 'n/a')); ?></code>
            </p>
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

    <h2>Registered methods</h2>
    <pre><?php echo esc_html(wp_json_encode($methods, JSON_PRETTY_PRINT)); ?></pre>
</div>
