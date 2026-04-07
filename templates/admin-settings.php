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
                <td><input id="lp-cargonizer-api-key" type="text" name="lp_cargonizer_settings[api_key]" value="<?php echo esc_attr($settings['api_key'] ?? ''); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th scope="row"><label for="lp-cargonizer-sender-id">Sender ID</label></th>
                <td><input id="lp-cargonizer-sender-id" type="text" name="lp_cargonizer_settings[sender_id]" value="<?php echo esc_attr($settings['sender_id'] ?? ''); ?>" class="regular-text" /></td>
            </tr>
        </table>

        <?php submit_button('Save settings'); ?>
    </form>

    <h2>Registered methods</h2>
    <pre><?php echo esc_html(wp_json_encode($methods, JSON_PRETTY_PRINT)); ?></pre>
</div>
