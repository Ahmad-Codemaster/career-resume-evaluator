<?php
namespace CRE;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Settings {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'wp_ajax_cre_test_connection', [ $this, 'test_api_connection' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );
        add_action( 'admin_init', [ $this, 'handle_csv_export' ] );
    }

    public function add_admin_menu() {
        add_options_page(
            'Career Evaluator Settings',
            'Career Evaluator',
            'manage_options',
            'cre-settings',
            [ $this, 'settings_page_html' ]
        );
    }

    public function register_settings() {
        register_setting( 'cre_options', 'cre_grok_api_key', [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'cre_options', 'cre_grok_model', [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'cre_options', 'cre_adzuna_app_id', [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'cre_options', 'cre_adzuna_app_key', [ 'sanitize_callback' => 'sanitize_text_field' ] );
        
        // NEW: Stripe Settings (Including the Enable Payments Toggle)
        register_setting( 'cre_options', 'cre_enable_payments', [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'cre_options', 'cre_stripe_pub_key', [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'cre_options', 'cre_stripe_secret_key', [ 'sanitize_callback' => 'sanitize_text_field' ] );
    }

    public function handle_csv_export() {
        if ( isset( $_POST['cre_action'] ) && 'export_csv' === $_POST['cre_action'] && current_user_can( 'manage_options' ) ) {
            check_admin_referer( 'cre_export_csv', 'cre_export_nonce' );

            require_once CRE_PLUGIN_DIR . 'includes/class-db.php';
            $submissions = DB::get_all_for_export();

            header( 'Content-Type: text/csv' );
            header( 'Content-Disposition: attachment; filename="career_submissions_' . date( 'Y-m-d' ) . '.csv"' );

            $output = fopen( 'php://output', 'w' );
            
            // NEW: Added all form fields to the CSV headers
            fputcsv( $output, [ 
                'ID', 'Date', 'Name', 'Email', 'Resume ID', 
                'Location', 'Work Setup', 'Current Salary', 
                'Target Field', 'Target Role', 'Target Salary', 
                'Additional Skills', 'Restrictions',
                'Entry Level Rec', 'AI Summary' 
            ] );

            foreach ( $submissions as $row ) {
                $input = json_decode( $row->input_data, true );
                $ai = json_decode( $row->ai_result_data, true );
                $top_role = $ai['careers'][0]['entry_role_title'] ?? $ai['careers'][0]['role'] ?? 'N/A';
                $summary = $ai['analysis']['summary'] ?? '';

                // NEW: Mapped all the correct form inputs to the CSV columns
                fputcsv( $output, [
                    $row->id,
                    $row->created_at,
                    $row->candidate_name,
                    $row->user_email,
                    $row->resume_attachment_id,
                    $input['location'] ?? '',
                    $input['work_setup'] ?? '',
                    $input['cur_salary'] ?? '',
                    $input['field'] ?? '',
                    $input['role_title'] ?? '',
                    $input['tgt_salary'] ?? '',
                    $input['additional_skills'] ?? '',
                    $input['exclude'] ?? '',
                    $top_role,
                    $summary
                ] );
            }

            fclose( $output );
            exit;
        }
    }

    public function settings_page_html() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'settings';
        ?>
        <div class="wrap">
            <h1>Lumen Path Career Roadmap</h1>

            <h2 class="nav-tab-wrapper">
                <a href="?page=cre-settings&tab=settings" class="nav-tab <?php echo $active_tab == 'settings' ? 'nav-tab-active' : ''; ?>">Settings</a>
                <a href="?page=cre-settings&tab=history" class="nav-tab <?php echo $active_tab == 'history' ? 'nav-tab-active' : ''; ?>">Submission History</a>
            </h2>

            <?php if ( 'settings' === $active_tab ) : ?>
                <?php $this->render_settings_tab(); ?>
            <?php else : ?>
                <?php $this->render_history_tab(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_settings_tab() {
        $models = [
            'grok-4-latest',
            'grok-4-0709',
            'grok-3',
            'grok-3-mini',
            'grok-3-fast',
            'grok-2-latest',
            'grok-2-vision-1212',
        ];
        $current_model = get_option( 'cre_grok_model', 'grok-3' );
        ?>
        <div class="card" style="max-width: 100%; margin-top: 20px; padding: 10px 20px;">
            <h2>Usage Shortcode</h2>
            <p>Paste this on any page:</p>
            <code>[career_evaluator]</code>
        </div>

        <form action="options.php" method="post">
            <?php
            settings_fields( 'cre_options' );
            do_settings_sections( 'cre_options' );
            ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Grok API Key</th>
                    <td>
                        <input type="password" name="cre_grok_api_key" value="<?php echo esc_attr( get_option( 'cre_grok_api_key' ) ); ?>" class="regular-text" />
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">AI Model</th>
                    <td>
                        <select name="cre_grok_model">
                            <?php foreach ( $models as $model ) : ?>
                                <option value="<?php echo esc_attr( $model ); ?>" <?php selected( $current_model, $model ); ?>>
                                    <?php echo esc_html( $model ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>

                <tr valign="top">
                    <th scope="row" colspan="2"><hr><h3>Stripe Payment Gateway</h3></th>
                </tr>
                <tr valign="top">
                    <th scope="row">Enable Payments</th>
                    <td>
                        <label>
                            <input type="checkbox" name="cre_enable_payments" value="yes" <?php checked( get_option('cre_enable_payments', 'no'), 'yes' ); ?> />
                            <strong>Require $1.00 Payment to unlock reports</strong> (Uncheck to make all reports instantly free)
                        </label>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Publishable Key</th>
                    <td><input type="text" name="cre_stripe_pub_key" value="<?php echo esc_attr( get_option( 'cre_stripe_pub_key' ) ); ?>" class="regular-text" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Secret Key</th>
                    <td><input type="password" name="cre_stripe_secret_key" value="<?php echo esc_attr( get_option( 'cre_stripe_secret_key' ) ); ?>" class="regular-text" /></td>
                </tr>

                <tr valign="top">
                    <th scope="row" colspan="2"><hr><h3>Adzuna Job Search</h3></th>
                </tr>
                <tr valign="top">
                    <th scope="row">App ID</th>
                    <td><input type="text" name="cre_adzuna_app_id" value="<?php echo esc_attr( get_option( 'cre_adzuna_app_id' ) ); ?>" class="regular-text" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">App Key</th>
                    <td><input type="password" name="cre_adzuna_app_key" value="<?php echo esc_attr( get_option( 'cre_adzuna_app_key' ) ); ?>" class="regular-text" /></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <hr>
        <button id="cre-test-grok" class="button button-secondary">Test API Connection</button>
        <p id="cre-test-result" style="margin-top:10px; font-weight:bold;"></p>
        <?php
    }

  private function render_history_tab() {
        require_once CRE_PLUGIN_DIR . 'includes/class-db.php';
        $submissions = DB::get_submissions( 50 );
        ?>
        <div style="margin-top: 20px;">
            <form method="post" style="display:inline-block; margin-bottom: 20px;">
                <input type="hidden" name="cre_action" value="export_csv">
                <?php wp_nonce_field( 'cre_export_csv', 'cre_export_nonce' ); ?>
                <button type="submit" class="button button-primary">Export to CSV</button>
            </form>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Target Role</th>
                        <th>Immediate Next Step</th>
                        <th>Resume</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $submissions ) ) : ?>
                        <tr><td colspan="6">No submissions yet.</td></tr>
                    <?php else : ?>
                        <?php foreach ( $submissions as $row ) : 
                            $input = json_decode( $row->input_data, true );
                            $ai = json_decode( $row->ai_result_data, true );
                            $top_role = $ai['careers'][0]['entry_role_title'] ?? $ai['careers'][0]['role'] ?? 'N/A';
                            $resume_url = wp_get_attachment_url( $row->resume_attachment_id );
                        ?>
                        <tr>
                            <td><?php echo esc_html( $row->created_at ); ?></td>
                            <td><strong><?php echo esc_html( $row->candidate_name ); ?></strong></td>
                            <td><a href="mailto:<?php echo esc_attr( $row->user_email ); ?>"><?php echo esc_html( $row->user_email ); ?></a></td>
                            
                            <td><?php echo esc_html( $input['role_title'] ?? '-' ); ?></td>
                            
                            <td><?php echo esc_html( $top_role ); ?></td>
                            <td>
                                <?php if ( $resume_url ) : ?>
                                    <a href="<?php echo esc_url( $resume_url ); ?>" target="_blank" class="button button-small">Download</a>
                                <?php else : ?>
                                    Deleted
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function enqueue_admin_scripts( $hook ) {
        if ( 'settings_page_cre-settings' !== $hook ) {
            return;
        }
        wp_enqueue_script( 'cre-admin', CRE_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], CRE_VERSION, true );
        wp_localize_script( 'cre-admin', 'cre_admin_vars', [
            'nonce' => wp_create_nonce( 'cre_test_connection' ),
        ] );
    }

    public function test_api_connection() {
        check_ajax_referer( 'cre_test_connection', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $api_key = get_option( 'cre_grok_api_key' );
        if ( empty( $api_key ) ) {
            wp_send_json_error( 'API Key not saved.' );
        }

        $model = get_option( 'cre_grok_model', 'grok-3' );

        $response = wp_remote_post( 'https://api.x.ai/v1/chat/completions', [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body'    => json_encode( [
                'model'    => $model,
                'messages' => [
                    [ 'role' => 'user', 'content' => 'Say hello' ],
                ],
            ] ),
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 === $code ) {
            wp_send_json_success( 'Connection Successful using ' . $model . '!' );
        } else {
            $body = wp_remote_retrieve_body( $response );
            wp_send_json_error( 'Error: ' . $code . ' - ' . substr( $body, 0, 100 ) );
        }
    }
}