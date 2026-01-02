<?php

namespace EventReminder;

if (! defined('ABSPATH')) {
    exit;
}

class Cron
{
    public const HOOK_SEND_REMINDERS = 'event_reminder_send_emails';
    public const SCHEDULE_DAILY = 'event_reminder_daily';

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
        add_action('init', [$this, 'schedule_cron']);
        add_action(self::HOOK_SEND_REMINDERS, [$this, 'send_reminders']);
        add_action('wp_ajax_event_reminder_manual_send', [$this, 'handle_manual_send']);
        add_filter('cron_schedules', [$this, 'add_daily_schedule']);
    }

    public function schedule_cron()
    {
        // Planuj tylko jeśli nie istnieje
        if (! wp_next_scheduled(self::HOOK_SEND_REMINDERS)) {
            wp_schedule_event(time(), self::SCHEDULE_DAILY, self::HOOK_SEND_REMINDERS);
            error_log('EventReminder: Cron zaplanowany');
        }
    }

    public function add_daily_schedule($schedules)
    {
        $schedules[self::SCHEDULE_DAILY] = [
            'interval' => 86400, // 24h
            'display'  => __('Codziennie', EVENT_REMINDER_TEXTDOMAIN),
        ];
        return $schedules;
    }

    public function send_reminders()
    {
        $events = $this->get_events_for_reminders();

        if (empty($events)) {
            error_log('EventReminder: Brak wydarzeń do przypomnień');
            return;
        }

        foreach ($events as $event) {
            $this->send_event_reminders($event);
        }

        error_log('EventReminder: Wysyłka przypomnień zakończona (' . count($events) . ' wydarzeń)');
    }

    private function get_events_for_reminders()
    {
        $events = get_posts([
            'post_type'      => PostType::POST_TYPE,
            'post_status'    => 'publish',
            'meta_query'     => [
                [
                    'key'     => '_reminders_enabled',
                    'value'   => '1',
                    'compare' => '=',
                ],
            ],
            'posts_per_page' => -1,
        ]);
        return $events;
    }

    private function send_event_reminders($event)
    {
        $emails = get_post_meta($event->ID, '_reminder_emails', true);
        if (! is_array($emails) || empty($emails)) {
            return;
        }

        $intervals = $this->calculate_reminder_intervals();

        $is_recurring = get_post_meta($event->ID, '_event_recurring', true) === '1';

        foreach ($intervals as $interval_key => $label) {
            $meta_key = '_reminder_sent_' . $interval_key;

            $already = get_post_meta($event->ID, $meta_key, true);
            if ($already) {
                continue;
            }

            // PRZEŁĄCZNIK: cykliczne vs jednorazowe
            if ($is_recurring) {
                $date_for_calc = $this->get_recurring_date($event->ID);
            } else {
                $date_for_calc = get_post_meta($event->ID, '_event_start_date', true);
            }

            if (empty($date_for_calc)) {
                continue;
            }

            if ($this->is_time_for_reminder($date_for_calc, $interval_key)) {
                $this->send_reminder_email($event, $emails, $label);
                update_post_meta($event->ID, $meta_key, current_time('mysql'));
                error_log("EventReminder: WYSLANO '{$label}' dla '{$event->post_title}' (ID:{$event->ID})");
            }
        }
    }


    private function calculate_reminder_intervals()
    {
        return [
            '30days' => __('miesiąc przed wydarzeniem', EVENT_REMINDER_TEXTDOMAIN),
            '14days' => __('2 tygodnie przed wydarzeniem', EVENT_REMINDER_TEXTDOMAIN),
            '7days'  => __('tydzień przed wydarzeniem', EVENT_REMINDER_TEXTDOMAIN),
            '3days'  => __('3 dni przed wydarzeniem', EVENT_REMINDER_TEXTDOMAIN),
            '1day'   => __('dzień przed wydarzeniem', EVENT_REMINDER_TEXTDOMAIN),
        ];
    }

    private function is_time_for_reminder($event_or_date, $interval_key)
    {
        // Sprawdź czy to obiekt WP_Post czy string daty
        if (is_object($event_or_date) && isset($event_or_date->ID)) {
            $event = $event_or_date;
            $is_recurring = get_post_meta($event->ID, '_event_recurring', true) === '1';

            if ($is_recurring) {
                $month_day = get_post_meta($event->ID, '_event_month_day', true);
                $event_date = wp_date('Y-') . $month_day . ' 00:00:00';
            } else {
                $start_date = get_post_meta($event->ID, '_event_start_date', true);
                $event_date = str_replace('T', ' ', $start_date);
            }
        } elseif (is_string($event_or_date)) {
            $event_date = str_replace('T', ' ', $event_or_date);
        } else {
            return false;
        }

        if (empty($event_date)) {
            return false;
        }

        $map = [
            '30days' => '-30 days',
            '14days' => '-14 days',
            '7days'  => '-7 days',
            '3days'  => '-3 days',
            '1day'   => '-1 day',
        ];

        if (! isset($map[$interval_key])) {
            return false;
        }

        $event_ts = strtotime($event_date);
        $target_ts = strtotime($map[$interval_key], $event_ts);
        $target_date = date('Y-m-d', $target_ts);

        $today = wp_date('Y-m-d', current_time('timestamp'));
        $match = $target_date === $today;

        error_log("    DEBUG: {$interval_key} target={$target_date} TODAY={$today} → " . ($match ? 'TAK' : 'NIE'));

        return $match;
    }




    private function send_reminder_email($event, $emails, $label)
    {
        $subject = sprintf(
            __('[Przypomnienie] %s - %s', EVENT_REMINDER_TEXTDOMAIN),
            get_the_title($event->ID),
            $label
        );

        $message = apply_filters('the_content', $event->post_content);

        $headers = ['Content-Type: text/html; charset=UTF-8'];

        foreach ($emails as $email) {
            if (is_email($email)) {
                wp_mail($email, $subject, $message, $headers);
                error_log("EventReminder: E-mail wysłany do {$email} dla wydarzenia {$event->ID}");
            }
        }
    }

    public function handle_manual_send()
    {
        if (
            ! isset($_POST['nonce']) ||
            ! wp_verify_nonce($_POST['nonce'], 'event_reminder_manual_send')
        ) {
            wp_send_json_error(['message' => 'Invalid nonce']);
        }

        if (! current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'No capability']);
        }

        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        if (! $post_id || get_post_type($post_id) !== PostType::POST_TYPE) {
            wp_send_json_error(['message' => 'Invalid post']);
        }

        $event = get_post($post_id);
        if (! $event || $event->post_status !== 'publish') {
            wp_send_json_error(['message' => 'Invalid event']);
        }

        $this->send_event_reminder_manual_force($event);

        wp_send_json_success();
    }



    private function send_event_reminder_manual_force($event)
    {
        $emails = get_post_meta($event->ID, '_reminder_emails', true);
        if (! is_array($emails) || empty($emails)) {
            error_log('EventReminder manual force: brak emaili dla ' . $event->ID);
            return;
        }

        // PRZEŁĄCZNIK: cykliczne vs jednorazowe
        $is_recurring = get_post_meta($event->ID, '_event_recurring', true) === '1';

        if ($is_recurring) {
            $date_for_calc = $this->get_recurring_date($event->ID);
        } else {
            $date_for_calc = get_post_meta($event->ID, '_event_start_date', true);
        }

        if (empty($date_for_calc)) {
            error_log('EventReminder manual force: brak daty dla ' . $event->ID . ' (recurring=' . $is_recurring . ')');
            return;
        }

        // Oblicz różnicę dni (jak było)
        $start = str_replace('T', ' ', $date_for_calc);
        $event_ts = strtotime($start);
        $today_ts = strtotime(wp_date('Y-m-d', current_time('timestamp')));

        $diff_seconds = $event_ts - $today_ts;
        $days = (int) floor($diff_seconds / DAY_IN_SECONDS);

        // Zbuduj opis
        if ($days > 0) {
            $label = sprintf(
                _n('%d dzień przed wydarzeniem', '%d dni przed wydarzeniem', $days, EVENT_REMINDER_TEXTDOMAIN),
                $days
            );
        } elseif ($days === 0) {
            $label = __('w dniu wydarzenia', EVENT_REMINDER_TEXTDOMAIN);
        } else {
            $label = sprintf(
                _n('%d dzień po wydarzeniu', '%d dni po wydarzeniu', abs($days), EVENT_REMINDER_TEXTDOMAIN),
                abs($days)
            );
        }

        error_log("EventReminder manual force: {$label} (event {$event->ID}, date: {$date_for_calc})");

        $this->send_reminder_email($event, $emails, $label . ' (wysłane ręcznie)');
    }


    private function get_recurring_date($post_id)
    {
        $month_day = get_post_meta($post_id, '_event_month_day', true);
        if (empty($month_day)) {
            return '';
        }

        return wp_date('Y-') . $month_day . 'T00:00:00';
    }
}
