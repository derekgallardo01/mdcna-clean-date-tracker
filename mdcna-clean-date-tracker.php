<?php
/**
 * Plugin Name:     MDCNA Clean Date Tracker
 * Plugin URI:      https://namiamiconvention.isatisfy.dev/
 * Description:     Captures Fluent Forms registrations, aggregates clean dates, provides frontend shortcodes and backend reporting for MDCNA 2026.
 * Version:         1.0.0
 * Author:          MDCNA Dev
 * Text Domain:     mdcna-cdt
 * Requires PHP:    7.4
 * Requires WP:     5.8
 */

defined( 'ABSPATH' ) || exit;

// ─────────────────────────────────────────────────────────────
//  CONSTANTS
// ─────────────────────────────────────────────────────────────
define( 'MDCNA_CDT_VERSION',   '1.0.0' );
define( 'MDCNA_CDT_DB_VER',    '1' );
define( 'MDCNA_CDT_FORM_ID',   13 );                          // Fluent Form ID
define( 'MDCNA_CDT_TABLE',     'mdcna_clean_dates' );
define( 'MDCNA_CDT_LOG_FILE',  WP_CONTENT_DIR . '/mdcna-cdt.log' );
define( 'MDCNA_CDT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MDCNA_CDT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// ─────────────────────────────────────────────────────────────
//  ACTIVATION / DEACTIVATION
// ─────────────────────────────────────────────────────────────
register_activation_hook( __FILE__, [ 'MDCNA_CDT', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'MDCNA_CDT', 'deactivate' ] );

// ─────────────────────────────────────────────────────────────
//  MAIN CLASS
// ─────────────────────────────────────────────────────────────
class MDCNA_CDT {

    /** @var MDCNA_CDT|null */
    private static ?MDCNA_CDT $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'init',               [ $this, 'load_textdomain' ] );
        add_action( 'admin_menu',         [ $this, 'register_admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ] );
        add_action( 'wp_ajax_mdcna_export_csv',       [ $this, 'ajax_export_csv' ] );
        add_action( 'wp_ajax_mdcna_insert_test_data', [ $this, 'ajax_insert_test_data' ] );
        add_action( 'wp_ajax_mdcna_delete_test_data', [ $this, 'ajax_delete_test_data' ] );
        add_action( 'wp_ajax_mdcna_delete_record',    [ $this, 'ajax_delete_record' ] );
        add_action( 'wp_ajax_mdcna_edit_clean_date',  [ $this, 'ajax_edit_clean_date' ] );
        add_action( 'wp_ajax_mdcna_bulk_delete',      [ $this, 'ajax_bulk_delete' ] );
        add_action( 'wp_ajax_mdcna_save_email_settings',  [ $this, 'ajax_save_email_settings' ] );
        add_action( 'wp_ajax_mdcna_send_test_email',      [ $this, 'ajax_send_test_email' ] );

        // Email report cron hooks
        add_action( 'mdcna_cdt_daily_report',  [ $this, 'send_daily_report' ] );
        add_action( 'mdcna_cdt_weekly_report', [ $this, 'send_weekly_report' ] );

        // Fluent Forms submission hook
        add_action( 'fluentform/submission_inserted', [ $this, 'on_ff_submission' ], 10, 3 );

        // Shortcodes
        add_shortcode( 'mdcna_clean_time',   [ $this, 'shortcode_clean_time' ] );
        add_shortcode( 'mdcna_leaderboard',  [ $this, 'shortcode_leaderboard' ] );
        add_shortcode( 'mdcna_total_time',   [ $this, 'shortcode_total_time' ] );

        // Daily cron for milestone checks
        add_action( 'mdcna_cdt_daily',      [ $this, 'check_milestones' ] );
        if ( ! wp_next_scheduled( 'mdcna_cdt_daily' ) ) {
            wp_schedule_event( time(), 'daily', 'mdcna_cdt_daily' );
        }
    }

    // ─────────────────────────────────────────────────────────
    //  ACTIVATION
    // ─────────────────────────────────────────────────────────
    public static function activate(): void {
        self::create_table();
        self::log( 'Plugin activated — table created.' );
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook( 'mdcna_cdt_daily' );
        wp_clear_scheduled_hook( 'mdcna_cdt_daily_report' );
        wp_clear_scheduled_hook( 'mdcna_cdt_weekly_report' );
        self::log( 'Plugin deactivated.' );
    }

    private static function create_table(): void {
        global $wpdb;
        $table   = $wpdb->prefix . MDCNA_CDT_TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            entry_id        BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            user_id         BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            first_name      VARCHAR(100) NOT NULL DEFAULT '',
            last_name       VARCHAR(100) NOT NULL DEFAULT '',
            email           VARCHAR(200) NOT NULL DEFAULT '',
            phone           VARCHAR(50)  NOT NULL DEFAULT '',
            clean_date      DATE         NOT NULL,
            qty             SMALLINT(5)  UNSIGNED NOT NULL DEFAULT 1,
            donation        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            merch_json      TEXT         NOT NULL DEFAULT '',
            raw_data        LONGTEXT     NOT NULL DEFAULT '',
            ip_address      VARCHAR(45)  NOT NULL DEFAULT '',
            status          ENUM('active','deleted') NOT NULL DEFAULT 'active',
            created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY     (id),
            KEY             entry_id (entry_id),
            KEY             email (email(100)),
            KEY             clean_date (clean_date),
            KEY             user_id (user_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        update_option( 'mdcna_cdt_db_ver', MDCNA_CDT_DB_VER );
    }

    // ─────────────────────────────────────────────────────────
    //  FLUENT FORMS HOOK
    // ─────────────────────────────────────────────────────────
    public function on_ff_submission( int $entry_id, array $form_data, $form ): void {
        try {
            // Only handle our specific form
            if ( (int) $form->id !== MDCNA_CDT_FORM_ID ) {
                return;
            }

            self::log( "FF Submission received. entry_id={$entry_id}" );
            self::log( "Raw form_data keys: " . implode( ', ', array_keys( $form_data ) ) );

            // ── Extract fields ────────────────────────────────
            $first_name = sanitize_text_field( $form_data['names_1']['first_name'] ?? $form_data['names']['first_name'] ?? $form_data['first_name'] ?? '' );
            $last_name  = sanitize_text_field( $form_data['names_1']['last_name']  ?? $form_data['names']['last_name']  ?? $form_data['last_name']  ?? '' );
            $email      = sanitize_email( $form_data['email_1'] ?? $form_data['email'] ?? '' );
            $phone      = sanitize_text_field( $form_data['phone_1'] ?? $form_data['phone_mobile'] ?? $form_data['phone'] ?? '' );
            $datetime   = sanitize_text_field( $form_data['datetime'] ?? '' );

            // ── Registration quantity ─────────────────────────
            // Fluent Forms payment item quantity field
            $qty = absint(
                $form_data['item-quantity']     ??   // payment item quantity
                $form_data['payment_input_1']   ??   // alternate payment input
                $form_data['quantity']          ??
                1
            );

            // ── Donation ──────────────────────────────────────
            // custom_payment_amount_donation is the optional donation field
            $donation_raw = $form_data['custom_payment_amount_donation'] ?? $form_data['optional_donation'] ?? 0;
            $donation     = floatval( str_replace( [ '$', ',' ], '', $donation_raw ) );

            // ── Merch — map payment fields to internal keys ───
            $merch = [];

            // T-Shirt: check payment field + quantity
            $tshirt_qty = absint( $form_data['item_quantity_t_shirt'] ?? 0 );
            if ( ! empty( $form_data['payment_t_shirt'] ) || $tshirt_qty > 0 ) {
                $merch['e_shirt'] = [
                    'qty'  => $tshirt_qty ?: 1,
                    'size' => sanitize_text_field( $form_data['t_shirt_size'] ?? '' ),
                ];
            }

            // Baseball Cap
            $cap_qty = absint( $form_data['item_quantity_baseball_cap'] ?? 0 );
            if ( ! empty( $form_data['payment_input_baseball_cap'] ) || $cap_qty > 0 ) {
                $merch['baseball_cap'] = [ 'qty' => $cap_qty ?: 1 ];
            }

            // Tote Bag
            $tote_qty = absint( $form_data['item_quantity_tote_bag'] ?? 0 );
            if ( ! empty( $form_data['payment_input_tote_bag'] ) || $tote_qty > 0 ) {
                $merch['tote_bag'] = [ 'qty' => $tote_qty ?: 1 ];
            }

            // Water Bottle
            $bottle_qty = absint( $form_data['item_quantity_water_bottle'] ?? 0 );
            if ( ! empty( $form_data['payment_input_water_bottle'] ) || $bottle_qty > 0 ) {
                $merch['water_bottle'] = [ 'qty' => $bottle_qty ?: 1 ];
            }

            // Payment method (log it)
            $payment_method = sanitize_text_field( $form_data['payment_method'] ?? 'unknown' );
            self::log( "Payment method: {$payment_method} | qty: {$qty} | donation: {$donation} | merch: " . wp_json_encode( $merch ) );

            // ── Parse clean date ──────────────────────────────
            $clean_date = self::parse_clean_date( $datetime );
            if ( ! $clean_date ) {
                self::log( "ERROR: Could not parse clean_date '{$datetime}' for entry {$entry_id}", 'error' );
                self::notify_admin_error( "Could not parse clean date for entry #{$entry_id}. Raw value: '{$datetime}'" );
                return;
            }

            // ── Match / create WP user ────────────────────────
            $user_id = 0;
            if ( $email ) {
                $user = get_user_by( 'email', $email );
                $user_id = $user ? (int) $user->ID : 0;
            }

            // ── Insert into DB ────────────────────────────────
            global $wpdb;
            $table = $wpdb->prefix . MDCNA_CDT_TABLE;

            $inserted = $wpdb->insert( $table, [
                'entry_id'   => $entry_id,
                'user_id'    => $user_id,
                'first_name' => $first_name,
                'last_name'  => $last_name,
                'email'      => $email,
                'phone'      => $phone,
                'clean_date' => $clean_date,
                'qty'        => $qty,
                'donation'   => $donation,
                'merch_json' => wp_json_encode( $merch ),
                'raw_data'   => wp_json_encode( $form_data ),
                'ip_address' => sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ),
            ], [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%f', '%s', '%s', '%s' ] );

            if ( false === $inserted ) {
                $err = $wpdb->last_error;
                self::log( "DB INSERT failed for entry {$entry_id}: {$err}", 'error' );
                self::notify_admin_error( "DB insert failed for entry #{$entry_id}. Error: {$err}" );
                return;
            }

            $record_id = $wpdb->insert_id;
            self::log( "Record inserted id={$record_id} for {$email} clean_date={$clean_date}" );

            // ── Notifications ──────────────────────────────────
            $clean_time_str = self::format_clean_time( self::days_since( $clean_date ) );
            self::notify_admin_new_registration( $first_name, $last_name, $email, $clean_date, $clean_time_str, $qty );
            self::notify_registrant( $email, $first_name, $clean_date, $clean_time_str );

        } catch ( \Throwable $e ) {
            self::log( "EXCEPTION in on_ff_submission: " . $e->getMessage() . ' | ' . $e->getTraceAsString(), 'error' );
            self::notify_admin_error( 'Exception during form submission processing: ' . $e->getMessage() );
        }
    }

    // ─────────────────────────────────────────────────────────
    //  HELPERS
    // ─────────────────────────────────────────────────────────
    public static function parse_clean_date( string $raw ): ?string {
        if ( empty( $raw ) ) return null;

        $raw = trim( $raw );

        // Try formats: m/d/y, m/d/Y, Y-m-d, d-m-Y, etc.
        $formats = [ 'm/d/y', 'm/d/Y', 'Y-m-d', 'd/m/Y', 'm-d-Y', 'n/j/Y', 'n/j/y' ];
        foreach ( $formats as $fmt ) {
            $dt = \DateTime::createFromFormat( $fmt, $raw );
            if ( $dt ) {
                // Sanity check: clean date must be in the past
                if ( $dt->getTimestamp() < time() ) {
                    return $dt->format( 'Y-m-d' );
                }
            }
        }

        // Last resort: strtotime
        $ts = strtotime( $raw );
        if ( $ts && $ts < time() ) {
            return date( 'Y-m-d', $ts );
        }

        return null;
    }

    public static function days_since( string $date ): int {
        try {
            $start = new \DateTime( $date );
            $now   = new \DateTime( 'today' );
            return max( 0, (int) $start->diff( $now )->days );
        } catch ( \Exception $e ) {
            return 0;
        }
    }

    public static function format_clean_time( int $days ): string {
        if ( $days < 30 ) {
            return "{$days} day" . ( $days !== 1 ? 's' : '' );
        }
        $years  = intdiv( $days, 365 );
        $rem    = $days % 365;
        $months = intdiv( $rem, 30 );
        $d      = $rem % 30;

        $parts = [];
        if ( $years )  $parts[] = "{$years} year" . ( $years  > 1 ? 's' : '' );
        if ( $months ) $parts[] = "{$months} month" . ( $months > 1 ? 's' : '' );
        if ( $d )      $parts[] = "{$d} day" . ( $d > 1 ? 's' : '' );
        return implode( ', ', $parts );
    }

    // ─────────────────────────────────────────────────────────
    //  NOTIFICATIONS
    // ─────────────────────────────────────────────────────────
    private static function notify_admin_new_registration(
        string $first, string $last, string $email,
        string $clean_date, string $clean_time, int $qty
    ): void {
        $admin_email = get_option( 'admin_email' );
        $subject     = "[MDCNA 2026] New Registration – {$first} {$last}";
        $body        = self::email_template( "New Convention Registration", [
            'Name'       => "{$first} {$last}",
            'Email'      => $email,
            'Clean Date' => $clean_date . " ({$clean_time} clean)",
            'Registrations' => $qty,
        ], 'A new attendee has registered for MDCNA 2026.' );

        wp_mail( $admin_email, $subject, $body, self::email_headers() );
    }

    private static function notify_registrant( string $email, string $first, string $clean_date, string $clean_time ): void {
        if ( ! is_email( $email ) ) return;
        $subject = "Welcome to MDCNA 2026, {$first}! 🌴";
        $body    = self::email_template( "You're Registered!", [
            'Your Clean Date' => $clean_date,
            'Time Clean'      => $clean_time,
            'Convention'      => 'August 7–9, 2026 – Miami Beach',
        ], "Hi {$first}, thank you for registering for MDCNA 2026. We celebrate your recovery journey. Lead with Love! 💙" );

        wp_mail( $email, $subject, $body, self::email_headers() );
    }

    private static function notify_admin_error( string $message ): void {
        $admin_email = get_option( 'admin_email' );
        wp_mail(
            $admin_email,
            '[MDCNA CDT] ⚠️ Error Detected',
            "An error occurred in the MDCNA Clean Date Tracker plugin:\n\n{$message}\n\nCheck {$_SERVER['HTTP_HOST']} and the log at " . MDCNA_CDT_LOG_FILE,
            self::email_headers()
        );
    }

    private static function email_headers(): array {
        return [
            'Content-Type: text/html; charset=UTF-8',
            'From: MDCNA 2026 <' . get_option( 'admin_email' ) . '>',
        ];
    }

    private static function email_template( string $title, array $fields, string $intro = '' ): string {
        $rows = '';
        foreach ( $fields as $label => $value ) {
            $rows .= "<tr><td style='padding:8px 12px;font-weight:bold;background:#f4f4f4;width:160px'>{$label}</td><td style='padding:8px 12px'>" . esc_html( $value ) . "</td></tr>";
        }
        return "
        <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto'>
            <div style='background:linear-gradient(135deg,#e91e8c,#00bcd4);padding:24px;text-align:center'>
                <h1 style='color:#fff;margin:0;font-size:22px'>MDCNA 2026 – {$title}</h1>
            </div>
            <div style='padding:24px'>
                " . ( $intro ? "<p style='margin-bottom:16px'>{$intro}</p>" : "" ) . "
                <table style='width:100%;border-collapse:collapse;border:1px solid #ddd'>{$rows}</table>
            </div>
            <div style='background:#222;padding:12px;text-align:center'>
                <p style='color:#aaa;font-size:12px;margin:0'>\"Lead with Love\" · Miami Beach · August 7–9, 2026</p>
            </div>
        </div>";
    }

    // ─────────────────────────────────────────────────────────
    //  LOGGING
    // ─────────────────────────────────────────────────────────
    public static function log( string $message, string $level = 'info' ): void {
        $level  = strtoupper( $level );
        $line   = '[' . date( 'Y-m-d H:i:s' ) . "] [{$level}] {$message}" . PHP_EOL;
        // Write to custom log
        @file_put_contents( MDCNA_CDT_LOG_FILE, $line, FILE_APPEND | LOCK_EX );
        // Also write to WP debug log if enabled
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( "MDCNA_CDT [{$level}]: {$message}" );
        }
    }

    // ─────────────────────────────────────────────────────────
    //  MILESTONE CRON
    // ─────────────────────────────────────────────────────────
    public function check_milestones(): void {
        global $wpdb;
        $table = $wpdb->prefix . MDCNA_CDT_TABLE;
        $today = date( 'Y-m-d' );

        // Milestones in days
        $milestones = [ 30, 60, 90, 180, 365, 730, 1095, 1825, 3650 ];

        $rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE status='active'" );
        foreach ( $rows as $row ) {
            $days = self::days_since( $row->clean_date );
            foreach ( $milestones as $m ) {
                if ( $days === $m ) {
                    $label = self::format_clean_time( $m );
                    self::log( "Milestone {$label} for {$row->email}" );
                    // Send congrats email
                    if ( is_email( $row->email ) ) {
                        wp_mail(
                            $row->email,
                            "🎉 Congratulations on {$label} Clean!",
                            self::email_template(
                                "{$label} Clean!",
                                [ 'Name' => $row->first_name . ' ' . $row->last_name, 'Clean Date' => $row->clean_date, 'Days Clean' => $days ],
                                "Congratulations on {$label} clean! We celebrate your journey. Lead with Love! 💙"
                            ),
                            self::email_headers()
                        );
                    }
                }
            }
        }
        self::log( "Milestone check complete for {$today}" );
    }

    // ─────────────────────────────────────────────────────────
    //  SHORTCODES
    // ─────────────────────────────────────────────────────────

    /**
     * [mdcna_clean_time] – Shows current logged-in user's clean time.
     */
    public function shortcode_clean_time( array $atts ): string {
        $atts = shortcode_atts( [ 'show_date' => 'yes', 'style' => 'card' ], $atts );

        if ( ! is_user_logged_in() ) {
            return '<p class="mdcna-login-msg">Please <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">log in</a> to view your clean time.</p>';
        }

        global $wpdb;
        $table = $wpdb->prefix . MDCNA_CDT_TABLE;
        $email = wp_get_current_user()->user_email;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE email = %s AND status='active' ORDER BY clean_date ASC LIMIT 1",
            $email
        ) );

        if ( ! $row ) {
            return '<p class="mdcna-no-record">No clean date found for your account. <a href="/register">Register here</a>.</p>';
        }

        $days  = self::days_since( $row->clean_date );
        $label = self::format_clean_time( $days );
        ob_start();
        ?>
        <div class="mdcna-clean-time-card" data-days="<?php echo $days; ?>">
            <div class="mdcna-cdc-inner">
                <div class="mdcna-cdc-icon">💙</div>
                <div class="mdcna-cdc-name"><?php echo esc_html( $row->first_name ); ?>'s Clean Time</div>
                <div class="mdcna-cdc-days"><?php echo number_format( $days ); ?></div>
                <div class="mdcna-cdc-days-label">Days Clean</div>
                <div class="mdcna-cdc-label"><?php echo esc_html( $label ); ?></div>
                <?php if ( $atts['show_date'] === 'yes' ) : ?>
                    <div class="mdcna-cdc-date">Since <?php echo esc_html( date( 'F j, Y', strtotime( $row->clean_date ) ) ); ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * [mdcna_leaderboard limit="20" anonymous="yes"]
     */
    public function shortcode_leaderboard( array $atts ): string {
        $atts = shortcode_atts( [ 'limit' => 20, 'anonymous' => 'no' ], $atts );

        global $wpdb;
        $table = $wpdb->prefix . MDCNA_CDT_TABLE;
        $limit = absint( $atts['limit'] );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT first_name, last_name, clean_date FROM {$table} WHERE status='active' ORDER BY clean_date ASC LIMIT %d",
            $limit
        ) );

        if ( ! $rows ) return '<p>No registrations yet.</p>';

        ob_start();
        ?>
        <div class="mdcna-leaderboard">
            <h3 class="mdcna-lb-title">🌴 MDCNA 2026 — Community Clean Time</h3>
            <div class="mdcna-lb-grid">
            <?php foreach ( $rows as $i => $row ) :
                $days  = self::days_since( $row->clean_date );
                $label = self::format_clean_time( $days );
                $name  = $atts['anonymous'] === 'yes'
                    ? strtoupper( substr( $row->first_name, 0, 1 ) ) . '. ' . strtoupper( substr( $row->last_name, 0, 1 ) ) . '.'
                    : esc_html( $row->first_name . ' ' . substr( $row->last_name, 0, 1 ) . '.' );
                ?>
                <div class="mdcna-lb-item">
                    <span class="mdcna-lb-rank">#<?php echo $i + 1; ?></span>
                    <span class="mdcna-lb-name"><?php echo $name; ?></span>
                    <span class="mdcna-lb-time"><?php echo esc_html( $label ); ?></span>
                    <span class="mdcna-lb-days"><?php echo number_format( $days ); ?> days</span>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * [mdcna_total_time] – Total combined clean time of all registrants.
     */
    public function shortcode_total_time( array $atts ): string {
        global $wpdb;
        $table = $wpdb->prefix . MDCNA_CDT_TABLE;

        $rows  = $wpdb->get_col( "SELECT clean_date FROM {$table} WHERE status='active'" );
        $total = 0;
        $total_hours = 0;
        $now = new \DateTime( 'now' );
        foreach ( $rows as $d ) {
            try {
                $start = new \DateTime( $d );
                $diff  = $start->diff( $now );
                $total += max( 0, (int) $diff->days );
                $total_hours += max( 0, (int) $diff->days * 24 + (int) $diff->h );
            } catch ( \Exception $e ) {
                // skip invalid dates
            }
        }

        $tt_years     = intdiv( $total, 365 );
        $tt_rem_days  = $total % 365;
        $tt_hours     = $total_hours % 24;
        $count        = count( $rows );

        ob_start();
        ?>
        <div class="mdcna-total-time">
            <div class="mdcna-tt-segments">
                <div class="mdcna-tt-seg">
                    <div class="mdcna-tt-number" data-target="<?php echo $tt_years; ?>">0</div>
                    <div class="mdcna-tt-seg-label">Years</div>
                </div>
                <div class="mdcna-tt-sep">:</div>
                <div class="mdcna-tt-seg">
                    <div class="mdcna-tt-number" data-target="<?php echo $tt_rem_days; ?>">0</div>
                    <div class="mdcna-tt-seg-label">Days</div>
                </div>
                <div class="mdcna-tt-sep">:</div>
                <div class="mdcna-tt-seg">
                    <div class="mdcna-tt-number" data-target="<?php echo $tt_hours; ?>">0</div>
                    <div class="mdcna-tt-seg-label">Hours</div>
                </div>
            </div>
            <div class="mdcna-tt-label">Total Clean Time</div>
            <div class="mdcna-tt-sub">
                From NA Members who registered for the event
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // ─────────────────────────────────────────────────────────
    //  ADMIN
    // ─────────────────────────────────────────────────────────
    public function register_admin_menu(): void {
        add_menu_page(
            'MDCNA Clean Dates',
            'MDCNA Clean Dates',
            'manage_options',
            'mdcna-cdt',
            [ $this, 'admin_page' ],
            'dashicons-heart',
            30
        );
        add_submenu_page(
            'mdcna-cdt',
            'All Registrations',
            'All Registrations',
            'manage_options',
            'mdcna-cdt',
            [ $this, 'admin_page' ]
        );
        add_submenu_page(
            'mdcna-cdt',
            'Stats & Reports',
            'Stats & Reports',
            'manage_options',
            'mdcna-cdt-stats',
            [ $this, 'admin_stats_page' ]
        );
        add_submenu_page(
            'mdcna-cdt',
            'View Log',
            'View Log',
            'manage_options',
            'mdcna-cdt-log',
            [ $this, 'admin_log_page' ]
        );
        add_submenu_page(
            'mdcna-cdt',
            'Test Data',
            'Test Data',
            'manage_options',
            'mdcna-cdt-test',
            [ $this, 'admin_test_page' ]
        );
        add_submenu_page(
            'mdcna-cdt',
            'Email Reports',
            'Email Reports',
            'manage_options',
            'mdcna-cdt-email-reports',
            [ $this, 'admin_email_reports_page' ]
        );
    }

    // Merch key → pretty label
    private static function merch_label( string $key ): string {
        return [
            'e_shirt'      => 'T-Shirt',
            'baseball_cap' => 'Baseball Cap',
            'tote_bag'     => 'Tote Bag',
            'water_bottle' => 'Water Bottle',
        ][ $key ] ?? ucwords( str_replace( '_', ' ', $key ) );
    }

    public function admin_page(): void {
        global $wpdb;
        $table = $wpdb->prefix . MDCNA_CDT_TABLE;
        $nonce = wp_create_nonce( 'mdcna_admin_action' );

        // ── Filter tab ────────────────────────────────────────
        $filter = sanitize_text_field( $_GET['filter'] ?? 'all' );
        $filter_where = match( $filter ) {
            'newcomer' => "AND DATEDIFF(CURDATE(), clean_date) <= 90",
            'mid'      => "AND DATEDIFF(CURDATE(), clean_date) BETWEEN 91 AND 1824",
            'veteran'  => "AND DATEDIFF(CURDATE(), clean_date) >= 1825",
            default    => '',
        };

        // ── Search ────────────────────────────────────────────
        $search = sanitize_text_field( $_GET['s'] ?? '' );
        $search_where = '';
        if ( $search ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $search_where = $wpdb->prepare( " AND (first_name LIKE %s OR last_name LIKE %s OR email LIKE %s)", $like, $like, $like );
        }

        // ── Sort ──────────────────────────────────────────────
        $sort_col = sanitize_key( $_GET['sort'] ?? 'clean_date' );
        $sort_dir = strtoupper( sanitize_key( $_GET['dir'] ?? 'ASC' ) ) === 'DESC' ? 'DESC' : 'ASC';
        $sort_dir_flip = $sort_dir === 'ASC' ? 'DESC' : 'ASC';
        $allowed_sort = [ 'clean_date', 'first_name', 'days', 'qty', 'donation', 'created_at' ];
        if ( ! in_array( $sort_col, $allowed_sort ) ) $sort_col = 'clean_date';
        $order_sql = $sort_col === 'days'
            ? "DATEDIFF(CURDATE(), clean_date) {$sort_dir}"
            : "{$sort_col} {$sort_dir}";

        $where = "WHERE status='active' {$filter_where}{$search_where}";

        // ── Tab counts ────────────────────────────────────────
        $count_all      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='active'" );
        $count_newcomer = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='active' AND DATEDIFF(CURDATE(), clean_date) <= 90" );
        $count_mid      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='active' AND DATEDIFF(CURDATE(), clean_date) BETWEEN 91 AND 1824" );
        $count_veteran  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='active' AND DATEDIFF(CURDATE(), clean_date) >= 1825" );

        // ── Pagination ────────────────────────────────────────
        $per_page     = 25;
        $current_page = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        $offset       = ( $current_page - 1 ) * $per_page;
        $total        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" );
        $rows         = $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY {$order_sql} LIMIT {$per_page} OFFSET {$offset}" );
        $total_pages  = ceil( $total / $per_page );

        // ── Totals row ────────────────────────────────────────
        $page_donations = 0;
        $page_revenue   = 0;
        foreach ( $rows as $r ) {
            $page_donations += (float) $r->donation;
            $page_revenue   += (int) $r->qty * 30;
        }

        // ── Sort link helper ──────────────────────────────────
        $sl = function( string $col, string $label ) use ( $sort_col, $sort_dir, $sort_dir_flip ) {
            $active = $sort_col === $col;
            $arrow  = $active ? ( $sort_dir === 'ASC' ? ' ▲' : ' ▼' ) : '';
            $d      = $active ? $sort_dir_flip : 'ASC';
            $url    = add_query_arg( [ 'sort' => $col, 'dir' => $d ] );
            return "<a href='" . esc_url( $url ) . "' style='color:inherit;text-decoration:none'>{$label}{$arrow}</a>";
        };
        ?>
        <div class="wrap" id="mdcna-admin-wrap">
            <h1 class="wp-heading-inline">💙 MDCNA 2026 — Clean Date Registry</h1>
            <a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=mdcna_export_csv&nonce=' . wp_create_nonce( 'mdcna_export' ) ) ); ?>"
               class="page-title-action">Export CSV</a>
            <hr class="wp-header-end">

            <?php // ── Filter Tabs ?>
            <ul class="subsubsub" style="margin-bottom:8px">
                <li><a href="<?php echo esc_url( add_query_arg( [ 'filter' => 'all', 'paged' => 1 ] ) ); ?>" <?php echo $filter === 'all' ? 'class="current"' : ''; ?>>All <span class="count">(<?php echo $count_all; ?>)</span></a> |</li>
                <li><a href="<?php echo esc_url( add_query_arg( [ 'filter' => 'newcomer', 'paged' => 1 ] ) ); ?>" <?php echo $filter === 'newcomer' ? 'class="current"' : ''; ?>>Newcomers ≤90d <span class="count">(<?php echo $count_newcomer; ?>)</span></a> |</li>
                <li><a href="<?php echo esc_url( add_query_arg( [ 'filter' => 'mid', 'paged' => 1 ] ) ); ?>" <?php echo $filter === 'mid' ? 'class="current"' : ''; ?>>1–5 Years <span class="count">(<?php echo $count_mid; ?>)</span></a> |</li>
                <li><a href="<?php echo esc_url( add_query_arg( [ 'filter' => 'veteran', 'paged' => 1 ] ) ); ?>" <?php echo $filter === 'veteran' ? 'class="current"' : ''; ?>>5+ Years <span class="count">(<?php echo $count_veteran; ?>)</span></a></li>
            </ul>

            <?php // ── Search + Bulk ?>
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:8px">
                <form method="get" style="display:flex;gap:6px;align-items:center">
                    <input type="hidden" name="page" value="mdcna-cdt">
                    <input type="hidden" name="filter" value="<?php echo esc_attr( $filter ); ?>">
                    <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search name or email…" style="width:240px">
                    <button type="submit" class="button">Search</button>
                    <?php if ( $search ) : ?><a href="<?php echo esc_url( add_query_arg( 's', '', remove_query_arg( 's' ) ) ); ?>" class="button">Clear</a><?php endif; ?>
                </form>
                <div style="display:flex;gap:8px;align-items:center">
                    <button id="mdcna-bulk-delete" class="button" disabled>Delete Selected</button>
                    <span id="mdcna-bulk-status" style="font-style:italic;color:#666;font-size:12px"></span>
                </div>
            </div>

            <p style="color:#666;font-size:12px;margin:4px 0 8px">
                Showing <?php echo $total; ?> registrant(s) · Last updated: <?php echo date( 'M j, Y g:i A' ); ?>
            </p>

            <form id="mdcna-bulk-form">
            <table class="wp-list-table widefat fixed striped mdcna-table">
                <thead>
                    <tr>
                        <th style="width:32px"><input type="checkbox" id="mdcna-check-all"></th>
                        <th style="width:40px">#</th>
                        <th><?php echo $sl( 'first_name', 'Name' ); ?></th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th><?php echo $sl( 'clean_date', 'Clean Date' ); ?></th>
                        <th>Time Clean</th>
                        <th><?php echo $sl( 'days', 'Days' ); ?></th>
                        <th><?php echo $sl( 'qty', 'Qty' ); ?></th>
                        <th><?php echo $sl( 'donation', 'Donation' ); ?></th>
                        <th>Merch</th>
                        <th><?php echo $sl( 'created_at', 'Registered' ); ?></th>
                        <th>Time</th>
                        <th style="width:80px">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( $rows ) :
                    foreach ( $rows as $row ) :
                        $days      = self::days_since( $row->clean_date );
                        $label     = self::format_clean_time( $days );
                        $merch     = json_decode( $row->merch_json, true ) ?: [];
                        $merch_str = $merch
                            ? implode( ', ', array_map( [ $this, 'merch_label' ], array_keys( $merch ) ) )
                            : '—';
                        $is_test   = str_ends_with( $row->email, '@mdcna-test.dev' );
                        $is_new    = $days <= 90;

                        // Row day-range class for color coding
                        $row_class = $is_test ? 'mdcna-row-test' : ( $is_new ? 'mdcna-row-new' : ( $days >= 1825 ? 'mdcna-row-vet' : '' ) );
                        ?>
                        <tr class="<?php echo $row_class; ?>" data-id="<?php echo (int) $row->id; ?>">
                            <td><input type="checkbox" class="mdcna-row-check" value="<?php echo (int) $row->id; ?>"></td>
                            <td><?php echo (int) $row->id; ?></td>
                            <td>
                                <strong><?php echo esc_html( $row->first_name . ' ' . $row->last_name ); ?></strong>
                                <?php if ( $is_new )  echo ' <span class="mdcna-badge mdcna-badge-new">Newcomer</span>'; ?>
                                <?php if ( $is_test ) echo ' <span class="mdcna-badge mdcna-badge-test">Test</span>'; ?>
                                <?php if ( $days >= 1825 && ! $is_test ) echo ' <span class="mdcna-badge mdcna-badge-vet">5yr+</span>'; ?>
                            </td>
                            <td><?php echo esc_html( $row->email ); ?></td>
                            <td><?php echo esc_html( $row->phone ); ?></td>
                            <td class="mdcna-date-cell" data-id="<?php echo (int) $row->id; ?>" data-date="<?php echo esc_attr( $row->clean_date ); ?>">
                                <span class="mdcna-date-display"><?php echo esc_html( date( 'M j, Y', strtotime( $row->clean_date ) ) ); ?></span>
                                <span class="mdcna-date-edit-wrap" style="display:none">
                                    <input type="date" class="mdcna-date-input" value="<?php echo esc_attr( $row->clean_date ); ?>" style="width:130px">
                                    <button class="button button-small mdcna-date-save" style="margin-left:4px">✓</button>
                                    <button class="button button-small mdcna-date-cancel" style="margin-left:2px">✕</button>
                                </span>
                                <button class="mdcna-edit-date-btn button-link" title="Edit date" style="margin-left:4px;color:#2271b1;font-size:11px">Edit</button>
                            </td>
                            <td><?php echo esc_html( $label ); ?></td>
                            <td><strong class="mdcna-days-val"><?php echo number_format( $days ); ?></strong></td>
                            <td><?php echo (int) $row->qty; ?></td>
                            <td><?php echo $row->donation > 0 ? '$' . number_format( $row->donation, 2 ) : '—'; ?></td>
                            <td><?php echo esc_html( $merch_str ); ?></td>
                            <td style="font-size:11px;color:#666"><?php echo esc_html( date( 'M j, Y', strtotime( $row->created_at ) ) ); ?></td>
                            <td style="font-size:11px;color:#666"><?php echo esc_html( date( 'g:iA', strtotime( $row->created_at ) ) ); ?></td>
                            <td>
                                <button class="button-link mdcna-delete-btn" data-id="<?php echo (int) $row->id; ?>"
                                        style="color:#b32d2e;font-size:12px" title="Delete record">Delete</button>
                            </td>
                        </tr>
                    <?php endforeach;
                else : ?>
                    <tr><td colspan="14">No registrations found.</td></tr>
                <?php endif; ?>
                </tbody>
                <?php if ( $rows ) : ?>
                <tfoot>
                    <tr style="background:#f0f0f0;font-weight:600">
                        <td colspan="9" style="text-align:right;padding-right:8px">Page totals:</td>
                        <td>$<?php echo number_format( $page_donations, 2 ); ?></td>
                        <td colspan="3"></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
            </form>

            <?php if ( $total_pages > 1 ) :
                echo paginate_links( [
                    'base'    => add_query_arg( 'paged', '%#%' ),
                    'format'  => '',
                    'total'   => $total_pages,
                    'current' => $current_page,
                ] );
            endif; ?>
        </div>

        <script>
        (function($){
            const nonce = '<?php echo $nonce; ?>';

            // ── Check all ──────────────────────────────────────
            $('#mdcna-check-all').on('change', function(){
                $('.mdcna-row-check').prop('checked', this.checked);
                $('#mdcna-bulk-delete').prop('disabled', !this.checked);
            });
            $(document).on('change', '.mdcna-row-check', function(){
                const any = $('.mdcna-row-check:checked').length > 0;
                $('#mdcna-bulk-delete').prop('disabled', !any);
                if (!this.checked) $('#mdcna-check-all').prop('checked', false);
            });

            // ── Delete single ──────────────────────────────────
            $(document).on('click', '.mdcna-delete-btn', function(){
                const id  = $(this).data('id');
                const row = $(this).closest('tr');
                if (!confirm('Delete this record? This cannot be undone.')) return;
                $.post(ajaxurl, { action: 'mdcna_delete_record', nonce, id }, function(res){
                    if (res.success) {
                        row.fadeOut(300, function(){ $(this).remove(); });
                    } else {
                        alert('Error: ' + res.data);
                    }
                });
            });

            // ── Bulk delete ────────────────────────────────────
            $('#mdcna-bulk-delete').on('click', function(){
                const ids = $('.mdcna-row-check:checked').map(function(){ return $(this).val(); }).get();
                if (!ids.length) return;
                if (!confirm('Delete ' + ids.length + ' selected record(s)?')) return;
                $('#mdcna-bulk-status').text('Deleting…');
                $.post(ajaxurl, { action: 'mdcna_bulk_delete', nonce, ids }, function(res){
                    if (res.success) {
                        ids.forEach(function(id){
                            $('tr[data-id="' + id + '"]').fadeOut(300, function(){ $(this).remove(); });
                        });
                        $('#mdcna-bulk-status').css('color','green').text('✓ Deleted ' + res.data.deleted + ' records');
                        $('#mdcna-bulk-delete').prop('disabled', true);
                        $('#mdcna-check-all').prop('checked', false);
                    } else {
                        $('#mdcna-bulk-status').css('color','red').text('Error: ' + res.data);
                    }
                });
            });

            // ── Edit clean date ────────────────────────────────
            $(document).on('click', '.mdcna-edit-date-btn', function(){
                const cell = $(this).closest('.mdcna-date-cell');
                cell.find('.mdcna-date-display, .mdcna-edit-date-btn').hide();
                cell.find('.mdcna-date-edit-wrap').show();
            });
            $(document).on('click', '.mdcna-date-cancel', function(){
                const cell = $(this).closest('.mdcna-date-cell');
                cell.find('.mdcna-date-display, .mdcna-edit-date-btn').show();
                cell.find('.mdcna-date-edit-wrap').hide();
            });
            $(document).on('click', '.mdcna-date-save', function(){
                const cell    = $(this).closest('.mdcna-date-cell');
                const id      = cell.data('id');
                const newDate = cell.find('.mdcna-date-input').val();
                if (!newDate) return;
                $(this).prop('disabled', true).text('…');
                $.post(ajaxurl, { action: 'mdcna_edit_clean_date', nonce, id, clean_date: newDate }, function(res){
                    if (res.success) {
                        const d = res.data;
                        cell.find('.mdcna-date-display').text(d.formatted);
                        cell.find('.mdcna-date-input').val(d.clean_date);
                        cell.closest('tr').find('.mdcna-days-val').text(d.days.toLocaleString());
                        // Update time clean cell (4th td after date)
                        cell.next().text(d.time_clean);
                        cell.find('.mdcna-date-display, .mdcna-edit-date-btn').show();
                        cell.find('.mdcna-date-edit-wrap').hide();
                    } else {
                        alert('Error: ' + res.data);
                    }
                    cell.find('.mdcna-date-save').prop('disabled', false).text('✓');
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    public function admin_stats_page(): void {
        global $wpdb;
        $table = $wpdb->prefix . MDCNA_CDT_TABLE;

        $total_registrants = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='active'" );
        $total_days        = (int) $wpdb->get_var( "SELECT SUM(DATEDIFF(CURDATE(), clean_date)) FROM {$table} WHERE status='active'" );
        $avg_days          = $total_registrants ? round( $total_days / $total_registrants ) : 0;
        $total_donations   = (float) $wpdb->get_var( "SELECT SUM(donation) FROM {$table} WHERE status='active'" );
        $total_revenue     = (float) $wpdb->get_var( "SELECT SUM(qty * 35) FROM {$table} WHERE status='active'" );
        $total_merch_rev   = (float) $wpdb->get_var( "SELECT SUM(
            (JSON_UNQUOTE(JSON_EXTRACT(merch_json, '$.e_shirt')) IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(merch_json, '$.e_shirt')) != 'null') * 25 +
            (JSON_UNQUOTE(JSON_EXTRACT(merch_json, '$.baseball_cap')) IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(merch_json, '$.baseball_cap')) != 'null') * 15 +
            (JSON_UNQUOTE(JSON_EXTRACT(merch_json, '$.tote_bag')) IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(merch_json, '$.tote_bag')) != 'null') * 20 +
            (JSON_UNQUOTE(JSON_EXTRACT(merch_json, '$.water_bottle')) IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(merch_json, '$.water_bottle')) != 'null') * 12
        ) FROM {$table} WHERE status='active'" );

        // Breakdown by year
        $by_year = $wpdb->get_results( "SELECT YEAR(clean_date) AS yr, COUNT(*) AS cnt FROM {$table} WHERE status='active' GROUP BY yr ORDER BY yr ASC" );

        // Milestones
        $m_newcomer = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='active' AND DATEDIFF(CURDATE(), clean_date) <= 90" );
        $m_1yr      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='active' AND DATEDIFF(CURDATE(), clean_date) BETWEEN 91 AND 364" );
        $m_1_5yr    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='active' AND DATEDIFF(CURDATE(), clean_date) BETWEEN 365 AND 1824" );
        $m_5_10yr   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='active' AND DATEDIFF(CURDATE(), clean_date) BETWEEN 1825 AND 3649" );
        $m_10yr     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='active' AND DATEDIFF(CURDATE(), clean_date) >= 3650" );

        // Merch counts
        $all_merch = $wpdb->get_col( "SELECT merch_json FROM {$table} WHERE status='active' AND merch_json != '[]' AND merch_json != ''" );
        $merch_counts = [ 'e_shirt' => 0, 'baseball_cap' => 0, 'tote_bag' => 0, 'water_bottle' => 0 ];
        foreach ( $all_merch as $json ) {
            $items = json_decode( $json, true ) ?: [];
            foreach ( array_keys( $items ) as $k ) {
                if ( isset( $merch_counts[ $k ] ) ) $merch_counts[ $k ]++;
            }
        }

        // Chart data as JSON
        $year_labels = wp_json_encode( array_column( $by_year, 'yr' ) );
        $year_data   = wp_json_encode( array_column( $by_year, 'cnt' ) );
        $donut_data  = wp_json_encode( [ $m_newcomer, $m_1yr, $m_1_5yr, $m_5_10yr, $m_10yr ] );
        $merch_data  = wp_json_encode( array_values( $merch_counts ) );

        // Milestone bars max
        $bar_max = max( 1, $m_newcomer, $m_1yr, $m_1_5yr, $m_5_10yr, $m_10yr );

        $milestones_vis = [
            [ 'label' => 'Newcomers (≤90d)', 'count' => $m_newcomer,  'color' => '#22c55e' ],
            [ 'label' => 'Under 1 Year',     'count' => $m_1yr,       'color' => '#3b82f6' ],
            [ 'label' => '1–5 Years',        'count' => $m_1_5yr,     'color' => '#8b5cf6' ],
            [ 'label' => '5–10 Years',       'count' => $m_5_10yr,    'color' => '#f59e0b' ],
            [ 'label' => '10+ Years',        'count' => $m_10yr,      'color' => '#e91e8c' ],
        ];
        ?>
        <div class="wrap" id="mdcna-stats-wrap">
            <h1>MDCNA 2026 — Stats & Reports</h1>
            <p style="color:#666;font-size:12px;margin:-8px 0 16px">Last updated: <?php echo date( 'M j, Y g:i A' ); ?></p>

            <?php // ── KPI Cards ?>
            <div class="mdcna-stats-grid">
                <?php
                $stats = [
                    [ 'label' => 'Total Registrants',   'value' => number_format( $total_registrants ), 'icon' => '', 'sub' => 'convention attendees' ],
                    [ 'label' => 'Total Days Clean',     'value' => number_format( $total_days ),        'icon' => '', 'sub' => number_format( $total_days / 365, 1 ) . ' combined years' ],
                    [ 'label' => 'Avg Days Per Person',  'value' => number_format( $avg_days ),          'icon' => '', 'sub' => self::format_clean_time( $avg_days ) . ' avg' ],
                    [ 'label' => 'Total Donations',      'value' => '$' . number_format( $total_donations, 2 ), 'icon' => '', 'sub' => '100% to event costs' ],
                    [ 'label' => 'Reg. Revenue',         'value' => '$' . number_format( $total_revenue, 2 ),   'icon' => '', 'sub' => '$35 × ' . $total_registrants . ' registrants' ],
                    [ 'label' => 'Total Revenue',        'value' => '$' . number_format( $total_donations + $total_revenue, 2 ), 'icon' => '', 'sub' => 'donations + registrations' ],
                ];
                foreach ( $stats as $s ) : ?>
                    <div class="mdcna-stat-box">
                        <?php if ( $s['icon'] ) : ?><div class="mdcna-stat-icon"><?php echo $s['icon']; ?></div><?php endif; ?>
                        <div class="mdcna-stat-val"><?php echo esc_html( $s['value'] ); ?></div>
                        <div class="mdcna-stat-lbl"><?php echo esc_html( $s['label'] ); ?></div>
                        <div class="mdcna-stat-sub"><?php echo esc_html( $s['sub'] ); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php // ── Charts row ?>
            <div class="mdcna-charts-row">

                <?php // Bar: Registrants by Clean Year ?>
                <div class="mdcna-chart-box" style="flex:2">
                    <h3>Registrants by Clean Year</h3>
                    <canvas id="mdcna-year-chart" height="120"></canvas>
                </div>

                <?php // Donut: Clean Time Distribution ?>
                <div class="mdcna-chart-box" style="flex:1;min-width:260px">
                    <h3>Clean Time Distribution</h3>
                    <canvas id="mdcna-donut-chart" height="120"></canvas>
                    <div id="mdcna-donut-legend" style="margin-top:10px;font-size:12px"></div>
                </div>

            </div>

            <?php // ── Bottom row ?>
            <div class="mdcna-charts-row" style="margin-top:0">

                <?php // Milestone visual bars ?>
                <div class="mdcna-chart-box" style="flex:1">
                    <h3>Milestone Breakdown</h3>
                    <?php foreach ( $milestones_vis as $m ) :
                        $pct = $bar_max ? round( $m['count'] / $bar_max * 100 ) : 0; ?>
                        <div style="margin-bottom:10px">
                            <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:3px">
                                <span><?php echo $m['label']; ?></span>
                                <strong><?php echo $m['count']; ?></strong>
                            </div>
                            <div style="background:#f0f0f0;border-radius:6px;height:10px;overflow:hidden">
                                <div style="width:<?php echo $pct; ?>%;background:<?php echo $m['color']; ?>;height:100%;border-radius:6px;transition:width .8s ease"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php // Merch chart ?>
                <div class="mdcna-chart-box" style="flex:1">
                    <h3>Merch Orders</h3>
                    <canvas id="mdcna-merch-chart" height="120"></canvas>
                </div>

                <?php // Revenue breakdown ?>
                <div class="mdcna-chart-box" style="flex:1">
                    <h3>Revenue Breakdown</h3>
                    <?php
                    $rev_items = [
                        [ 'label' => 'Registrations', 'val' => $total_revenue,   'color' => '#e91e8c' ],
                        [ 'label' => 'Donations',      'val' => $total_donations, 'color' => '#8b5cf6' ],
                    ];
                    $rev_total = $total_revenue + $total_donations;
                    foreach ( $rev_items as $r ) :
                        $pct = $rev_total ? round( $r['val'] / $rev_total * 100 ) : 0;
                    ?>
                        <div style="margin-bottom:14px">
                            <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px">
                                <span><?php echo $r['label']; ?></span>
                                <strong>$<?php echo number_format( $r['val'], 2 ); ?> <span style="color:#999;font-weight:400">(<?php echo $pct; ?>%)</span></strong>
                            </div>
                            <div style="background:#f0f0f0;border-radius:6px;height:14px;overflow:hidden">
                                <div style="width:<?php echo $pct; ?>%;background:<?php echo $r['color']; ?>;height:100%;border-radius:6px"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <div style="margin-top:16px;padding-top:12px;border-top:1px solid #eee;font-size:14px;display:flex;justify-content:space-between">
                        <strong>Total</strong>
                        <strong style="color:#e91e8c">$<?php echo number_format( $rev_total, 2 ); ?></strong>
                    </div>
                </div>

            </div>

        </div>

        <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
        <script>
        (function(){
            const pink   = '#e91e8c';
            const purple = '#8b5cf6';
            const cyan   = '#06b6d4';
            const amber  = '#f59e0b';
            const green  = '#22c55e';
            const blue   = '#3b82f6';

            Chart.defaults.font.family = "'Segoe UI', Arial, sans-serif";
            Chart.defaults.color = '#555';

            // ── Bar: By Year ──────────────────────────────────
            new Chart(document.getElementById('mdcna-year-chart'), {
                type: 'bar',
                data: {
                    labels: <?php echo $year_labels; ?>,
                    datasets: [{
                        label: 'Registrants',
                        data: <?php echo $year_data; ?>,
                        backgroundColor: function(ctx) {
                            const grad = ctx.chart.ctx.createLinearGradient(0, 0, 0, 200);
                            grad.addColorStop(0, pink);
                            grad.addColorStop(1, purple);
                            return grad;
                        },
                        borderRadius: 6,
                        borderSkipped: false,
                    }]
                },
                options: {
                    responsive: true,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: '#f0f0f0' } },
                        x: { grid: { display: false } }
                    }
                }
            });

            // ── Donut: Distribution ───────────────────────────
            const donutLabels = ['≤90 Days', 'Under 1yr', '1–5 Years', '5–10 Years', '10+ Years'];
            const donutColors = [green, blue, purple, amber, pink];
            const donutChart  = new Chart(document.getElementById('mdcna-donut-chart'), {
                type: 'doughnut',
                data: {
                    labels: donutLabels,
                    datasets: [{
                        data: <?php echo $donut_data; ?>,
                        backgroundColor: donutColors,
                        borderWidth: 2,
                        borderColor: '#fff',
                        hoverOffset: 8,
                    }]
                },
                options: {
                    responsive: true,
                    cutout: '65%',
                    plugins: {
                        legend: { display: false },
                        tooltip: { callbacks: { label: ctx => ' ' + ctx.label + ': ' + ctx.raw } }
                    }
                }
            });
            // Custom legend
            const legend = document.getElementById('mdcna-donut-legend');
            donutLabels.forEach(function(l, i){
                const dot  = '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' + donutColors[i] + ';margin-right:5px"></span>';
                const span = document.createElement('span');
                span.style.cssText = 'margin-right:12px;white-space:nowrap';
                span.innerHTML = dot + l + ' (' + (<?php echo $donut_data; ?>[i] || 0) + ')';
                legend.appendChild(span);
            });

            // ── Bar: Merch ────────────────────────────────────
            new Chart(document.getElementById('mdcna-merch-chart'), {
                type: 'bar',
                data: {
                    labels: ['T-Shirt', 'Baseball Cap', 'Tote Bag', 'Water Bottle'],
                    datasets: [{
                        label: 'Orders',
                        data: <?php echo $merch_data; ?>,
                        backgroundColor: [pink, purple, cyan, amber],
                        borderRadius: 6,
                        borderSkipped: false,
                    }]
                },
                options: {
                    responsive: true,
                    indexAxis: 'y',
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: '#f0f0f0' } },
                        y: { grid: { display: false } }
                    }
                }
            });
        })();
        </script>
        <?php
    }

    public function admin_log_page(): void {
        $log = '';
        if ( file_exists( MDCNA_CDT_LOG_FILE ) ) {
            $lines = array_slice( file( MDCNA_CDT_LOG_FILE ), -200 ); // last 200 lines
            $log   = implode( '', array_reverse( $lines ) );
        }
        ?>
        <div class="wrap">
            <h1>MDCNA CDT — Error Log</h1>
            <p><small>Showing last 200 entries · Log path: <code><?php echo esc_html( MDCNA_CDT_LOG_FILE ); ?></code></small></p>
            <textarea style="width:100%;height:500px;font-family:monospace;font-size:12px"><?php echo esc_textarea( $log ?: 'No log entries yet.' ); ?></textarea>
        </div>
        <?php
    }

    // ─────────────────────────────────────────────────────────
    //  TEST DATA PAGE
    // ─────────────────────────────────────────────────────────
    public function admin_test_page(): void {
        global $wpdb;
        $table      = $wpdb->prefix . MDCNA_CDT_TABLE;
        $test_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE email LIKE '%@mdcna-test.dev'" );
        ?>
        <div class="wrap">
            <h1>MDCNA CDT — Test Data</h1>
            <p>Insert fake registrations to preview the frontend counter and leaderboard. All test records use <code>@mdcna-test.dev</code> emails and can be bulk-deleted.</p>

            <div style="display:flex;gap:16px;flex-wrap:wrap;margin:20px 0">
                <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;min-width:260px">
                    <h3 style="margin-top:0">Insert Test Records</h3>
                    <label style="display:block;margin-bottom:8px">
                        Number of records:
                        <input type="number" id="mdcna-test-qty" value="10" min="1" max="100" style="width:70px;margin-left:8px">
                    </label>
                    <label style="display:block;margin-bottom:12px">
                        Spread (years back): <input type="number" id="mdcna-test-spread" value="15" min="1" max="40" style="width:70px;margin-left:8px">
                    </label>
                    <button id="mdcna-insert-test" class="button button-primary">Insert Test Records</button>
                    <span id="mdcna-insert-status" style="margin-left:10px;font-style:italic;color:#666"></span>
                </div>

                <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;min-width:260px">
                    <h3 style="margin-top:0">Delete Test Records</h3>
                    <p style="color:#555">Currently <strong id="mdcna-test-count"><?php echo $test_count; ?></strong> test record(s) in DB.</p>
                    <button id="mdcna-delete-test" class="button button-secondary" <?php echo $test_count ? '' : 'disabled'; ?>>
                        Delete All Test Records
                    </button>
                    <span id="mdcna-delete-status" style="margin-left:10px;font-style:italic;color:#666"></span>
                </div>
            </div>

            <div id="mdcna-test-preview" style="margin-top:16px"></div>
        </div>

        <script>
        (function($){
            const nonce = '<?php echo wp_create_nonce( 'mdcna_test_data' ); ?>';

            $('#mdcna-insert-test').on('click', function(){
                const qty    = parseInt($('#mdcna-test-qty').val()) || 10;
                const spread = parseInt($('#mdcna-test-spread').val()) || 15;
                $('#mdcna-insert-status').text('Inserting…');
                $(this).prop('disabled', true);
                $.post(ajaxurl, { action: 'mdcna_insert_test_data', nonce, qty, spread }, function(res){
                    if(res.success){
                        $('#mdcna-insert-status').css('color','green').text('✓ Inserted ' + res.data.inserted + ' records');
                        $('#mdcna-test-count').text(res.data.total_test);
                        $('#mdcna-delete-test').prop('disabled', false);
                        $('#mdcna-test-preview').html('<p><a href="<?php echo admin_url('admin.php?page=mdcna-cdt'); ?>">View All Registrations →</a></p>');
                    } else {
                        $('#mdcna-insert-status').css('color','red').text('Error: ' + res.data);
                    }
                    $('#mdcna-insert-test').prop('disabled', false);
                });
            });

            $('#mdcna-delete-test').on('click', function(){
                if(!confirm('Delete all test records?')) return;
                $('#mdcna-delete-status').text('Deleting…');
                $(this).prop('disabled', true);
                $.post(ajaxurl, { action: 'mdcna_delete_test_data', nonce }, function(res){
                    if(res.success){
                        $('#mdcna-delete-status').css('color','green').text('✓ Deleted ' + res.data.deleted + ' records');
                        $('#mdcna-test-count').text('0');
                    } else {
                        $('#mdcna-delete-status').css('color','red').text('Error: ' + res.data);
                        $('#mdcna-delete-test').prop('disabled', false);
                    }
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    public function ajax_insert_test_data(): void {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['nonce'] ?? '', 'mdcna_test_data' ) ) {
            wp_send_json_error( 'Forbidden' );
        }

        global $wpdb;
        $table   = $wpdb->prefix . MDCNA_CDT_TABLE;
        $qty     = min( 100, max( 1, (int) ( $_POST['qty'] ?? 10 ) ) );
        $spread  = min( 40,  max( 1, (int) ( $_POST['spread'] ?? 15 ) ) );

        $first_names = [ 'Alex', 'Jordan', 'Taylor', 'Morgan', 'Casey', 'Riley', 'Drew', 'Avery', 'Quinn', 'Skyler',
                         'Jamie', 'Blake', 'Cameron', 'Dakota', 'Emery', 'Finley', 'Harley', 'Jesse', 'Kendall', 'Logan' ];
        $last_names  = [ 'Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis', 'Wilson', 'Moore',
                         'Taylor', 'Anderson', 'Thomas', 'Jackson', 'White', 'Harris', 'Martin', 'Thompson', 'Lee', 'Walker' ];
        $merch_opts  = [ 'e_shirt', 'baseball_cap', 'tote_bag', 'water_bottle' ];

        $inserted = 0;
        for ( $i = 0; $i < $qty; $i++ ) {
            $first = $first_names[ array_rand( $first_names ) ];
            $last  = $last_names[ array_rand( $last_names ) ];
            $email = strtolower( $first . '.' . $last . rand( 1, 999 ) . '@mdcna-test.dev' );

            // Random clean date between 1 day and $spread years ago
            $days_ago   = rand( 1, $spread * 365 );
            $clean_date = date( 'Y-m-d', strtotime( "-{$days_ago} days" ) );

            // Random merch subset
            $merch = [];
            foreach ( $merch_opts as $m ) {
                if ( rand( 0, 1 ) ) $merch[ $m ] = true;
            }

            $result = $wpdb->insert( $table, [
                'entry_id'   => 0,
                'user_id'    => 0,
                'first_name' => $first,
                'last_name'  => $last,
                'email'      => $email,
                'phone'      => '305-' . rand( 100, 999 ) . '-' . rand( 1000, 9999 ),
                'clean_date' => $clean_date,
                'qty'        => rand( 1, 3 ),
                'donation'   => rand( 0, 1 ) ? round( rand( 5, 50 ) + rand( 0, 99 ) / 100, 2 ) : 0,
                'merch_json' => wp_json_encode( $merch ),
                'raw_data'   => wp_json_encode( [ 'test' => true ] ),
                'ip_address' => '127.0.0.1',
            ], [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%f', '%s', '%s', '%s' ] );

            if ( $result ) $inserted++;
        }

        $total_test = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE email LIKE '%@mdcna-test.dev'" );
        self::log( "Test data: inserted {$inserted} records." );
        wp_send_json_success( [ 'inserted' => $inserted, 'total_test' => $total_test ] );
    }

    public function ajax_delete_test_data(): void {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['nonce'] ?? '', 'mdcna_test_data' ) ) {
            wp_send_json_error( 'Forbidden' );
        }

        global $wpdb;
        $table   = $wpdb->prefix . MDCNA_CDT_TABLE;
        $deleted = $wpdb->query( "DELETE FROM {$table} WHERE email LIKE '%@mdcna-test.dev'" );
        self::log( "Test data: deleted {$deleted} records." );
        wp_send_json_success( [ 'deleted' => $deleted ] );
    }

    // ─────────────────────────────────────────────────────────
    //  RECORD ACTIONS
    // ─────────────────────────────────────────────────────────
    public function ajax_delete_record(): void {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['nonce'] ?? '', 'mdcna_admin_action' ) ) {
            wp_send_json_error( 'Forbidden' );
        }
        global $wpdb;
        $id    = absint( $_POST['id'] ?? 0 );
        $table = $wpdb->prefix . MDCNA_CDT_TABLE;
        $done  = $wpdb->update( $table, [ 'status' => 'deleted' ], [ 'id' => $id ], [ '%s' ], [ '%d' ] );
        if ( $done === false ) wp_send_json_error( $wpdb->last_error );
        self::log( "Record #{$id} soft-deleted by admin." );
        wp_send_json_success( [ 'id' => $id ] );
    }

    public function ajax_bulk_delete(): void {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['nonce'] ?? '', 'mdcna_admin_action' ) ) {
            wp_send_json_error( 'Forbidden' );
        }
        $raw_ids = $_POST['ids'] ?? [];
        if ( ! is_array( $raw_ids ) ) $raw_ids = explode( ',', $raw_ids );
        $ids = array_filter( array_map( 'absint', $raw_ids ) );
        if ( ! $ids ) wp_send_json_error( 'No IDs provided' );
        global $wpdb;
        $table        = $wpdb->prefix . MDCNA_CDT_TABLE;
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $deleted      = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET status='deleted' WHERE id IN ({$placeholders})",
            ...$ids
        ) );
        self::log( "Bulk delete: {$deleted} records by admin." );
        wp_send_json_success( [ 'deleted' => $deleted ] );
    }

    public function ajax_edit_clean_date(): void {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['nonce'] ?? '', 'mdcna_admin_action' ) ) {
            wp_send_json_error( 'Forbidden' );
        }
        $id         = absint( $_POST['id'] ?? 0 );
        $clean_date = self::parse_clean_date( sanitize_text_field( $_POST['clean_date'] ?? '' ) );
        if ( ! $clean_date ) wp_send_json_error( 'Invalid date — must be in the past.' );
        global $wpdb;
        $table = $wpdb->prefix . MDCNA_CDT_TABLE;
        $done  = $wpdb->update( $table, [ 'clean_date' => $clean_date ], [ 'id' => $id ], [ '%s' ], [ '%d' ] );
        if ( $done === false ) wp_send_json_error( $wpdb->last_error );
        $days = self::days_since( $clean_date );
        self::log( "Record #{$id} clean_date updated to {$clean_date} by admin." );
        wp_send_json_success( [
            'id'         => $id,
            'clean_date' => $clean_date,
            'formatted'  => date( 'M j, Y', strtotime( $clean_date ) ),
            'days'       => $days,
            'time_clean' => self::format_clean_time( $days ),
        ] );
    }

    public function ajax_export_csv(): void {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_GET['nonce'] ?? '', 'mdcna_export' ) ) {
            wp_die( 'Forbidden', 403 );
        }

        global $wpdb;
        $table = $wpdb->prefix . MDCNA_CDT_TABLE;
        $rows  = $wpdb->get_results( "SELECT * FROM {$table} WHERE status='active' ORDER BY clean_date ASC", ARRAY_A );

        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="mdcna-registrations-' . date( 'Y-m-d' ) . '.csv"' );
        header( 'Pragma: no-cache' );

        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, [ 'ID', 'First', 'Last', 'Email', 'Phone', 'Clean Date', 'Days Clean', 'Time Clean', 'Qty', 'Donation', 'Registered At' ] );

        foreach ( $rows as $row ) {
            $days  = self::days_since( $row['clean_date'] );
            fputcsv( $out, [
                $row['id'],
                $row['first_name'],
                $row['last_name'],
                $row['email'],
                $row['phone'],
                $row['clean_date'],
                $days,
                self::format_clean_time( $days ),
                $row['qty'],
                $row['donation'],
                $row['created_at'],
            ] );
        }

        fclose( $out );
        exit;
    }

    // ─────────────────────────────────────────────────────────
    //  ASSETS
    // ─────────────────────────────────────────────────────────
    public function enqueue_admin_assets( string $hook ): void {
        if ( ! str_contains( $hook, 'mdcna-cdt' ) ) return;
        wp_add_inline_style( 'wp-admin', self::admin_css() );
    }

    public function enqueue_frontend_assets(): void {
        wp_add_inline_style( 'wp-block-library', self::frontend_css() );
        wp_add_inline_script( 'jquery', self::frontend_js() );
    }

    public function load_textdomain(): void {
        load_plugin_textdomain( 'mdcna-cdt', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }

    // ─────────────────────────────────────────────────────────
    //  EMAIL REPORTS
    // ─────────────────────────────────────────────────────────

    private static function get_email_report_settings(): array {
        return wp_parse_args( get_option( 'mdcna_cdt_email_reports', [] ), [
            'recipients'     => '',
            'from_email'     => 'support@namiamiconvention.org',
            'daily_enabled'  => false,
            'weekly_enabled' => false,
        ] );
    }

    public function ajax_save_email_settings(): void {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['nonce'] ?? '', 'mdcna_email_reports' ) ) {
            wp_send_json_error( 'Forbidden' );
        }

        $settings = [
            'recipients'     => sanitize_textarea_field( $_POST['recipients'] ?? '' ),
            'from_email'     => sanitize_email( $_POST['from_email'] ?? 'support@namiamiconvention.org' ),
            'daily_enabled'  => ! empty( $_POST['daily_enabled'] ),
            'weekly_enabled' => ! empty( $_POST['weekly_enabled'] ),
        ];

        update_option( 'mdcna_cdt_email_reports', $settings );

        // Reschedule crons based on settings
        wp_clear_scheduled_hook( 'mdcna_cdt_daily_report' );
        wp_clear_scheduled_hook( 'mdcna_cdt_weekly_report' );

        if ( $settings['daily_enabled'] && $settings['recipients'] ) {
            wp_schedule_event( strtotime( 'tomorrow 8:00 AM' ), 'daily', 'mdcna_cdt_daily_report' );
        }
        if ( $settings['weekly_enabled'] && $settings['recipients'] ) {
            wp_schedule_event( strtotime( 'next Monday 8:00 AM' ), 'weekly', 'mdcna_cdt_weekly_report' );
        }

        self::log( 'Email report settings saved. Daily=' . ( $settings['daily_enabled'] ? 'ON' : 'OFF' ) . ' Weekly=' . ( $settings['weekly_enabled'] ? 'ON' : 'OFF' ) );
        wp_send_json_success( [ 'message' => 'Settings saved.' ] );
    }

    private static function get_report_recipients(): array {
        $settings   = self::get_email_report_settings();
        $raw        = $settings['recipients'];
        $emails     = preg_split( '/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
        return array_filter( array_map( 'sanitize_email', $emails ), 'is_email' );
    }

    private static function get_report_from_email(): string {
        $settings = self::get_email_report_settings();
        return $settings['from_email'] ?: 'support@namiamiconvention.org';
    }

    private static function report_email_headers(): array {
        $from = self::get_report_from_email();
        return [
            'Content-Type: text/html; charset=UTF-8',
            'From: MDCNA 2026 <' . $from . '>',
        ];
    }

    private static function build_report_data( string $period = 'daily' ): array {
        global $wpdb;
        $table = $wpdb->prefix . MDCNA_CDT_TABLE;

        $date_condition = ( $period === 'daily' )
            ? $wpdb->prepare( "AND DATE(created_at) = %s", date( 'Y-m-d', strtotime( '-1 day' ) ) )
            : $wpdb->prepare( "AND created_at >= %s", date( 'Y-m-d', strtotime( '-7 days' ) ) );

        $period_label = ( $period === 'daily' ) ? 'Yesterday' : 'Last 7 Days';

        // New registrations in period
        $new_registrations = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE status='active' {$date_condition}"
        );

        // Total registrations
        $total_registrations = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE status='active'"
        );

        // New donations in period
        $new_donations = (float) $wpdb->get_var(
            "SELECT COALESCE(SUM(donation), 0) FROM {$table} WHERE status='active' AND donation > 0 {$date_condition}"
        );

        // Total donations
        $total_donations = (float) $wpdb->get_var(
            "SELECT COALESCE(SUM(donation), 0) FROM {$table} WHERE status='active' AND donation > 0"
        );

        // New revenue in period (registrations × $35)
        $new_revenue = (float) $wpdb->get_var(
            "SELECT COALESCE(SUM(qty * 35), 0) FROM {$table} WHERE status='active' {$date_condition}"
        );

        // Total revenue (registrations × $35 + donations)
        $total_revenue = (float) $wpdb->get_var(
            "SELECT COALESCE(SUM(qty * 35), 0) FROM {$table} WHERE status='active'"
        );
        $total_revenue_all = $total_revenue + $total_donations;

        // New registrants list
        $new_rows = $wpdb->get_results(
            "SELECT first_name, last_name, email, clean_date, qty, donation, created_at
             FROM {$table} WHERE status='active' {$date_condition} ORDER BY created_at DESC LIMIT 50"
        );

        // Full attendee roster (all active registrants) — for check-in/badges
        $all_rows = $wpdb->get_results(
            "SELECT first_name, last_name, email, phone, clean_date, qty, donation, merch_json, created_at
             FROM {$table} WHERE status='active' ORDER BY last_name ASC, first_name ASC"
        );

        return [
            'period_label'        => $period_label,
            'period'              => $period,
            'new_registrations'   => $new_registrations,
            'total_registrations' => $total_registrations,
            'new_donations'       => $new_donations,
            'total_donations'     => $total_donations,
            'new_revenue'         => $new_revenue,
            'total_revenue'       => $total_revenue_all,
            'new_rows'            => $new_rows,
            'all_rows'            => $all_rows,
        ];
    }

    private static function build_report_html( array $data ): string {
        $period_label = $data['period_label'];
        $period_title = ( $data['period'] === 'daily' ) ? 'Daily Report' : 'Weekly Report';

        // Build new registrants table rows
        $registrant_rows = '';
        if ( ! empty( $data['new_rows'] ) ) {
            foreach ( $data['new_rows'] as $row ) {
                $registrant_rows .= sprintf(
                    '<tr><td style="padding:6px 10px;border-bottom:1px solid #eee">%s %s</td><td style="padding:6px 10px;border-bottom:1px solid #eee" class="mdcna-hide-mobile">%s</td><td style="padding:6px 10px;border-bottom:1px solid #eee">%s</td><td style="padding:6px 10px;border-bottom:1px solid #eee">%s</td><td style="padding:6px 10px;border-bottom:1px solid #eee;text-align:right">$%s</td></tr>',
                    esc_html( $row->first_name ),
                    esc_html( $row->last_name ),
                    esc_html( $row->email ),
                    esc_html( date( 'M j, Y', strtotime( $row->clean_date ) ) ),
                    esc_html( self::format_clean_time( self::days_since( $row->clean_date ) ) ),
                    number_format( (float) $row->donation, 2 )
                );
            }
        } else {
            $registrant_rows = '<tr><td colspan="5" style="padding:12px;text-align:center;color:#999">No new registrations for this period.</td></tr>';
        }

        $new_donations_fmt   = number_format( $data['new_donations'], 2 );
        $total_donations_fmt = number_format( $data['total_donations'], 2 );
        $new_revenue_fmt     = number_format( $data['new_revenue'], 2 );
        $total_revenue_fmt   = number_format( $data['total_revenue'], 2 );

        // Build full attendee roster rows (sorted by last name)
        $roster_rows = '';
        $roster_count = 0;
        if ( ! empty( $data['all_rows'] ) ) {
            foreach ( $data['all_rows'] as $i => $row ) {
                $roster_count++;
                $zebra = $i % 2 === 0 ? '#ffffff' : '#f9fafb';
                $roster_rows .= sprintf(
                    '<tr style="background:%s"><td style="padding:6px 10px;border-bottom:1px solid #eee;font-size:12px">%d</td><td style="padding:6px 10px;border-bottom:1px solid #eee;font-size:12px">%s %s</td><td style="padding:6px 10px;border-bottom:1px solid #eee;font-size:12px" class="mdcna-hide-mobile">%s</td><td style="padding:6px 10px;border-bottom:1px solid #eee;font-size:12px">%s</td><td style="padding:6px 10px;border-bottom:1px solid #eee;font-size:12px;text-align:center">%d</td></tr>',
                    $zebra,
                    $roster_count,
                    esc_html( $row->first_name ),
                    esc_html( $row->last_name ),
                    esc_html( $row->email ),
                    esc_html( date( 'M j, Y', strtotime( $row->clean_date ) ) ),
                    (int) $row->qty
                );
            }
        } else {
            $roster_rows = '<tr><td colspan="5" style="padding:12px;text-align:center;color:#999">No registered attendees yet.</td></tr>';
        }

        return "
        <html><head><meta name='viewport' content='width=device-width,initial-scale=1'>
        <style>
            @media only screen and (max-width:600px) {
                .mdcna-kpi-row td { display:block !important; width:100% !important; margin-bottom:8px !important; }
                .mdcna-kpi-spacer { display:none !important; }
                .mdcna-report-wrap { padding:12px !important; }
                .mdcna-report-wrap h1 { font-size:18px !important; }
                .mdcna-reg-table { font-size:11px !important; }
                .mdcna-reg-table th, .mdcna-reg-table td { padding:6px 4px !important; }
                .mdcna-hide-mobile { display:none !important; }
            }
        </style></head><body style='margin:0;padding:0'>
        <div style='font-family:Arial,sans-serif;max-width:700px;margin:0 auto'>
            <div style='background:linear-gradient(135deg,#e91e8c,#00bcd4);padding:24px;text-align:center' class='mdcna-report-wrap'>
                <h1 style='color:#fff;margin:0;font-size:22px'>MDCNA 2026 — {$period_title}</h1>
                <p style='color:rgba(255,255,255,0.85);margin:6px 0 0;font-size:13px'>{$period_label} · Generated " . date( 'M j, Y g:i A' ) . "</p>
            </div>

            <div style='padding:24px' class='mdcna-report-wrap'>
                <!-- KPI Summary — 3x2 grid that stacks on mobile -->
                <table style='width:100%;border-collapse:separate;border-spacing:8px;margin-bottom:24px'>
                    <tr class='mdcna-kpi-row'>
                        <td style='padding:16px;text-align:center;background:#f0fdf4;border-radius:8px;width:50%'>
                            <div style='font-size:28px;font-weight:800;color:#16a34a'>{$data['new_registrations']}</div>
                            <div style='font-size:11px;color:#666;text-transform:uppercase;margin-top:4px'>New Registrations</div>
                        </td>
                        <td style='padding:16px;text-align:center;background:#eff6ff;border-radius:8px;width:50%'>
                            <div style='font-size:28px;font-weight:800;color:#2563eb'>{$data['total_registrations']}</div>
                            <div style='font-size:11px;color:#666;text-transform:uppercase;margin-top:4px'>Total Registrations</div>
                        </td>
                    </tr>
                    <tr class='mdcna-kpi-row'>
                        <td style='padding:16px;text-align:center;background:#fefce8;border-radius:8px;width:50%'>
                            <div style='font-size:28px;font-weight:800;color:#ca8a04'>\${$new_donations_fmt}</div>
                            <div style='font-size:11px;color:#666;text-transform:uppercase;margin-top:4px'>New Donations</div>
                        </td>
                        <td style='padding:16px;text-align:center;background:#fdf2f8;border-radius:8px;width:50%'>
                            <div style='font-size:28px;font-weight:800;color:#e91e8c'>\${$total_donations_fmt}</div>
                            <div style='font-size:11px;color:#666;text-transform:uppercase;margin-top:4px'>Total Donations</div>
                        </td>
                    </tr>
                    <tr class='mdcna-kpi-row'>
                        <td style='padding:16px;text-align:center;background:#f0f9ff;border-radius:8px;width:50%'>
                            <div style='font-size:28px;font-weight:800;color:#0891b2'>\${$new_revenue_fmt}</div>
                            <div style='font-size:11px;color:#666;text-transform:uppercase;margin-top:4px'>New Revenue</div>
                        </td>
                        <td style='padding:16px;text-align:center;background:#faf5ff;border-radius:8px;width:50%'>
                            <div style='font-size:28px;font-weight:800;color:#7c3aed'>\${$total_revenue_fmt}</div>
                            <div style='font-size:11px;color:#666;text-transform:uppercase;margin-top:4px'>Total Revenue</div>
                        </td>
                    </tr>
                </table>

                <!-- New Registrants -->
                <h3 style='font-size:14px;font-weight:600;color:#333;margin:0 0 10px'>New Registrations ({$period_label})</h3>
                <div style='overflow-x:auto;-webkit-overflow-scrolling:touch'>
                    <table class='mdcna-reg-table' style='width:100%;border-collapse:collapse;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;min-width:480px'>
                        <thead>
                            <tr style='background:#1e1e2e;color:#fff'>
                                <th style='padding:8px 10px;text-align:left;font-size:12px'>Name</th>
                                <th style='padding:8px 10px;text-align:left;font-size:12px' class='mdcna-hide-mobile'>Email</th>
                                <th style='padding:8px 10px;text-align:left;font-size:12px'>Clean Date</th>
                                <th style='padding:8px 10px;text-align:left;font-size:12px'>Time Clean</th>
                                <th style='padding:8px 10px;text-align:right;font-size:12px'>Donation</th>
                            </tr>
                        </thead>
                        <tbody>{$registrant_rows}</tbody>
                    </table>
                </div>

                <!-- Full Attendee Roster (for check-in / badges / welcome bags) -->
                <h3 style='font-size:14px;font-weight:600;color:#333;margin:28px 0 4px'>Full Attendee Roster ({$data['total_registrations']} total)</h3>
                <p style='font-size:12px;color:#666;margin:0 0 10px'>Complete list of all registered attendees, sorted by last name. A CSV copy is attached to this email for printing badges and check-in lists.</p>
                <div style='overflow-x:auto;-webkit-overflow-scrolling:touch'>
                    <table class='mdcna-reg-table' style='width:100%;border-collapse:collapse;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;min-width:480px'>
                        <thead>
                            <tr style='background:#1e1e2e;color:#fff'>
                                <th style='padding:8px 10px;text-align:left;font-size:12px;width:40px'>#</th>
                                <th style='padding:8px 10px;text-align:left;font-size:12px'>Name</th>
                                <th style='padding:8px 10px;text-align:left;font-size:12px' class='mdcna-hide-mobile'>Email</th>
                                <th style='padding:8px 10px;text-align:left;font-size:12px'>Clean Date</th>
                                <th style='padding:8px 10px;text-align:center;font-size:12px'>Qty</th>
                            </tr>
                        </thead>
                        <tbody>{$roster_rows}</tbody>
                    </table>
                </div>
            </div>

            <div style='background:#222;padding:12px;text-align:center'>
                <p style='color:#aaa;font-size:12px;margin:0'>MDCNA 2026 · Miami Beach · August 7–9, 2026</p>
            </div>
        </div>
        </body></html>";
    }

    /**
     * Build a CSV file of the full attendee roster. Returns the absolute file path.
     * Caller is responsible for deleting the file after wp_mail() runs.
     */
    private static function build_attendee_csv( array $rows ): string {
        $upload  = wp_upload_dir();
        $dir     = trailingslashit( $upload['basedir'] ) . 'mdcna-reports';
        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        $path = $dir . '/mdcna-attendees-' . date( 'Y-m-d-His' ) . '.csv';

        $fp = fopen( $path, 'w' );
        if ( ! $fp ) return '';

        fputcsv( $fp, [ '#', 'First Name', 'Last Name', 'Email', 'Phone', 'Clean Date', 'Time Clean', 'Qty', 'Donation', 'Registered At' ] );

        $i = 0;
        foreach ( $rows as $row ) {
            $i++;
            $days = self::days_since( $row->clean_date );
            fputcsv( $fp, [
                $i,
                $row->first_name,
                $row->last_name,
                $row->email,
                $row->phone ?? '',
                $row->clean_date,
                self::format_clean_time( $days ),
                (int) $row->qty,
                number_format( (float) $row->donation, 2 ),
                $row->created_at,
            ] );
        }
        fclose( $fp );
        return $path;
    }

    private function send_report( string $period ): bool {
        $recipients = self::get_report_recipients();
        if ( empty( $recipients ) ) {
            self::log( "Email report ({$period}): No recipients configured, skipping." );
            return false;
        }

        $data    = self::build_report_data( $period );
        $html    = self::build_report_html( $data );
        $subject = ( $period === 'daily' )
            ? '[MDCNA 2026] Daily Report — ' . date( 'M j, Y' )
            : '[MDCNA 2026] Weekly Report — Week of ' . date( 'M j, Y', strtotime( '-7 days' ) );

        $csv_path    = self::build_attendee_csv( $data['all_rows'] );
        $attachments = $csv_path ? [ $csv_path ] : [];

        $sent = wp_mail( $recipients, $subject, $html, self::report_email_headers(), $attachments );

        if ( $csv_path && file_exists( $csv_path ) ) {
            @unlink( $csv_path );
        }

        self::log( "Email report ({$period}) sent to " . implode( ', ', $recipients ) . " — " . ( $sent ? 'OK' : 'FAILED' ) );
        return $sent;
    }

    public function send_daily_report(): void {
        $settings = self::get_email_report_settings();
        if ( $settings['daily_enabled'] ) {
            $this->send_report( 'daily' );
        }
    }

    public function send_weekly_report(): void {
        $settings = self::get_email_report_settings();
        if ( $settings['weekly_enabled'] ) {
            $this->send_report( 'weekly' );
        }
    }

    public function ajax_send_test_email(): void {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['nonce'] ?? '', 'mdcna_email_reports' ) ) {
            wp_send_json_error( 'Forbidden' );
        }

        $type       = sanitize_text_field( $_POST['report_type'] ?? 'daily' );
        $recipients = self::get_report_recipients();

        if ( empty( $recipients ) ) {
            wp_send_json_error( 'No valid recipient emails configured. Please save settings first.' );
        }

        $data    = self::build_report_data( $type );
        $html    = self::build_report_html( $data );
        $subject = '[MDCNA 2026] TEST ' . ucfirst( $type ) . ' Report — ' . date( 'M j, Y g:i A' );

        $csv_path    = self::build_attendee_csv( $data['all_rows'] );
        $attachments = $csv_path ? [ $csv_path ] : [];

        $sent = wp_mail( $recipients, $subject, $html, self::report_email_headers(), $attachments );

        if ( $csv_path && file_exists( $csv_path ) ) {
            @unlink( $csv_path );
        }

        if ( $sent ) {
            self::log( "Test {$type} report sent to: " . implode( ', ', $recipients ) );
            wp_send_json_success( [ 'message' => 'Test email sent to: ' . implode( ', ', $recipients ) ] );
        } else {
            self::log( "Test {$type} report FAILED to send.", 'error' );
            wp_send_json_error( 'Failed to send email. Check your WordPress mail configuration.' );
        }
    }

    public function admin_email_reports_page(): void {
        $settings = self::get_email_report_settings();
        $nonce    = wp_create_nonce( 'mdcna_email_reports' );

        // Next scheduled times
        $next_daily  = wp_next_scheduled( 'mdcna_cdt_daily_report' );
        $next_weekly = wp_next_scheduled( 'mdcna_cdt_weekly_report' );
        ?>
        <style>
            #mdcna-email-wrap .mdcna-email-grid { display:flex; gap:20px; flex-wrap:wrap; }
            #mdcna-email-wrap .mdcna-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:24px; box-shadow:0 1px 4px rgba(0,0,0,.06); }
            #mdcna-email-wrap .mdcna-card-settings { flex:1; min-width:300px; }
            #mdcna-email-wrap .mdcna-card-test { min-width:260px; max-width:360px; align-self:flex-start; }
            #mdcna-email-wrap .form-table th { padding:12px 10px 12px 0; width:160px; }
            #mdcna-email-wrap .form-table td { padding:12px 0; }
            #mdcna-email-wrap input.regular-text,
            #mdcna-email-wrap textarea.large-text { max-width:100%; box-sizing:border-box; }
            @media screen and (max-width:782px) {
                #mdcna-email-wrap .mdcna-email-grid { flex-direction:column; }
                #mdcna-email-wrap .mdcna-card-settings { min-width:0; }
                #mdcna-email-wrap .mdcna-card-test { max-width:none; }
                #mdcna-email-wrap .form-table,
                #mdcna-email-wrap .form-table tbody,
                #mdcna-email-wrap .form-table tr,
                #mdcna-email-wrap .form-table th,
                #mdcna-email-wrap .form-table td { display:block; width:100%; }
                #mdcna-email-wrap .form-table th { padding-bottom:4px; }
                #mdcna-email-wrap .form-table td { padding-top:0; }
            }
        </style>
        <div class="wrap" id="mdcna-email-wrap">
            <h1>MDCNA 2026 — Email Reports</h1>
            <p style="color:#666;font-size:13px;margin:-8px 0 20px">Configure automated daily and weekly email reports for registration and donation activity.</p>

            <div class="mdcna-email-grid">
                <!-- Settings Card -->
                <div class="mdcna-card mdcna-card-settings">
                    <h2 style="margin:0 0 16px;font-size:16px;font-weight:600">Report Settings</h2>

                    <table class="form-table" style="margin:0">
                        <tr>
                            <th><label for="mdcna-from-email">From Email</label></th>
                            <td>
                                <input type="email" id="mdcna-from-email" class="regular-text" value="<?php echo esc_attr( $settings['from_email'] ); ?>" placeholder="support@namiamiconvention.org">
                                <p class="description">The sender email address for report emails.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="mdcna-recipients">Recipient Emails</label></th>
                            <td>
                                <textarea id="mdcna-recipients" class="large-text" rows="4" placeholder="email1@example.com&#10;email2@example.com"><?php echo esc_textarea( $settings['recipients'] ); ?></textarea>
                                <p class="description">One email per line, or separated by commas. These addresses will receive the reports.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Report Frequency</th>
                            <td>
                                <label style="display:block;margin-bottom:10px">
                                    <input type="checkbox" id="mdcna-daily-enabled" <?php checked( $settings['daily_enabled'] ); ?>>
                                    <strong>Daily Report</strong> — sent every morning at 8:00 AM
                                    <?php if ( $next_daily ) : ?>
                                        <br><span style="color:#16a34a;font-size:12px;margin-left:24px">Next: <?php echo date( 'M j, Y g:i A', $next_daily ); ?></span>
                                    <?php endif; ?>
                                </label>
                                <label style="display:block">
                                    <input type="checkbox" id="mdcna-weekly-enabled" <?php checked( $settings['weekly_enabled'] ); ?>>
                                    <strong>Weekly Report</strong> — sent every Monday at 8:00 AM
                                    <?php if ( $next_weekly ) : ?>
                                        <br><span style="color:#16a34a;font-size:12px;margin-left:24px">Next: <?php echo date( 'M j, Y g:i A', $next_weekly ); ?></span>
                                    <?php endif; ?>
                                </label>
                            </td>
                        </tr>
                    </table>

                    <div style="margin-top:16px;padding-top:16px;border-top:1px solid #eee">
                        <button id="mdcna-save-settings" class="button button-primary">Save Settings</button>
                        <span id="mdcna-save-status" style="margin-left:12px;font-style:italic;color:#666"></span>
                    </div>
                </div>

                <!-- Action Cards Column -->
                <div class="mdcna-card mdcna-card-test" style="display:flex;flex-direction:column;gap:20px;padding:0;background:transparent;border:none;box-shadow:none">

                    <!-- Download CSV Card -->
                    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;box-shadow:0 1px 4px rgba(0,0,0,.06)">
                        <h2 style="margin:0 0 16px;font-size:16px;font-weight:600">Download Attendee CSV</h2>
                        <p style="color:#555;font-size:13px;margin-bottom:16px">Download the complete roster of all registered attendees — useful for printing badges, welcome bags, and check-in lists.</p>
                        <a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=mdcna_export_csv&nonce=' . wp_create_nonce( 'mdcna_export' ) ) ); ?>"
                           class="button button-primary" style="background:#0891b2;border-color:#0e7490;text-decoration:none">
                            ⬇ Download CSV
                        </a>
                    </div>

                    <!-- Test Email Card -->
                    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;box-shadow:0 1px 4px rgba(0,0,0,.06)">
                        <h2 style="margin:0 0 16px;font-size:16px;font-weight:600">Send Test Email</h2>
                        <p style="color:#555;font-size:13px;margin-bottom:16px">Send a test report to all configured recipients to verify email delivery is working.</p>

                        <label style="display:block;margin-bottom:12px">
                            <strong>Report Type:</strong><br>
                            <select id="mdcna-test-type" style="margin-top:4px;min-width:200px">
                                <option value="daily">Daily Report</option>
                                <option value="weekly">Weekly Report</option>
                            </select>
                        </label>

                        <button id="mdcna-send-test" class="button button-secondary" style="background:#e91e8c;color:#fff;border-color:#d4187f">
                            Send Test Email
                        </button>
                        <div id="mdcna-test-status" style="margin-top:10px;font-size:13px"></div>
                    </div>
                </div>
            </div>
        </div>

        <script>
        (function($){
            const nonce = '<?php echo $nonce; ?>';

            $('#mdcna-save-settings').on('click', function(){
                const btn = $(this);
                btn.prop('disabled', true);
                $('#mdcna-save-status').css('color','#666').text('Saving…');

                $.post(ajaxurl, {
                    action: 'mdcna_save_email_settings',
                    nonce: nonce,
                    recipients: $('#mdcna-recipients').val(),
                    from_email: $('#mdcna-from-email').val(),
                    daily_enabled: $('#mdcna-daily-enabled').is(':checked') ? 1 : 0,
                    weekly_enabled: $('#mdcna-weekly-enabled').is(':checked') ? 1 : 0
                }, function(res){
                    if(res.success){
                        $('#mdcna-save-status').css('color','#16a34a').text('✓ ' + res.data.message);
                    } else {
                        $('#mdcna-save-status').css('color','red').text('Error: ' + res.data);
                    }
                    btn.prop('disabled', false);
                }).fail(function(){
                    $('#mdcna-save-status').css('color','red').text('Request failed.');
                    btn.prop('disabled', false);
                });
            });

            $('#mdcna-send-test').on('click', function(){
                const btn = $(this);
                btn.prop('disabled', true);
                $('#mdcna-test-status').css('color','#666').html('Sending test email…');

                $.post(ajaxurl, {
                    action: 'mdcna_send_test_email',
                    nonce: nonce,
                    report_type: $('#mdcna-test-type').val()
                }, function(res){
                    if(res.success){
                        $('#mdcna-test-status').css('color','#16a34a').html('✓ ' + res.data.message);
                    } else {
                        $('#mdcna-test-status').css('color','red').html('✗ ' + res.data);
                    }
                    btn.prop('disabled', false);
                }).fail(function(){
                    $('#mdcna-test-status').css('color','red').html('Request failed.');
                    btn.prop('disabled', false);
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    // ─────────────────────────────────────────────────────────
    //  STYLES
    // ─────────────────────────────────────────────────────────
    private static function admin_css(): string {
        return "
        /* ── Table headers ── */
        #mdcna-admin-wrap .mdcna-table th { background: #1e1e2e; color: #fff; }
        #mdcna-admin-wrap .mdcna-table th a { color: #fff; }

        /* ── Badges ── */
        .mdcna-badge {
            display: inline-block; font-size: 10px; font-weight: 700;
            padding: 1px 6px; border-radius: 10px; vertical-align: middle;
            margin-left: 4px; text-transform: uppercase; letter-spacing: .04em;
        }
        .mdcna-badge-new  { background: #d4f0dc; color: #1a7a3a; border: 1px solid #a8ddb5; }
        .mdcna-badge-test { background: #fff3cd; color: #7a5c00; border: 1px solid #ffe08a; }
        .mdcna-badge-vet  { background: #e8e0ff; color: #5b21b6; border: 1px solid #c4b5fd; }

        /* ── Row tints ── */
        .mdcna-row-new  td { background: #f0fdf4 !important; }
        .mdcna-row-test td { background: #fffbeb !important; }
        .mdcna-row-vet  td { background: #f5f3ff !important; }

        /* ── Inline date editor ── */
        .mdcna-edit-date-btn { opacity: 0; transition: opacity .15s; cursor: pointer; }
        tr:hover .mdcna-edit-date-btn { opacity: 1; }

        /* ── Delete button ── */
        .mdcna-delete-btn { opacity: 0; transition: opacity .15s; cursor: pointer; font-size: 15px; }
        tr:hover .mdcna-delete-btn { opacity: 1; }

        /* ── Stats KPI grid ── */
        .mdcna-stats-grid { display: flex; flex-wrap: wrap; gap: 16px; margin: 20px 0; }
        .mdcna-stat-box {
            background: #fff; border: 1px solid #e5e7eb;
            border-radius: 12px; padding: 18px 22px; min-width: 150px;
            flex: 1; text-align: center; box-shadow: 0 1px 4px rgba(0,0,0,.06);
        }
        .mdcna-stat-icon { font-size: 22px; margin-bottom: 6px; }
        .mdcna-stat-val { font-size: 26px; font-weight: 800; color: #e91e8c; line-height: 1; }
        .mdcna-stat-lbl { font-size: 11px; color: #888; margin-top: 4px; text-transform: uppercase; letter-spacing: .08em; }
        .mdcna-stat-sub { font-size: 11px; color: #bbb; margin-top: 3px; }

        /* ── Charts layout ── */
        #mdcna-stats-wrap h3 { font-size: 14px; font-weight: 600; margin: 0 0 12px; color: #333; }
        .mdcna-charts-row { display: flex; flex-wrap: wrap; gap: 16px; margin: 16px 0; }
        .mdcna-chart-box {
            background: #fff; border: 1px solid #e5e7eb; border-radius: 12px;
            padding: 20px 24px; box-shadow: 0 1px 4px rgba(0,0,0,.06); min-width: 220px;
        }
        ";
    }

    private static function frontend_css(): string {
        return "
        /* ── Shared ── */
        .mdcna-clean-time-card,
        .mdcna-total-time,
        .mdcna-leaderboard {
            font-family: 'Segoe UI', Arial, sans-serif;
            box-sizing: border-box;
            max-width: 100%;
            overflow: hidden;
        }
        .mdcna-clean-time-card *,
        .mdcna-total-time *,
        .mdcna-leaderboard * {
            box-sizing: border-box;
        }

        /* ── Personal Clean Time Card ── */
        .mdcna-clean-time-card {
            display: inline-block;
            background: linear-gradient(135deg, #ff2d9b, #7b2fff, #00d4ff);
            border-radius: 20px;
            padding: 3px;
            box-shadow: 0 0 32px rgba(255,45,155,0.45), 0 0 64px rgba(123,47,255,0.2);
        }
        .mdcna-cdc-inner {
            background: rgba(10,8,28,0.97);
            border-radius: 18px;
            padding: 32px 48px;
            text-align: center;
            color: #fff;
        }
        .mdcna-cdc-icon { font-size:36px; margin-bottom:8px; }
        .mdcna-cdc-name {
            font-size: 11px; text-transform: uppercase;
            letter-spacing: .18em; color: #9b8ec4; margin-bottom: 12px;
        }
        .mdcna-cdc-days {
            font-size: 72px; font-weight: 900; line-height: 1;
            background: linear-gradient(135deg, #ff2d9b, #a855f7, #00d4ff);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text;
            filter: drop-shadow(0 0 12px rgba(255,45,155,0.5));
        }
        .mdcna-cdc-days-label {
            font-size: 10px; text-transform: uppercase;
            letter-spacing: .2em; color: #6b5b9a; margin: 6px 0;
        }
        .mdcna-cdc-label { font-size: 17px; font-weight: 700; color: #e8d5ff; margin: 10px 0 4px; }
        .mdcna-cdc-date { font-size: 12px; color: #7b6faa; }

        /* ── Total Time Counter ── */
        .mdcna-total-time {
            text-align: center;
            padding: 40px 32px;
        }
        .mdcna-tt-segments {
            display: flex;
            justify-content: center;
            align-items: flex-start;
            gap: 8px;
        }
        .mdcna-tt-seg {
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .mdcna-tt-seg-label {
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: .15em;
            color: rgba(255, 255, 255, 0.7);
            margin-top: 6px;
            font-weight: 600;
        }
        .mdcna-tt-sep {
            font-size: 60px;
            font-weight: 900;
            line-height: 1;
            background: linear-gradient(180deg, #ffffff 30%, #a8e8ff 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            padding: 0 4px;
        }
        .mdcna-tt-number {
            font-size: 80px;
            font-weight: 900;
            line-height: 1;
            background: linear-gradient(180deg, #ffffff 30%, #a8e8ff 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            filter: drop-shadow(0 0 8px rgba(255,255,255,0.3));
            letter-spacing: -2px;
        }
        .mdcna-tt-label {
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: .25em;
            color: rgba(255, 255, 255, 0.9);
            margin: 12px 0 6px;
            font-weight: 600;
        }
        .mdcna-tt-sub {
            font-size: 16px;
            color: rgb(255, 255, 255);
            letter-spacing: .02em;
            font-weight: 600;
        }

        /* ── Leaderboard ── */
        .mdcna-leaderboard {
            background: rgba(10, 8, 28, 0.88);
            border-radius: 18px;
            border: 1px solid rgba(255, 45, 155, 0.2);
            padding: 24px;
            box-shadow: 0 0 40px rgba(123,47,255,0.15);
        }
        .mdcna-lb-title {
            font-size: 16px; font-weight: 800; margin-bottom: 16px;
            text-align: center; text-transform: uppercase; letter-spacing: .12em;
            background: linear-gradient(90deg, #ff2d9b, #a855f7, #00d4ff);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .mdcna-lb-grid { display: grid; gap: 6px; }
        .mdcna-lb-item {
            display: grid; grid-template-columns: 32px 1fr 1fr auto;
            align-items: center; gap: 10px;
            padding: 10px 14px;
            background: rgba(255,255,255,0.04);
            border-radius: 10px;
            border-left: 3px solid;
            border-image: linear-gradient(180deg, #ff2d9b, #7b2fff) 1;
            transition: background .2s;
        }
        .mdcna-lb-item:hover { background: rgba(255,45,155,0.08); }
        .mdcna-lb-rank { font-size: 10px; color: #6b5b9a; font-weight: 800; }
        .mdcna-lb-name { font-weight: 600; color: #e8d5ff; font-size: 14px; }
        .mdcna-lb-time { color: #9b8ec4; font-size: 13px; }
        .mdcna-lb-days { font-size: 12px; font-weight: 800;
            background: linear-gradient(135deg, #ff2d9b, #a855f7);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        /* ── Login / no-record messages ── */
        .mdcna-login-msg, .mdcna-no-record {
            color: #9b8ec4; font-size: 14px; padding: 12px;
            background: rgba(10,8,28,0.7); border-radius: 10px;
            border: 1px solid rgba(255,45,155,0.15); text-align: center;
        }
        .mdcna-login-msg a, .mdcna-no-record a { color: #ff2d9b; }

        /* ── Responsive — Tablet ── */
        @media (max-width: 768px) {
            /* Total Time */
            .mdcna-tt-number { font-size: 52px; letter-spacing: -1px; }
            .mdcna-tt-sep { font-size: 40px; }
            .mdcna-tt-seg-label { font-size: 12px; }
            .mdcna-total-time { padding: 28px 16px; }

            /* Clean Time Card */
            .mdcna-cdc-inner { padding: 24px 28px; }
            .mdcna-cdc-days { font-size: 54px; }
            .mdcna-cdc-label { font-size: 15px; }

            /* Leaderboard */
            .mdcna-leaderboard { padding: 16px; }
            .mdcna-lb-item {
                grid-template-columns: 28px 1fr auto;
                gap: 8px; padding: 8px 10px;
            }
            .mdcna-lb-time { display: none; }
            .mdcna-lb-name { font-size: 13px; }
            .mdcna-lb-days { font-size: 11px; }
        }

        /* ── Responsive — Small phones ── */
        @media (max-width: 480px) {
            /* Total Time */
            .mdcna-tt-number { font-size: 36px; letter-spacing: 0; }
            .mdcna-tt-sep { font-size: 28px; padding: 0 2px; }
            .mdcna-tt-segments { gap: 4px; }
            .mdcna-tt-seg-label { font-size: 10px; letter-spacing: .08em; }
            .mdcna-tt-label { font-size: 12px; letter-spacing: .15em; }
            .mdcna-tt-sub { font-size: 13px; }
            .mdcna-total-time { padding: 20px 12px; }

            /* Clean Time Card */
            .mdcna-clean-time-card { display: block; }
            .mdcna-cdc-inner { padding: 20px 16px; }
            .mdcna-cdc-icon { font-size: 28px; }
            .mdcna-cdc-days { font-size: 42px; }
            .mdcna-cdc-label { font-size: 14px; }
            .mdcna-cdc-name { font-size: 10px; letter-spacing: .12em; }
            .mdcna-cdc-date { font-size: 11px; }

            /* Leaderboard */
            .mdcna-leaderboard { padding: 12px; border-radius: 12px; }
            .mdcna-lb-title { font-size: 13px; letter-spacing: .08em; }
            .mdcna-lb-item {
                grid-template-columns: 24px 1fr auto;
                gap: 6px; padding: 7px 8px;
                border-radius: 8px;
            }
            .mdcna-lb-name { font-size: 12px; }
            .mdcna-lb-days { font-size: 10px; }
            .mdcna-lb-rank { font-size: 9px; }

            /* Messages */
            .mdcna-login-msg, .mdcna-no-record { font-size: 13px; padding: 10px; }
        }
        ";
    }

    // ─────────────────────────────────────────────────────────
    //  JAVASCRIPT
    // ─────────────────────────────────────────────────────────
    private static function frontend_js(): string {
        return <<<'JS'
document.addEventListener('DOMContentLoaded', function() {

    // ── Utility: easeOutExpo ──────────────────────────────────
    function easeOutExpo(t) {
        return t === 1 ? 1 : 1 - Math.pow(2, -10 * t);
    }

    // ── Count-up animation ────────────────────────────────────
    function countUp(el, target, duration) {
        if (!el || target === 0) {
            if (el) el.textContent = '0';
            return;
        }
        var start     = 0;
        var startTime = null;

        function step(timestamp) {
            if (!startTime) startTime = timestamp;
            var elapsed  = timestamp - startTime;
            var progress = Math.min(elapsed / duration, 1);
            var eased    = easeOutExpo(progress);
            var current  = Math.round(eased * target);

            el.textContent = current.toLocaleString('en-US');

            if (progress < 1) {
                requestAnimationFrame(step);
            } else {
                el.textContent = target.toLocaleString('en-US');
            }
        }
        requestAnimationFrame(step);
    }

    // ── IntersectionObserver: fire once when visible ──────────
    function observeAndAnimate(selector, callback) {
        var elements = document.querySelectorAll(selector);
        if (!elements.length) return;

        if (!('IntersectionObserver' in window)) {
            elements.forEach(callback);
            return;
        }

        var observer = new IntersectionObserver(function(entries, obs) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    callback(entry.target);
                    obs.unobserve(entry.target);
                }
            });
        }, { threshold: 0.3 });

        elements.forEach(function(el) { observer.observe(el); });
    }

    // ── Total Time Counter (Years : Days : Hours) ──────────────
    observeAndAnimate('.mdcna-total-time', function(container) {
        var nums = container.querySelectorAll('.mdcna-tt-number[data-target]');
        nums.forEach(function(el) {
            var target   = parseInt(el.getAttribute('data-target'), 10) || 0;
            var duration = Math.min(2500, Math.max(800, target * 0.5));
            countUp(el, target, duration);
        });
    });

    // ── Personal Clean Time Card ──────────────────────────────
    observeAndAnimate('.mdcna-cdc-days', function(el) {
        var card   = el.closest('.mdcna-clean-time-card');
        var target = card ? parseInt(card.getAttribute('data-days'), 10) || 0 : 0;
        countUp(el, target, 1800);
    });

    // ── Leaderboard: stagger fade-in rows ─────────────────────
    observeAndAnimate('.mdcna-leaderboard', function(el) {
        var items = el.querySelectorAll('.mdcna-lb-item');
        items.forEach(function(item, i) {
            item.style.opacity = '0';
            item.style.transform = 'translateY(10px)';
            item.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
            setTimeout(function() {
                item.style.opacity = '1';
                item.style.transform = 'translateY(0)';
            }, i * 60);
        });
    });

});
JS;
    }
}

// ─────────────────────────────────────────────────────────────
//  BOOT
// ─────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', function () {
    MDCNA_CDT::instance();
} );
