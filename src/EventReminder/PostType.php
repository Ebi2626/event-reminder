<?php

namespace EventReminder;

if (! defined('ABSPATH')) {
    exit;
}

class PostType
{

    public const POST_TYPE = 'event_reminder';
    public const TAXONOMY_STATUS = 'event_status';

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
        add_action('init', [$this, 'register_post_type']);
        add_action('init', [$this, 'register_taxonomy']);
        add_filter('post_updated_messages', [$this, 'update_messages']);

        // POPRAWIONE HOOKI KOLUMN - TYLKO dla naszego CPT
        add_filter('manage_' . self::POST_TYPE . '_posts_columns', [$this, 'custom_columns']);
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [$this, 'render_columns'], 10, 2);
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [$this, 'render_status_column'], 10, 2);

        // Metaboksy
        add_action('add_meta_boxes_' . self::POST_TYPE, [$this, 'add_meta_boxes']);
        add_action('save_post_' . self::POST_TYPE, [$this, 'save_meta'], 10, 2);
        add_action('add_meta_boxes_' . self::POST_TYPE, [$this, 'add_manual_send_metabox']);
    }


    public function register_post_type()
    {
        error_log('EventReminder: registering CPT');

        $labels = [
            'name'               => __('Wydarzenia', EVENT_REMINDER_TEXTDOMAIN),
            'singular_name'      => __('Wydarzenie', EVENT_REMINDER_TEXTDOMAIN),
            'menu_name'          => __('Przypomnienia o wydarzeniach', EVENT_REMINDER_TEXTDOMAIN),
            'add_new'            => __('Nowe wydarzenie', EVENT_REMINDER_TEXTDOMAIN),
            'add_new_item'       => __('Dodaj nowe wydarzenie', EVENT_REMINDER_TEXTDOMAIN),
            'edit_item'          => __('Edytuj wydarzenie', EVENT_REMINDER_TEXTDOMAIN),
            'new_item'           => __('Nowe wydarzenie', EVENT_REMINDER_TEXTDOMAIN),
            'view_item'          => __('Zobacz wydarzenie', EVENT_REMINDER_TEXTDOMAIN),
            'search_items'       => __('Szukaj wydarzeń', EVENT_REMINDER_TEXTDOMAIN),
            'not_found'          => __('Brak wydarzeń', EVENT_REMINDER_TEXTDOMAIN),
            'not_found_in_trash' => __('Brak wydarzeń w koszu', EVENT_REMINDER_TEXTDOMAIN),
        ];

        $args = [
            'labels'             => $labels,
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'query_var'          => true,
            'rewrite'            => ['slug' => 'wydarzenie'],
            'capability_type'    => 'post',
            'has_archive'        => true,
            'hierarchical'       => false,
            'menu_position'      => 25,
            'menu_icon'          => 'dashicons-calendar-alt',
            'show_in_rest'       => true,
            'supports'           => ['title', 'editor', 'thumbnail'],
            'show_in_graphql'    => false,
        ];

        register_post_type(self::POST_TYPE, $args);
        error_log('EventReminder CPT registered: ' . self::POST_TYPE);
    }

    public function register_taxonomy()
    {
        $labels = [
            'name'          => __('Statusy', EVENT_REMINDER_TEXTDOMAIN),
            'singular_name' => __('Status', EVENT_REMINDER_TEXTDOMAIN),
            'search_items'  => __('Szukaj statusów', EVENT_REMINDER_TEXTDOMAIN),
            'all_items'     => __('Wszystkie statusy', EVENT_REMINDER_TEXTDOMAIN),
            'edit_item'     => __('Edytuj status', EVENT_REMINDER_TEXTDOMAIN),
            'update_item'   => __('Aktualizuj status', EVENT_REMINDER_TEXTDOMAIN),
            'add_new_item'  => __('Dodaj nowy status', EVENT_REMINDER_TEXTDOMAIN),
            'new_item_name' => __('Nazwa nowego statusu', EVENT_REMINDER_TEXTDOMAIN),
        ];

        register_taxonomy(self::TAXONOMY_STATUS, self::POST_TYPE, [
            'hierarchical'      => false,
            'labels'            => $labels,
            'show_ui'           => true,
            'show_admin_column' => true,
            'query_var'         => true,
            'rewrite'           => ['slug' => 'status'],
            'show_in_rest'      => true,
        ]);

        // Domyślne statusy
        wp_insert_term('Planowane', self::TAXONOMY_STATUS);
        wp_insert_term('Aktywne', self::TAXONOMY_STATUS);
        wp_insert_term('Zakończone', self::TAXONOMY_STATUS);
    }

    public function update_messages($messages)
    {
        global $post, $post_ID;

        $messages[self::POST_TYPE] = [
            0  => '',
            1  => sprintf(__('Wydarzenie "%s" zostało opublikowane.', EVENT_REMINDER_TEXTDOMAIN), esc_html(get_the_title($post_ID))),
            4  => __('Wydarzenie zaktualizowane.', EVENT_REMINDER_TEXTDOMAIN),
            5  => isset($_GET['revision']) ? sprintf(__('Wydarzenie przywrócone do rewizji z %s.', EVENT_REMINDER_TEXTDOMAIN), wp_post_revision_title((int) $_GET['revision'], false)) : false,
            6  => sprintf(__('Wydarzenie "%s" zostało opublikowane.', EVENT_REMINDER_TEXTDOMAIN), esc_html(get_the_title($post_ID))),
            7  => __('Wydarzenie zapisane.', EVENT_REMINDER_TEXTDOMAIN),
            8  => sprintf(__('Wydarzenie "%s" przesunięte do kosza.', EVENT_REMINDER_TEXTDOMAIN), esc_html(get_the_title($post_ID))),
            9  => sprintf(__('Wydarzenie "%s" przywrócone z kosza.', EVENT_REMINDER_TEXTDOMAIN), esc_html(get_the_title($post_ID))),
            10 => sprintf(__('Wydarzenie "%s" opublikowane.', EVENT_REMINDER_TEXTDOMAIN), esc_html(get_the_title($post_ID))),
        ];

        return $messages;
    }

    public function custom_columns($columns)
    {
        if (! is_array($columns)) {
            return $columns;
        }

        $columns = [
            'cb'            => $columns['cb'],
            'title'         => __('Nazwa', EVENT_REMINDER_TEXTDOMAIN),
            'event_date'    => __('📅 Data wydarzenia', EVENT_REMINDER_TEXTDOMAIN),
            self::TAXONOMY_STATUS => __('Status', EVENT_REMINDER_TEXTDOMAIN),
            'date'          => __('Data utworzenia', EVENT_REMINDER_TEXTDOMAIN),
        ];
        return $columns;
    }

    public function render_columns($column, $post_id)
    {
        switch ($column) {
            case 'event_date':
                $start_date = get_post_meta($post_id, '_event_start_date', true);
                $end_date = get_post_meta($post_id, '_event_end_date', true);

                if ($start_date) {
                    echo date_i18n('d.m.Y H:i', strtotime($start_date));
                    if ($end_date && $end_date !== $start_date) {
                        echo '<br><small>→ ' . date_i18n('d.m.Y H:i', strtotime($end_date)) . '</small>';
                    }
                } else {
                    echo '<em>brak daty</em>';
                }
                break;
        }
    }

    public function render_status_column($column, $post_id)
    {
        if ($column === self::TAXONOMY_STATUS) {
            $terms = get_the_terms($post_id, self::TAXONOMY_STATUS);
            if ($terms && ! is_wp_error($terms)) {
                $status = $terms[0]->name;
                $classes = 'status-' . sanitize_html_class(strtolower($status));
                echo '<span class="term-label ' . $classes . '">' . esc_html($status) . '</span>';
            } else {
                echo '<span class="no-status">—</span>';
            }
        }
    }


    public function add_meta_boxes()
    {
        add_meta_box(
            'event_dates',
            __('📅 Daty wydarzenia', EVENT_REMINDER_TEXTDOMAIN),
            [$this, 'render_dates_meta_box'],
            self::POST_TYPE,
            'normal',
            'high'
        );

        add_meta_box(
            'event_reminders',
            __('✉️ Przypomnienia e-mail', EVENT_REMINDER_TEXTDOMAIN),
            [$this, 'render_reminders_meta_box'],
            self::POST_TYPE,
            'side',
            'default'
        );
    }

    public function render_dates_meta_box($post)
    {
        wp_nonce_field('event_reminder_meta', 'event_reminder_nonce');

        $start_date = get_post_meta($post->ID, '_event_start_date', true);
        $end_date = get_post_meta($post->ID, '_event_end_date', true);
?>
        <table class="form-table">
            <tr>
                <th><label for="event_start_date"><?php _e('Data rozpoczęcia', EVENT_REMINDER_TEXTDOMAIN); ?></label></th>
                <td>
                    <input type="datetime-local"
                        id="event_start_date"
                        name="event_start_date"
                        value="<?php echo esc_attr($start_date); ?>"
                        class="regular-text"
                        required />
                    <p class="description"><?php _e('RRRR-MM-DDTHH:MM (np. 2026-01-15T10:00)', EVENT_REMINDER_TEXTDOMAIN); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="event_end_date"><?php _e('Data zakończenia', EVENT_REMINDER_TEXTDOMAIN); ?></label></th>
                <td>
                    <input type="datetime-local"
                        id="event_end_date"
                        name="event_end_date"
                        value="<?php echo esc_attr($end_date); ?>"
                        class="regular-text" />
                    <p class="description"><?php _e('Opcjonalne dla wydarzeń jednodniowych', EVENT_REMINDER_TEXTDOMAIN); ?></p>
                </td>
            </tr>
        </table>
    <?php
    }

    public function render_reminders_meta_box($post)
    {
        $reminders_enabled = get_post_meta($post->ID, '_reminders_enabled', true);
        $reminder_emails   = get_post_meta($post->ID, '_reminder_emails', true);

        if (is_array($reminder_emails)) {
            $reminder_emails = implode("\n", $reminder_emails);
        }

    ?>
        <p>
            <label>
                <input type="checkbox"
                    name="reminders_enabled"
                    value="1"
                    <?php checked($reminders_enabled, '1'); ?> />
                <?php _e('Wysyłaj automatyczne przypomnienia', EVENT_REMINDER_TEXTDOMAIN); ?>
            </label>
        </p>
        <p>
            <label><?php _e('Adresy e-mail (jeden na linię)', EVENT_REMINDER_TEXTDOMAIN); ?></label><br>
            <textarea name="reminder_emails"
                rows="4"
                class="large-text"><?php echo esc_textarea($reminder_emails); ?></textarea>
        <p class="description">
            <?php _e('Powiadomienia: miesiąc, 2 tygodnie, tydzień, 3 dni i 1 dzień przed wydarzeniem.', EVENT_REMINDER_TEXTDOMAIN); ?>
        </p>
        </p>
    <?php
    }


    public function save_meta($post_id, $post)
    {
        // Nonce + permissions
        if (
            ! isset($_POST['event_reminder_nonce']) ||
            ! wp_verify_nonce($_POST['event_reminder_nonce'], 'event_reminder_meta') ||
            ! current_user_can('edit_post', $post_id) ||
            wp_is_post_revision($post_id)
        ) {
            return;
        }

        // Daty (zawsze sanitizuj)
        if (isset($_POST['event_start_date'])) {
            $start_date = sanitize_text_field($_POST['event_start_date']);
            update_post_meta($post_id, '_event_start_date', $start_date);
            update_post_meta($post_id, '_event_date', $start_date); // Dla kolumny
        }

        if (isset($_POST['event_end_date']) && ! empty($_POST['event_end_date'])) {
            update_post_meta($post_id, '_event_end_date', sanitize_text_field($_POST['event_end_date']));
        }

        // Przypomnienia
        if (isset($_POST['reminders_enabled'])) {
            update_post_meta($post_id, '_reminders_enabled', '1');
        } else {
            delete_post_meta($post_id, '_reminders_enabled');
        }

        if (isset($_POST['reminder_emails'])) {
            $emails = array_filter(array_map('sanitize_email', explode("\n", $_POST['reminder_emails'])));
            update_post_meta($post_id, '_reminder_emails', $emails);
        }
    }

    public function add_manual_send_metabox()
    {
        add_meta_box(
            'event_manual_reminder',
            __('Ręczne wysłanie przypomnienia', EVENT_REMINDER_TEXTDOMAIN),
            [$this, 'render_manual_send_metabox'],
            self::POST_TYPE,
            'side',
            'low'
        );
    }

    public function render_manual_send_metabox($post)
    {
        wp_nonce_field('event_reminder_manual_send', 'event_reminder_manual_send_nonce');
    ?>
        <p>
            <button type="button"
                class="button button-primary button-large"
                id="event-reminder-manual-send"
                data-post-id="<?php echo esc_attr($post->ID); ?>">
                <?php _e('Wyślij przypomnienie teraz', EVENT_REMINDER_TEXTDOMAIN); ?>
            </button>
        </p>
        <p id="event-reminder-manual-send-status" style="display:none;"></p>
        <script>
            jQuery(function($) {
                $('#event-reminder-manual-send').on('click', function(e) {
                    e.preventDefault();
                    var $btn = $(this);
                    var postId = $btn.data('post-id');
                    var nonce  = $('#event_reminder_manual_send_nonce').val();
                    var $msg = $('#event-reminder-manual-send-status');

                    $btn.prop('disabled', true);
                    $msg.text('<?php echo esc_js(__('Wysyłanie...', EVENT_REMINDER_TEXTDOMAIN)); ?>')
                        .show();

                    $.post(ajaxurl, {
                        action: 'event_reminder_manual_send',
                        nonce: nonce,
                        post_id: postId
                    }).done(function(resp) {
                        if (resp && resp.success) {
                            $msg.text('<?php echo esc_js(__('Przypomnienia wysłane.', EVENT_REMINDER_TEXTDOMAIN)); ?>');
                        } else {
                            $msg.text('<?php echo esc_js(__('Błąd podczas wysyłania przypomnień.', EVENT_REMINDER_TEXTDOMAIN)); ?>');
                        }
                    }).fail(function() {
                        $msg.text('<?php echo esc_js(__('Błąd połączenia z serwerem.', EVENT_REMINDER_TEXTDOMAIN)); ?>');
                    }).always(function() {
                        $btn.prop('disabled', false);
                    });
                });
            });
        </script>
<?php
    }
}
