<?php
/**
 * Plugin Name: Golf Cart Dealership CRM
 * Description: Lightweight front-end CRM for golf cart dealerships.
 * Version: 1.0.0
 * Author: CRM Team
 */

if (!defined('ABSPATH')) {
    exit;
}

class GC_Dealership_CRM {
    const NONCE_ACTION = 'gc_crm_nonce_action';

    public function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);

        add_shortcode('gc_dealership_crm', [$this, 'render_shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

        add_action('wp_ajax_gc_add_lead', [$this, 'ajax_add_lead']);
        add_action('wp_ajax_gc_update_lead', [$this, 'ajax_update_lead']);
        add_action('wp_ajax_gc_delete_lead', [$this, 'ajax_delete_lead']);
        add_action('wp_ajax_gc_move_lead', [$this, 'ajax_move_lead']);
        add_action('wp_ajax_gc_get_lead', [$this, 'ajax_get_lead']);

        add_action('wp_ajax_gc_add_note', [$this, 'ajax_add_note']);

        add_action('wp_ajax_gc_add_todo', [$this, 'ajax_add_todo']);
        add_action('wp_ajax_gc_delete_todo', [$this, 'ajax_delete_todo']);

        add_action('wp_ajax_gc_get_contact', [$this, 'ajax_get_contact']);
        add_action('wp_ajax_gc_update_contact', [$this, 'ajax_update_contact']);
        add_action('wp_ajax_gc_delete_contact', [$this, 'ajax_delete_contact']);

        add_action('wp_ajax_gc_save_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_gc_export_leads_csv', [$this, 'ajax_export_leads_csv']);
        add_action('wp_ajax_gc_export_contacts_csv', [$this, 'ajax_export_contacts_csv']);

        add_action('wp_ajax_gc_submit_wc_inquiry', [$this, 'ajax_submit_wc_inquiry']);
        add_action('wp_ajax_nopriv_gc_submit_wc_inquiry', [$this, 'ajax_submit_wc_inquiry']);

        add_action('woocommerce_after_add_to_cart_button', [$this, 'render_wc_button']);
        add_action('wp_footer', [$this, 'render_wc_modal']);

        add_action('wpcf7_mail_sent', [$this, 'handle_cf7_submission']);
    }

    public function activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql_contacts = "CREATE TABLE {$prefix}gc_crm_contacts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            first_name VARCHAR(120) NOT NULL,
            last_name VARCHAR(120) NOT NULL,
            email VARCHAR(190) NOT NULL,
            phone VARCHAR(40) DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY email (email)
        ) $charset_collate;";

        $sql_leads = "CREATE TABLE {$prefix}gc_crm_leads (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            contact_id BIGINT UNSIGNED DEFAULT NULL,
            first_name VARCHAR(120) NOT NULL,
            last_name VARCHAR(120) NOT NULL,
            email VARCHAR(190) NOT NULL,
            phone VARCHAR(40) DEFAULT '',
            status VARCHAR(40) NOT NULL DEFAULT 'new_leads',
            source VARCHAR(60) DEFAULT 'manual',
            product_id BIGINT UNSIGNED DEFAULT NULL,
            product_name VARCHAR(255) DEFAULT '',
            message TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY status (status),
            KEY contact_id (contact_id)
        ) $charset_collate;";

        $sql_notes = "CREATE TABLE {$prefix}gc_crm_notes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            lead_id BIGINT UNSIGNED NOT NULL,
            note_text TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY lead_id (lead_id)
        ) $charset_collate;";

        $sql_activity = "CREATE TABLE {$prefix}gc_crm_activity (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            lead_id BIGINT UNSIGNED DEFAULT NULL,
            activity_type VARCHAR(60) NOT NULL,
            activity_text TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY lead_id (lead_id)
        ) $charset_collate;";

        $sql_product_links = "CREATE TABLE {$prefix}gc_crm_product_links (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            lead_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY lead_id (lead_id),
            KEY product_id (product_id)
        ) $charset_collate;";

        $sql_todos = "CREATE TABLE {$prefix}gc_crm_todos (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            todo_text VARCHAR(255) NOT NULL,
            is_done TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        dbDelta($sql_contacts);
        dbDelta($sql_leads);
        dbDelta($sql_notes);
        dbDelta($sql_activity);
        dbDelta($sql_product_links);
        dbDelta($sql_todos);
    }

    private function user_can_manage_crm() {
        return is_user_logged_in() && current_user_can('edit_posts');
    }

    private function verify_ajax_nonce() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
    }

    private function normalize_status($status) {
        $allowed = ['new_leads', 'contacted', 'quote_sent', 'sold', 'lost'];
        $status = sanitize_key($status);
        if (!in_array($status, $allowed, true)) {
            $status = 'new_leads';
        }
        return $status;
    }

    private function create_contact_if_missing($first_name, $last_name, $email, $phone) {
        global $wpdb;
        $contacts_table = $wpdb->prefix . 'gc_crm_contacts';
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$contacts_table} WHERE email = %s", $email));

        if ($existing) {
            $wpdb->update(
                $contacts_table,
                [
                    'first_name' => $first_name,
                    'last_name'  => $last_name,
                    'phone'      => $phone,
                ],
                ['id' => (int) $existing->id],
                ['%s', '%s', '%s'],
                ['%d']
            );
            return (int) $existing->id;
        }

        $wpdb->insert(
            $contacts_table,
            [
                'first_name' => $first_name,
                'last_name'  => $last_name,
                'email'      => $email,
                'phone'      => $phone,
            ],
            ['%s', '%s', '%s', '%s']
        );

        return (int) $wpdb->insert_id;
    }

    private function create_auto_todo($first_name, $last_name) {
        global $wpdb;
        $todos_table = $wpdb->prefix . 'gc_crm_todos';
        $todo_text = sprintf('Schedule test drive for %s %s', $first_name, $last_name);
        $wpdb->insert($todos_table, ['todo_text' => $todo_text], ['%s']);
    }

    private function create_lead($first_name, $last_name, $email, $phone, $status, $source = 'manual', $message = '', $product_id = 0) {
        global $wpdb;
        $leads_table = $wpdb->prefix . 'gc_crm_leads';
        $activity_table = $wpdb->prefix . 'gc_crm_activity';
        $product_links_table = $wpdb->prefix . 'gc_crm_product_links';

        $contact_id = $this->create_contact_if_missing($first_name, $last_name, $email, $phone);

        $product_name = '';
        if ($product_id > 0) {
            $product_name = sanitize_text_field(get_the_title($product_id));
        }

        $wpdb->insert(
            $leads_table,
            [
                'contact_id'  => $contact_id,
                'first_name'  => $first_name,
                'last_name'   => $last_name,
                'email'       => $email,
                'phone'       => $phone,
                'status'      => $this->normalize_status($status),
                'source'      => sanitize_text_field($source),
                'product_id'  => $product_id > 0 ? $product_id : null,
                'product_name'=> $product_name,
                'message'     => sanitize_textarea_field($message),
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
        );

        $lead_id = (int) $wpdb->insert_id;

        if ($lead_id > 0) {
            $wpdb->insert(
                $activity_table,
                [
                    'lead_id'        => $lead_id,
                    'activity_type'  => 'lead_created',
                    'activity_text'  => 'Lead created from ' . sanitize_text_field($source),
                ],
                ['%d', '%s', '%s']
            );

            if ($product_id > 0) {
                $wpdb->insert(
                    $product_links_table,
                    [
                        'lead_id'    => $lead_id,
                        'product_id' => $product_id,
                    ],
                    ['%d', '%d']
                );
            }

            $this->create_auto_todo($first_name, $last_name);
        }

        return $lead_id;
    }

    public function enqueue_assets() {
        $load_for_shortcode = false;
        if (is_singular()) {
            global $post;
            if ($post && has_shortcode($post->post_content, 'gc_dealership_crm')) {
                $load_for_shortcode = true;
            }
        }

        $load_for_product_cf7_modal = function_exists('is_product') && is_product() && class_exists('WPCF7_ContactForm') && (int) get_option('gc_crm_cf7_form_id', 0) > 0;

        if (!$load_for_shortcode && !$load_for_product_cf7_modal) {
            return;
        }

        wp_enqueue_style('gc-crm-style', plugin_dir_url(__FILE__) . 'assets/css/gc-crm.css', [], '1.0.0');
        wp_enqueue_script('jquery-ui-sortable');
        wp_enqueue_script('gc-crm-script', plugin_dir_url(__FILE__) . 'assets/js/gc-crm.js', ['jquery'], '1.0.0', true);

        wp_localize_script('gc-crm-script', 'gcCrmData', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::NONCE_ACTION),
        ]);
    }

    public function render_shortcode() {
        if (!$this->user_can_manage_crm()) {
            return '<div class="gc-crm gc-crm-login-wrap"><div class="gc-card">Please log in with proper permissions to access CRM.</div></div>';
        }

        global $wpdb;

        $leads_table = $wpdb->prefix . 'gc_crm_leads';
        $contacts_table = $wpdb->prefix . 'gc_crm_contacts';
        $notes_table = $wpdb->prefix . 'gc_crm_notes';
        $todos_table = $wpdb->prefix . 'gc_crm_todos';

        $lead_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$leads_table}");
        $contact_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$contacts_table}");

        $todos = $wpdb->get_results("SELECT * FROM {$todos_table} ORDER BY id DESC");
        $contacts = $wpdb->get_results("SELECT * FROM {$contacts_table} ORDER BY id DESC");

        $statuses = [
            'new_leads' => 'New Leads',
            'contacted' => 'Contacted',
            'quote_sent' => 'Quote Sent',
            'sold' => 'Sold',
            'lost' => 'Lost',
        ];

        $leads_by_status = [];
        foreach (array_keys($statuses) as $status_key) {
            $leads_by_status[$status_key] = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$leads_table} WHERE status = %s ORDER BY id DESC", $status_key));
        }

        $cf7_forms = [];
        if (class_exists('WPCF7_ContactForm')) {
            $forms = WPCF7_ContactForm::find();
            foreach ($forms as $form) {
                $cf7_forms[] = [
                    'id' => $form->id(),
                    'title' => $form->title(),
                ];
            }
        }

        $selected_cf7 = (int) get_option('gc_crm_cf7_form_id', 0);
        $notification_email = sanitize_email(get_option('gc_crm_notification_email', get_option('admin_email')));

        ob_start();
        ?>
        <div class="gc-crm" id="gc-crm-app">
            <div class="gc-tabs">
                <button class="gc-tab active" data-tab="dashboard">Dashboard</button>
                <button class="gc-tab" data-tab="leads">Leads</button>
                <button class="gc-tab" data-tab="contacts">Contacts</button>
                <button class="gc-tab" data-tab="settings">Settings</button>
            </div>

            <div class="gc-panel active" id="gc-panel-dashboard">
                <div class="gc-stats">
                    <div class="gc-stat"><span>Total Leads</span><strong><?php echo esc_html($lead_count); ?></strong></div>
                    <div class="gc-stat"><span>Total Contacts</span><strong><?php echo esc_html($contact_count); ?></strong></div>
                </div>

                <div class="gc-card">
                    <h3>To-Do List</h3>
                    <div class="gc-inline-form">
                        <label for="gc-todo-text" class="screen-reader-text">To-Do Item</label>
                        <input type="text" id="gc-todo-text" placeholder="New to-do item">
                        <button type="button" class="gc-btn" id="gc-add-todo">Add Item</button>
                    </div>
                    <table class="gc-table">
                        <thead><tr><th>Item</th><th>Action</th></tr></thead>
                        <tbody>
                        <?php foreach ($todos as $todo) : ?>
                            <tr>
                                <td><?php echo esc_html($todo->todo_text); ?></td>
                                <td><button class="gc-btn gc-btn-danger gc-btn-sm gc-delete-todo" data-id="<?php echo esc_attr($todo->id); ?>">Delete</button></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="gc-panel" id="gc-panel-leads">
                <div class="gc-header-row">
                    <h3>Lead Pipeline</h3>
                    <div class="gc-header-actions">
                        <button class="gc-btn" id="gc-open-add-lead">Add Lead</button>
                        <button class="gc-btn" id="gc-export-leads">Export Leads CSV</button>
                    </div>
                </div>

                <div class="gc-kanban">
                    <?php foreach ($statuses as $status_key => $status_label) : ?>
                        <div class="gc-column" data-status="<?php echo esc_attr($status_key); ?>">
                            <h4><?php echo esc_html($status_label); ?></h4>
                            <div class="gc-lead-list" data-status="<?php echo esc_attr($status_key); ?>">
                                <?php foreach ($leads_by_status[$status_key] as $lead) : ?>
                                    <div class="gc-lead-card" draggable="true" data-id="<?php echo esc_attr($lead->id); ?>">
                                        <strong><?php echo esc_html($lead->first_name . ' ' . $lead->last_name); ?></strong>
                                        <div><?php echo esc_html($lead->email); ?></div>
                                        <div><?php echo esc_html($lead->phone); ?></div>
                                        <button class="gc-btn gc-btn-sm gc-view-lead" data-id="<?php echo esc_attr($lead->id); ?>">View</button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="gc-panel" id="gc-panel-contacts">
                <div class="gc-card">
                    <h3>Contacts</h3>
                    <div class="gc-header-actions">
                        <button class="gc-btn" id="gc-export-contacts">Export Contacts CSV</button>
                    </div>
                    <table class="gc-table">
                        <thead>
                        <tr><th>First Name</th><th>Last Name</th><th>Email</th><th>Phone</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($contacts as $contact) : ?>
                            <tr>
                                <td><?php echo esc_html($contact->first_name); ?></td>
                                <td><?php echo esc_html($contact->last_name); ?></td>
                                <td><?php echo esc_html($contact->email); ?></td>
                                <td><?php echo esc_html($contact->phone); ?></td>
                                <td>
                                    <button class="gc-btn gc-btn-sm gc-edit-contact" data-id="<?php echo esc_attr($contact->id); ?>">Edit</button>
                                    <button class="gc-btn gc-btn-sm gc-btn-danger gc-delete-contact" data-id="<?php echo esc_attr($contact->id); ?>">Delete</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="gc-panel" id="gc-panel-settings">
                <div class="gc-card">
                    <h3>Settings</h3>
                    <form id="gc-settings-form">
                        <label for="gc-cf7-form-id">Contact Form 7 Form</label>
                        <select id="gc-cf7-form-id" name="cf7_form_id">
                            <option value="0">Select a Form</option>
                            <?php foreach ($cf7_forms as $form) : ?>
                                <option value="<?php echo esc_attr($form['id']); ?>" <?php selected($selected_cf7, (int) $form['id']); ?>>
                                    <?php echo esc_html($form['title'] . ' (#' . $form['id'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label for="gc-notification-email">Notification Email</label>
                        <input type="email" id="gc-notification-email" name="notification_email" value="<?php echo esc_attr($notification_email); ?>">

                        <div class="gc-actions">
                            <button type="submit" class="gc-btn">Save Settings</button>
                        </div>
                        <div class="gc-settings-message" id="gc-settings-message">Settings saved.</div>
                    </form>
                </div>
            </div>
        </div>

        <div class="gc-modal" id="gc-lead-modal">
            <div class="gc-modal-content">
                <button class="gc-close" type="button" data-close="#gc-lead-modal">&times;</button>
                <h3 id="gc-lead-modal-title">Lead</h3>
                <form id="gc-lead-form">
                    <input type="hidden" id="gc-lead-id" name="lead_id" value="">

                    <label for="gc-lead-first-name">First Name</label>
                    <input type="text" id="gc-lead-first-name" name="first_name" required>

                    <label for="gc-lead-last-name">Last Name</label>
                    <input type="text" id="gc-lead-last-name" name="last_name" required>

                    <label for="gc-lead-email">Email</label>
                    <input type="email" id="gc-lead-email" name="email" required>

                    <label for="gc-lead-phone">Phone</label>
                    <input type="text" id="gc-lead-phone" name="phone">

                    <label for="gc-lead-status">Status</label>
                    <select id="gc-lead-status" name="status">
                        <option value="new_leads">New Leads</option>
                        <option value="contacted">Contacted</option>
                        <option value="quote_sent">Quote Sent</option>
                        <option value="sold">Sold</option>
                        <option value="lost">Lost</option>
                    </select>

                    <div class="gc-actions">
                        <button type="submit" class="gc-btn">Save</button>
                        <button type="button" class="gc-btn gc-btn-danger" id="gc-delete-lead">Delete</button>
                        <button type="button" class="gc-btn" data-close="#gc-lead-modal">Close</button>
                    </div>
                </form>

                <hr>
                <h4>Notes</h4>
                <label for="gc-note-text">Add Note</label>
                <textarea id="gc-note-text" rows="3"></textarea>
                <div class="gc-actions">
                    <button type="button" class="gc-btn" id="gc-save-note">Save Note</button>
                </div>
                <div id="gc-note-list"></div>
            </div>
        </div>

        <div class="gc-modal" id="gc-contact-modal">
            <div class="gc-modal-content">
                <button class="gc-close" type="button" data-close="#gc-contact-modal">&times;</button>
                <h3>Edit Contact</h3>
                <form id="gc-contact-form">
                    <input type="hidden" id="gc-contact-id" name="contact_id" value="">

                    <label for="gc-contact-first-name">First Name</label>
                    <input type="text" id="gc-contact-first-name" name="first_name" required>

                    <label for="gc-contact-last-name">Last Name</label>
                    <input type="text" id="gc-contact-last-name" name="last_name" required>

                    <label for="gc-contact-email">Email</label>
                    <input type="email" id="gc-contact-email" name="email" required>

                    <label for="gc-contact-phone">Phone</label>
                    <input type="text" id="gc-contact-phone" name="phone">

                    <div class="gc-actions">
                        <button type="submit" class="gc-btn">Save</button>
                        <button type="button" class="gc-btn" data-close="#gc-contact-modal">Close</button>
                    </div>
                </form>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function ajax_add_lead() {
        $this->verify_ajax_nonce();
        if (!$this->user_can_manage_crm()) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $first_name = sanitize_text_field(wp_unslash($_POST['first_name'] ?? ''));
        $last_name = sanitize_text_field(wp_unslash($_POST['last_name'] ?? ''));
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $phone = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
        $status = $this->normalize_status($_POST['status'] ?? 'new_leads');

        if (empty($first_name) || empty($last_name) || empty($email)) {
            wp_send_json_error(['message' => 'Required fields missing']);
        }

        $lead_id = $this->create_lead($first_name, $last_name, $email, $phone, $status, 'manual');
        wp_send_json_success(['lead_id' => $lead_id]);
    }

    public function ajax_update_lead() {
        global $wpdb;
        $this->verify_ajax_nonce();
        if (!$this->user_can_manage_crm()) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $lead_id = absint($_POST['lead_id'] ?? 0);
        $first_name = sanitize_text_field(wp_unslash($_POST['first_name'] ?? ''));
        $last_name = sanitize_text_field(wp_unslash($_POST['last_name'] ?? ''));
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $phone = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
        $status = $this->normalize_status($_POST['status'] ?? 'new_leads');

        $leads_table = $wpdb->prefix . 'gc_crm_leads';
        $lead = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$leads_table} WHERE id = %d", $lead_id));
        if (!$lead) {
            wp_send_json_error(['message' => 'Lead not found']);
        }

        $contact_id = $this->create_contact_if_missing($first_name, $last_name, $email, $phone);

        $wpdb->update(
            $leads_table,
            [
                'contact_id' => $contact_id,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'email' => $email,
                'phone' => $phone,
                'status' => $status,
            ],
            ['id' => $lead_id],
            ['%d', '%s', '%s', '%s', '%s', '%s'],
            ['%d']
        );

        wp_send_json_success(['lead_id' => $lead_id]);
    }

    public function ajax_delete_lead() {
        global $wpdb;
        $this->verify_ajax_nonce();
        if (!$this->user_can_manage_crm()) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $lead_id = absint($_POST['lead_id'] ?? 0);
        $wpdb->delete($wpdb->prefix . 'gc_crm_leads', ['id' => $lead_id], ['%d']);
        $wpdb->delete($wpdb->prefix . 'gc_crm_notes', ['lead_id' => $lead_id], ['%d']);
        $wpdb->delete($wpdb->prefix . 'gc_crm_activity', ['lead_id' => $lead_id], ['%d']);
        $wpdb->delete($wpdb->prefix . 'gc_crm_product_links', ['lead_id' => $lead_id], ['%d']);

        wp_send_json_success(['deleted' => true]);
    }

    public function ajax_move_lead() {
        global $wpdb;
        $this->verify_ajax_nonce();
        if (!$this->user_can_manage_crm()) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $lead_id = absint($_POST['lead_id'] ?? 0);
        $status = $this->normalize_status($_POST['status'] ?? 'new_leads');

        $wpdb->update($wpdb->prefix . 'gc_crm_leads', ['status' => $status], ['id' => $lead_id], ['%s'], ['%d']);
        wp_send_json_success(['moved' => true]);
    }

    public function ajax_get_lead() {
        global $wpdb;
        $this->verify_ajax_nonce();
        if (!$this->user_can_manage_crm()) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $lead_id = absint($_POST['lead_id'] ?? 0);
        $lead = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}gc_crm_leads WHERE id = %d", $lead_id), ARRAY_A);
        $notes = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}gc_crm_notes WHERE lead_id = %d ORDER BY id DESC", $lead_id), ARRAY_A);

        wp_send_json_success([
            'lead' => $lead,
            'notes' => $notes,
        ]);
    }

    public function ajax_add_note() {
        global $wpdb;
        $this->verify_ajax_nonce();
        if (!$this->user_can_manage_crm()) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $lead_id = absint($_POST['lead_id'] ?? 0);
        $note_text = sanitize_textarea_field(wp_unslash($_POST['note_text'] ?? ''));

        if ($lead_id <= 0 || $note_text === '') {
            wp_send_json_error(['message' => 'Invalid note']);
        }

        $wpdb->insert(
            $wpdb->prefix . 'gc_crm_notes',
            [
                'lead_id' => $lead_id,
                'note_text' => $note_text,
            ],
            ['%d', '%s']
        );

        wp_send_json_success(['saved' => true]);
    }

    public function ajax_add_todo() {
        global $wpdb;
        $this->verify_ajax_nonce();
        if (!$this->user_can_manage_crm()) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $todo_text = sanitize_text_field(wp_unslash($_POST['todo_text'] ?? ''));
        if ($todo_text === '') {
            wp_send_json_error(['message' => 'To-do text required']);
        }

        $wpdb->insert($wpdb->prefix . 'gc_crm_todos', ['todo_text' => $todo_text], ['%s']);
        wp_send_json_success(['saved' => true]);
    }

    public function ajax_delete_todo() {
        global $wpdb;
        $this->verify_ajax_nonce();
        if (!$this->user_can_manage_crm()) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $todo_id = absint($_POST['todo_id'] ?? 0);
        $wpdb->delete($wpdb->prefix . 'gc_crm_todos', ['id' => $todo_id], ['%d']);
        wp_send_json_success(['deleted' => true]);
    }

    public function ajax_get_contact() {
        global $wpdb;
        $this->verify_ajax_nonce();
        if (!$this->user_can_manage_crm()) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $contact_id = absint($_POST['contact_id'] ?? 0);
        $contact = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}gc_crm_contacts WHERE id = %d", $contact_id), ARRAY_A);
        wp_send_json_success(['contact' => $contact]);
    }

    public function ajax_update_contact() {
        global $wpdb;
        $this->verify_ajax_nonce();
        if (!$this->user_can_manage_crm()) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $contact_id = absint($_POST['contact_id'] ?? 0);
        $first_name = sanitize_text_field(wp_unslash($_POST['first_name'] ?? ''));
        $last_name = sanitize_text_field(wp_unslash($_POST['last_name'] ?? ''));
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $phone = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));

        $wpdb->update(
            $wpdb->prefix . 'gc_crm_contacts',
            [
                'first_name' => $first_name,
                'last_name'  => $last_name,
                'email'      => $email,
                'phone'      => $phone,
            ],
            ['id' => $contact_id],
            ['%s', '%s', '%s', '%s'],
            ['%d']
        );

        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}gc_crm_leads SET first_name = %s, last_name = %s, email = %s, phone = %s WHERE contact_id = %d",
            $first_name,
            $last_name,
            $email,
            $phone,
            $contact_id
        ));

        wp_send_json_success(['updated' => true]);
    }

    public function ajax_delete_contact() {
        global $wpdb;
        $this->verify_ajax_nonce();
        if (!$this->user_can_manage_crm()) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $contact_id = absint($_POST['contact_id'] ?? 0);
        $wpdb->delete($wpdb->prefix . 'gc_crm_contacts', ['id' => $contact_id], ['%d']);
        wp_send_json_success(['deleted' => true]);
    }

    public function ajax_save_settings() {
        $this->verify_ajax_nonce();
        if (!$this->user_can_manage_crm()) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $cf7_form_id = absint($_POST['cf7_form_id'] ?? 0);
        $notification_email = sanitize_email(wp_unslash($_POST['notification_email'] ?? ''));

        update_option('gc_crm_cf7_form_id', $cf7_form_id);
        update_option('gc_crm_notification_email', $notification_email);

        wp_send_json_success(['saved' => true]);
    }

    public function ajax_export_leads_csv() {
        global $wpdb;
        $this->verify_ajax_nonce();
        if (!$this->user_can_manage_crm()) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $rows = $wpdb->get_results("SELECT id, first_name, last_name, email, phone, status, source, product_name, message, created_at FROM {$wpdb->prefix}gc_crm_leads ORDER BY id DESC", ARRAY_A);
        $csv_lines = [];
        $csv_lines[] = '"ID","First Name","Last Name","Email","Phone","Status","Source","Product Name","Message","Created At"';

        foreach ($rows as $row) {
            $line = [];
            foreach ($row as $value) {
                $escaped = str_replace('"', '""', (string) $value);
                $line[] = '"' . $escaped . '"';
            }
            $csv_lines[] = implode(',', $line);
        }

        wp_send_json_success([
            'filename' => 'gc-crm-leads-' . gmdate('Y-m-d-H-i-s') . '.csv',
            'content'  => implode("\n", $csv_lines),
        ]);
    }

    public function ajax_export_contacts_csv() {
        global $wpdb;
        $this->verify_ajax_nonce();
        if (!$this->user_can_manage_crm()) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $rows = $wpdb->get_results("SELECT id, first_name, last_name, email, phone, created_at FROM {$wpdb->prefix}gc_crm_contacts ORDER BY id DESC", ARRAY_A);
        $csv_lines = [];
        $csv_lines[] = '"ID","First Name","Last Name","Email","Phone","Created At"';

        foreach ($rows as $row) {
            $line = [];
            foreach ($row as $value) {
                $escaped = str_replace('"', '""', (string) $value);
                $line[] = '"' . $escaped . '"';
            }
            $csv_lines[] = implode(',', $line);
        }

        wp_send_json_success([
            'filename' => 'gc-crm-contacts-' . gmdate('Y-m-d-H-i-s') . '.csv',
            'content'  => implode("\n", $csv_lines),
        ]);
    }

    public function render_wc_button() {
        if (!class_exists('WPCF7_ContactForm')) {
            return;
        }

        $selected_cf7 = (int) get_option('gc_crm_cf7_form_id', 0);
        if ($selected_cf7 <= 0) {
            return;
        }

        echo '<button type="button" class="button alt" id="gc-open-wc-inquiry">Inquire for More Information</button>';
    }

    public function render_wc_modal() {
        if (!function_exists('is_product') || !is_product()) {
            return;
        }

        if (!class_exists('WPCF7_ContactForm')) {
            return;
        }

        $selected_cf7 = (int) get_option('gc_crm_cf7_form_id', 0);
        if ($selected_cf7 <= 0) {
            return;
        }
        ?>
        <div class="gc-modal" id="gc-wc-inquiry-modal">
            <div class="gc-modal-content">
                <button class="gc-close" type="button" data-close="#gc-wc-inquiry-modal">&times;</button>
                <h3>Inquire for More Information</h3>
                <?php echo do_shortcode('[contact-form-7 id="' . $selected_cf7 . '"]'); ?>
                <div class="gc-actions">
                    <button type="button" class="gc-btn" data-close="#gc-wc-inquiry-modal">Close</button>
                </div>
            </div>
        </div>
        <?php
    }

    public function ajax_submit_wc_inquiry() {
        $this->verify_ajax_nonce();

        $first_name = sanitize_text_field(wp_unslash($_POST['first_name'] ?? ''));
        $last_name = sanitize_text_field(wp_unslash($_POST['last_name'] ?? ''));
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $phone = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
        $message = sanitize_textarea_field(wp_unslash($_POST['message'] ?? ''));
        $product_id = absint($_POST['product_id'] ?? 0);

        if (empty($first_name) || empty($last_name) || empty($email)) {
            wp_send_json_error(['message' => 'Required fields missing']);
        }

        $lead_id = $this->create_lead($first_name, $last_name, $email, $phone, 'new_leads', 'woocommerce', $message, $product_id);

        $to = sanitize_email(get_option('gc_crm_notification_email', get_option('admin_email')));
        $subject = 'New WooCommerce Inquiry';
        $body = "Lead: {$first_name} {$last_name}\nEmail: {$email}\nPhone: {$phone}\nMessage: {$message}";
        wp_mail($to, $subject, $body);

        wp_send_json_success(['lead_id' => $lead_id]);
    }

    public function handle_cf7_submission($contact_form) {
        if (!class_exists('WPCF7_Submission')) {
            return;
        }

        $selected_form_id = (int) get_option('gc_crm_cf7_form_id', 0);
        if ($selected_form_id <= 0 || (int) $contact_form->id() !== $selected_form_id) {
            return;
        }

        $submission = WPCF7_Submission::get_instance();
        if (!$submission) {
            return;
        }

        $posted_data = $submission->get_posted_data();
        $container_post_id = absint($posted_data['_wpcf7_container_post'] ?? 0);
        $message = sanitize_textarea_field($posted_data['your-message'] ?? $posted_data['message'] ?? '');
        $product_id = 0;
        $source = 'cf7';

        if ($container_post_id > 0 && get_post_type($container_post_id) === 'product') {
            $product_id = $container_post_id;
            $source = 'woocommerce';
        }

        $first_name = sanitize_text_field($posted_data['first-name'] ?? $posted_data['first_name'] ?? $posted_data['your-name'] ?? '');
        $last_name = sanitize_text_field($posted_data['last-name'] ?? $posted_data['last_name'] ?? '');
        $email = sanitize_email($posted_data['your-email'] ?? $posted_data['email'] ?? '');
        $phone = sanitize_text_field($posted_data['your-phone'] ?? $posted_data['phone'] ?? '');

        if ($first_name && !$last_name) {
            $name_parts = preg_split('/\s+/', $first_name);
            $first_name = sanitize_text_field($name_parts[0] ?? '');
            $last_name = sanitize_text_field(implode(' ', array_slice($name_parts, 1)));
        }

        if (!$first_name || !$email) {
            return;
        }

        $this->create_lead($first_name, $last_name, $email, $phone, 'new_leads', $source, $message, $product_id);
    }
}

new GC_Dealership_CRM();
