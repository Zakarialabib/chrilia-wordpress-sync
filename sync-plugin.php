<?php
/*
Plugin Name: Chrilia
Plugin URI: https://chrilia.com
Description: Syncs products with Chrilia search engine
Version: 1.0
Author: Zakaria Labib
Author URI: https://github.com/zakarialabib
License: GPL2
*/

register_activation_hook(__FILE__, 'chrilia_activate');
register_deactivation_hook(__FILE__, 'chrilia_deactivate');

function chrilia_activate()
{
    // Activation code here
}

function chrilia_deactivate()
{
    // Deactivation code here
}

add_action('admin_menu', 'chrilia_create_settings_page');

function chrilia_create_settings_page()
{
    add_options_page(
        'Chrilia Sync Settings',
        'Chrilia Sync',
        'manage_options',
        'chrilia-settings',
        'chrilia_render_settings_page'
    );
}
add_action('admin_init', 'chrilia_register_settings');

function chrilia_register_settings()
{
    register_setting(
        'chrilia_options',
        'chrilia_api_key'
    );

    add_settings_section(
        'chrilia_section',
        'API Key',
        'chrilia_section_callback',
        'chrilia-settings'
    );

    add_settings_field(
        'chrilia_api_key',
        'API Key',
        'chrilia_api_key_callback',
        'chrilia-settings',
        'chrilia_section'
    );
    
    add_settings_field(
        'chrilia_settings_link',
        'Chrilia Settings',
        'chrilia_settings_link_callback',
        'chrilia-settings',
        'chrilia_section'
    );
}

function chrilia_settings_link_callback() {
    $url = admin_url( 'options-general.php?page=chrilia-settings' );
    echo "<a href='$url'>Go to Chrilia Settings</a>";
}

function chrilia_api_key_callback()
{
    $api_key = get_option('chrilia_api_key');
?>
    <input type="text" name="chrilia_api_key" value="<?php echo esc_attr($api_key); ?>" />
    <p class="description">If you don't have an API key, please <a href="https://chrilia.com/register" target="_blank">register at Chrilia</a> to get one.</p>
<?php
}

function chrilia_section_callback()
{
?>
    <p>
        Enter your Chrilia project API key below. If you don't have an API key, you can register for one at
        <a href="https://chrilia.com">https://chrilia.com</a>.<br><br>
        Chrilia is a meta search engine that allows clients to search for products and shop from websites that sell them.
        By syncing your products with Chrilia, you can increase your visibility and reach new customers.<br><br>
        Once you have entered your API key, you can use the button below to sync your products with Chrilia.
    </p>
<?php
}

function chrilia_render_settings_page()
{
    $api_key = get_option('chrilia_api_key');
    $sync_history = get_option('chrilia_sync_history', array());
    $sync_status = get_option('chrilia_sync_status', array());
    $last_sync_date = get_option('chrilia_last_sync_date', array());

    if (isset($_POST['chrilia_api_key']) && $_POST['chrilia_api_key'] !== $api_key) {
        $api_key = sanitize_text_field($_POST['chrilia_api_key']);
        update_option('chrilia_api_key', $api_key);

        // Sync data if API key is changed
        $response = chrilia_sync_data();
        $sync_history[] = array(
            'date' => current_time('mysql'),
            'status' => $response['status'],
            'message' => $response['message'],
        );
        $sync_status = $response['status'];
        $last_sync_date = $response['status'] == 'success' ? current_time('mysql') : $last_sync_date;

        update_option('chrilia_sync_history', $sync_history);
        update_option('chrilia_sync_status', $sync_status);
        update_option('chrilia_last_sync_date', $last_sync_date);
    }

?>
    <div class="wrap">
        <h1>Chrilia Settings</h1>

        <?php if (!empty($sync_history)) : ?>
            <h2>Sync History</h2>
            <table class="widefat">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sync_history as $entry) : ?>
                        <tr>
                            <td><?php echo $entry['date']; ?></td>
                            <td><?php echo $entry['status']; ?></td>
                            <td><?php echo $entry['message']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h2>API Key</h2>
        <form method="post">
            <?php wp_nonce_field('chrilia_update_api_key', 'chrilia_nonce'); ?>
            <table class="form-table">
                <tbody>
                    <tr>
                        <th scope="row"><label for="chrilia_api_key">API Key</label></th>
                        <td><input name="chrilia_api_key" type="text" id="chrilia_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text"></td>
                    </tr>
                </tbody>
            </table>
            <?php submit_button('Save API Key'); ?>
        </form>

        <?php if ($sync_status) : ?>
            <div class="notice notice-<?php echo $sync_status; ?> is-dismissible">
                <p><?php echo $sync_status == 'success' ? 'Data synced successfully!' : 'Data sync failed!'; ?></p>
            </div>
        <?php endif; ?>

        <?php if ($last_sync_date) : ?>
            <p>Last sync date: <?php echo $last_sync_date; ?></p>
        <?php endif; ?>
    </div>
<?php
}

function chrilia_get_product_data()
{
    $products = wc_get_products(array(
        'status' => 'publish',
    ));

    $product_data = array();

    foreach ($products as $product) {
        $product_data[] = array(
            'name' => $product->get_name(),
            'price' => $product->get_price(),
            'category_name' => $product->get_category()->name,
            'brand_name' => $product->get_attribute('brand'),
            'image' => $product->get_image(),
            'status' => $product->get_status(),
            'code' => $product->get_sku(),
            'description' => $product->get_description(),
        );
    }

    return $product_data;
}

function chrilia_sync_data()
{

    $api_key = get_option('chrilia_api_key');

    $product_data = chrilia_get_product_data();

    $response = wp_remote_post('https://chrilia.com/api/products', array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json',
        ),
        'body' => json_encode($product_data),
    ));

    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        chrilia_log_error('Error sending product data to Chrilia API: ' . $error_message);
        add_settings_error('chrilia_settings', 'chrilia_sync_error', 'Failed to sync product data with Chrilia API: ' . $error_message, 'error');
    } else {
        $response_body = json_decode(wp_remote_retrieve_body($response));
        if ($response_body->success) {
            chrilia_log_sync('Product data synced successfully with Chrilia API');
            add_settings_error('chrilia_settings', 'chrilia_sync_success', 'Product data synced successfully with Chrilia API', 'success');
        } else {
            chrilia_log_error('Error sending product data to Chrilia API: ' . $response_body->error_message);
            add_settings_error('chrilia_settings', 'chrilia_sync_error', 'Failed to sync product data with Chrilia API: ' . $response_body->error_message, 'error');
        }
    }

    // Save sync history
    $sync_history = get_option('chrilia_sync_history', array());
    $sync_history[] = array(
        'timestamp' => current_time('timestamp'),
        'success' => !is_wp_error($response) && $response_body->success,
        'message' => !is_wp_error($response) ? '' : $error_message,
    );
    update_option('chrilia_sync_history', $sync_history);

    // Save sync status
    $sync_status = !is_wp_error($response) && $response_body->success ? 'success' : 'error';
    update_option('chrilia_sync_status', $sync_status);
}

function chrilia_log_error($message)
{
    // Save the error message to the database
    $sync_history = get_option('chrilia_sync_history');
    $sync_history[] = array(
        'message' => $message,
        'timestamp' => current_time('mysql'),
        'status' => 'error',
    );
    update_option('chrilia_sync_history', $sync_history);
}

function chrilia_display_sync_history()
{
    $sync_history = get_option('chrilia_sync_history');
    if (empty($sync_history)) {
        echo '<p>No sync history available.</p>';
        return;
    }
    echo '<table class="widefat">';
    echo '<thead><tr><th>Timestamp</th><th>Status</th><th>Message</th></tr></thead>';
    echo '<tbody>';
    foreach ($sync_history as $entry) {
        echo '<tr>';
        echo '<td>' . $entry['timestamp'] . '</td>';
        echo '<td>' . ucfirst($entry['status']) . '</td>';
        echo '<td>' . esc_html($entry['message']) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}

add_action('woocommerce_admin_order_actions_end', 'chrilia_add_sync_button');

function chrilia_add_sync_button()
{
?>
    <button class="button button-primary" id="chrilia-sync-button">Sync with Chrilia</button>
    <img id=“chrilia-loader” src=“<?php echo admin_url('images/spinner.gif'); ?>” style=“display:none;”>
    <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#chrilia-sync-button').on('click', function() {
                chrilia_sync_data();
                ('#chrilia-loader').hide();
            });
        });
    </script>
<?php
}
