<?php
/**
 * Plugin Name: WP API Sync
 * Description: Sync products from Shopify API and store them in the database.
 * Version: 1.3
 * Author: Yuriy Kozmin aka Yuriy Knysh
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class WP_API_Sync {
    private $table_name;
    private $api_url;
    private $access_token;

    public function __construct() {
        // Завантаження налаштувань з бази даних
        $this->api_url = get_option('wp_api_sync_api_url');
        $this->access_token = get_option('wp_api_sync_access_token');
        
        global $wpdb;
        // Додавання префіксу до назви таблиці
        $this->table_name = $wpdb->prefix . get_option('wp_api_sync_table_name', 'shopify_products');

        // Реєстрація хуків для активації плагіна і налаштування крону
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        add_action('wp_api_sync_event', [$this, 'sync_products']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'settings_init']);
    }

    // Активація плагіна: створення таблиці і налаштування крон-завдання
    public function activate() {
        $this->create_table();
        $this->sync_products();
        if (!wp_next_scheduled('wp_api_sync_event')) {
            wp_schedule_event(time(), 'five_minutes', 'wp_api_sync_event');
        }
    }

    // Деактивація плагіна: очищення запланованих завдань
    public function deactivate() {
        wp_clear_scheduled_hook('wp_api_sync_event');
    }

    // Синхронізація продуктів з Shopify API
    public function sync_products() {
        global $wpdb;

        $products = $this->get_shopify_products();

        if ($products && isset($products['products'])) {
            // Створення таблиці на основі отриманих даних
            $this->create_table($products['products'][0]);

            $wpdb->query("TRUNCATE TABLE {$this->table_name}");

            foreach ($products['products'] as $product) {
                $result = $wpdb->insert($this->table_name, [
                    'product_id' => $product['id'],
                    'title' => $product['title'],
                    'body_html' => $product['body_html'],
                    'vendor' => $product['vendor'],
                    'product_type' => $product['product_type'],
                    'created_at' => date('Y-m-d H:i:s', strtotime($product['created_at'])),
                    'updated_at' => date('Y-m-d H:i:s', strtotime($product['updated_at'])),
                ]);

                if ($result === false) {
                    error_log('Failed to insert product: ' . $wpdb->last_error);
                }
            }

            // Збереження дати і часу останнього звернення та кількості отриманих записів
            update_option('wp_api_sync_last_sync', current_time('mysql'));
            update_option('wp_api_sync_record_count', count($products['products']));
        } else {
            error_log('Failed to fetch products or no products found.');
        }
    }

    // Отримання даних з Shopify API
    private function get_shopify_products() {
        $headers = array(
            'Content-Type: application/json',
            'X-Shopify-Access-Token: ' . $this->access_token
        );

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true);
    }

    // Створення таблиці у базі даних
    private function create_table($product = null) {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Визначення динамічних полів на основі отриманих даних
        $fields = "
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            product_id BIGINT(20) NOT NULL,
            title TEXT NOT NULL,
            body_html TEXT,
            vendor VARCHAR(255),
            product_type VARCHAR(255),
            created_at DATETIME,
            updated_at DATETIME,
            PRIMARY KEY (id),
            UNIQUE KEY product_id (product_id)
        ";

        if ($product) {
            $fields = "";
            foreach ($product as $key => $value) {
                if (is_array($value)) {
                    continue;
                }
                $fields .= "`$key` " . $this->get_field_type($value) . ",";
            }
            $fields .= "id BIGINT(20) NOT NULL AUTO_INCREMENT, PRIMARY KEY (id), UNIQUE KEY product_id (product_id)";
        }

        $sql = "CREATE TABLE {$this->table_name} (
            $fields
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    // Визначення типу поля
    private function get_field_type($value) {
        if (is_int($value)) {
            return 'BIGINT(20) NOT NULL';
        } elseif (is_float($value)) {
            return 'FLOAT NOT NULL';
        } elseif (is_string($value) && strlen($value) > 255) {
            return 'TEXT NOT NULL';
        } else {
            return 'VARCHAR(255) NOT NULL';
        }
    }

    // Додавання сторінки налаштувань у меню адміністратора
    public function add_admin_menu() {
        add_options_page(
            'WP API Sync Settings',
            'WP API Sync',
            'manage_options',
            'wp_api_sync',
            [$this, 'settings_page']
        );
    }

    // Ініціалізація налаштувань
    public function settings_init() {
        register_setting('wp_api_sync_settings', 'wp_api_sync_api_url');
        register_setting('wp_api_sync_settings', 'wp_api_sync_access_token');
        register_setting('wp_api_sync_settings', 'wp_api_sync_table_name');
        register_setting('wp_api_sync_settings', 'wp_api_sync_schedule');

        add_settings_section(
            'wp_api_sync_settings_section',
            __('Основні налаштування', 'wp_api_sync'),
            null,
            'wp_api_sync_settings'
        );

        add_settings_field(
            'wp_api_sync_api_url',
            __('API URL', 'wp_api_sync'),
            [$this, 'api_url_render'],
            'wp_api_sync_settings',
            'wp_api_sync_settings_section'
        );

        add_settings_field(
            'wp_api_sync_access_token',
            __('Access Token', 'wp_api_sync'),
            [$this, 'access_token_render'],
            'wp_api_sync_settings',
            'wp_api_sync_settings_section'
        );

        add_settings_field(
            'wp_api_sync_table_name',
            __('Назва таблиці', 'wp_api_sync'),
            [$this, 'table_name_render'],
            'wp_api_sync_settings',
            'wp_api_sync_settings_section'
        );

        add_settings_field(
            'wp_api_sync_schedule',
            __('Частота звернень', 'wp_api_sync'),
            [$this, 'schedule_render'],
            'wp_api_sync_settings',
            'wp_api_sync_settings_section'
        );

        add_settings_field(
            'wp_api_sync_last_sync',
            __('Дата і час останнього звернення до API', 'wp_api_sync'),
            [$this, 'last_sync_render'],
            'wp_api_sync_settings',
            'wp_api_sync_settings_section'
        );

        add_settings_field(
            'wp_api_sync_next_sync',
            __('Дата і час наступного звернення до API', 'wp_api_sync'),
            [$this, 'next_sync_render'],
            'wp_api_sync_settings',
            'wp_api_sync_settings_section'
        );

        add_settings_field(
            'wp_api_sync_record_count',
            __('Кількість отриманих записів', 'wp_api_sync'),
            [$this, 'record_count_render'],
            'wp_api_sync_settings',
            'wp_api_sync_settings_section'
        );
    }

    // Поле для введення API URL
    public function api_url_render() {
        $value = get_option('wp_api_sync_api_url', $this->api_url);
        echo '<input type="text" name="wp_api_sync_api_url" value="' . esc_attr($value) . '" />';
    }

    // Поле для введення Access Token
    public function access_token_render() {
        $value = get_option('wp_api_sync_access_token', $this->access_token);
        echo '<input type="text" name="wp_api_sync_access_token" value="' . esc_attr($value) . '" />';
    }

    // Поле для введення назви таблиці
    public function table_name_render() {
        $value = get_option('wp_api_sync_table_name', 'shopify_products');
        echo '<input type="text" name="wp_api_sync_table_name" value="' . esc_attr($value) . '" />';
    }

    // Поле для вибору частоти звернень
    public function schedule_render() {
        $value = get_option('wp_api_sync_schedule', 'five_minutes');
        $schedules = [
            'five_minutes' => __('Раз на 5 хвилин', 'wp_api_sync'),
            'hourly' => __('Раз на годину', 'wp_api_sync'),
            'daily' => __('Раз на добу', 'wp_api_sync')
        ];
        echo '<select name="wp_api_sync_schedule">';
        foreach ($schedules as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($value, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    // Поле для відображення дати і часу останнього звернення до API
    public function last_sync_render() {
        $value = get_option('wp_api_sync_last_sync', 'Ніколи');
        echo '<input type="text" readonly value="' . esc_attr($value) . '" />';
    }

    // Поле для відображення дати і часу наступного звернення до API
    public function next_sync_render() {
        $timestamp = wp_next_scheduled('wp_api_sync_event');
        $value = $timestamp ? date('Y-m-d H:i:s', $timestamp) : 'Не заплановано';
        echo '<input type="text" readonly value="' . esc_attr($value) . '" />';
    }

    // Поле для відображення кількості отриманих записів
    public function record_count_render() {
        $value = get_option('wp_api_sync_record_count', '0');
        echo '<input type="text" readonly value="' . esc_attr($value) . '" />';
    }

    // Сторінка налаштувань
    public function settings_page() {
        ?>
        <form action="options.php" method="post">
            <?php
            settings_fields('wp_api_sync_settings');
            do_settings_sections('wp_api_sync_settings');
            submit_button();
            ?>
        </form>
        <?php
    }
}

// Реєстрація крон-інтервалів
add_filter('cron_schedules', 'wp_api_sync_cron_schedule');
function wp_api_sync_cron_schedule($schedules) {
    $schedules['five_minutes'] = array(
        'interval' => 300, // 5 хвилин
        'display' => __('Раз на 5 хвилин')
    );
    return $schedules;
}

// Ініціалізація плагіна
new WP_API_Sync();
