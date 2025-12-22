<?php

/**
 * Plugin Name: Event Reminder
 * Plugin URI: ----
 * Description: System przypomnień o wydarzeniach z rejestracją, potwierdzeniem i cyklicznymi e-mailami (cron).
 * Version: 0.0.1
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: Edwin Harmata
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: event-reminder
 */


if (! defined('ABSPATH')) {
    exit;
}

define('EVENT_REMINDER_VERSION', '1.0.0');
define('EVENT_REMINDER_PLUGIN_FILE', __FILE__);
define('EVENT_REMINDER_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('EVENT_REMINDER_PLUGIN_URL', plugin_dir_url(__FILE__));
define('EVENT_REMINDER_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('EVENT_REMINDER_TEXTDOMAIN', 'event-reminder');

spl_autoload_register(function ($class) {
    $prefix = 'EventReminder\\';
    $base_dir = EVENT_REMINDER_PLUGIN_PATH . 'src' . DIRECTORY_SEPARATOR . 'EventReminder' . DIRECTORY_SEPARATOR;

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', DIRECTORY_SEPARATOR, $relative_class) . '.php';

    error_log('Autoloader: ' . $class . ' -> ' . $file);

    if (file_exists($file)) {
        require $file;
        error_log('Autoloader LOADED: ' . $class);
    }
});



if (! class_exists('EventReminder')) :
    final class EventReminder
    {

        private static $instance = null;

        public static function get_instance()
        {
            if (null === self::$instance) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        private function __construct()
        {
            add_action('plugins_loaded', [$this, 'init']);
            register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        }

        public function init()
        {
            if (! $this->is_requirements_met()) {
                return;
            }

            class_exists('\\EventReminder\\PostType');
            class_exists('\\EventReminder\\Cron');

            if (class_exists('\\EventReminder\\PostType')) {
                \EventReminder\PostType::get_instance();
            }
            if (class_exists('\\EventReminder\\Cron')) {
                \EventReminder\Cron::get_instance();
            }

            $this->load_textdomain();
        }

        private function is_requirements_met()
        {
            global $wp_version;
            if (version_compare(PHP_VERSION, '8.0', '<')) {
                add_action('admin_notices', function () {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Event Reminder wymaga PHP 8.0+', EVENT_REMINDER_TEXTDOMAIN) . '</p></div>';
                });
                return false;
            }
            if (version_compare($wp_version, '6.0', '<')) {
                add_action('admin_notices', function () {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Event Reminder wymaga WordPress 6.0+', EVENT_REMINDER_TEXTDOMAIN) . '</p></div>';
                });
                return false;
            }
            return true;
        }

        private function load_textdomain()
        {
            load_plugin_textdomain(EVENT_REMINDER_TEXTDOMAIN, false, dirname(plugin_basename(__FILE__)) . '/languages');
        }

        public function deactivate()
        {
            // Czyść cron events przy dezaktywacji.
            wp_clear_scheduled_hook('event_reminder_send_emails');
        }
    }

    EventReminder::get_instance();
endif;
