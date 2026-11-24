<?php
/**
 * Plugin Name: Free Surge Newsletter
 * Description: Collects confirmed newsletter subscribers and provides self-service unsubscribe tools.
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: Free Surge
 * Text Domain: free-surge-newsletter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FSN_VERSION', '0.1.0' );
define( 'FSN_DB_VERSION', '1' );
define( 'FSN_PLUGIN_FILE', __FILE__ );

register_activation_hook( __FILE__, 'fsn_activate' );

function fsn_activate() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table_name      = $wpdb->prefix . 'fsn_subscribers';
	$charset_collate = $wpdb->get_charset_collate();
	$sql             = "CREATE TABLE {$table_name} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		email varchar(191) NOT NULL,
		first_name varchar(100) NOT NULL DEFAULT '',
		status varchar(20) NOT NULL DEFAULT 'pending',
		confirmation_token_hash char(64) NOT NULL DEFAULT '',
		unsubscribe_token_hash char(64) NOT NULL DEFAULT '',
		confirmation_expires datetime NULL DEFAULT NULL,
		consented_at datetime NULL DEFAULT NULL,
		confirmed_at datetime NULL DEFAULT NULL,
		consent_source varchar(100) NOT NULL DEFAULT 'shortcode',
		consent_version varchar(20) NOT NULL DEFAULT '1',
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY email (email),
		KEY status (status)
	) {$charset_collate};";

	dbDelta( $sql );
	update_option( 'fsn_db_version', FSN_DB_VERSION );
}

add_action( 'plugins_loaded', 'fsn_maybe_upgrade' );
function fsn_maybe_upgrade() {
	if ( get_option( 'fsn_db_version' ) !== FSN_DB_VERSION ) {
		fsn_activate();
	}
}

add_shortcode( 'fsn_newsletter_form', 'fsn_render_signup_form' );
function fsn_render_signup_form() {
	$notice = isset( $_GET['fsn_notice'] ) ? sanitize_key( wp_unslash( $_GET['fsn_notice'] ) ) : '';
	$notices = array(
		'check_email' => __( 'If this address can be subscribed, we have sent a confirmation email. Please check your inbox.', 'free-surge-newsletter' ),
		'confirmed'   => __( 'Your subscription is confirmed. Thank you!', 'free-surge-newsletter' ),
		'unsubscribed'=> __( 'You have been unsubscribed.', 'free-surge-newsletter' ),
		'invalid'     => __( 'That link is invalid or has expired. Please submit the form again if you still want to subscribe.', 'free-surge-newsletter' ),
		'error'       => __( 'We could not process that request. Please try again later.', 'free-surge-newsletter' ),
	);

	ob_start();
	?>
	<div class="fsn-newsletter">
		<?php if ( isset( $notices[ $notice ] ) ) : ?>
			<p class="fsn-notice" role="status"><?php echo esc_html( $notices[ $notice ] ); ?></p>
		<?php endif; ?>
		<form class="fsn-newsletter__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="fsn_subscribe">
			<?php wp_nonce_field( 'fsn_subscribe', 'fsn_nonce' ); ?>
			<p>
				<label for="fsn-first-name"><?php esc_html_e( 'First name (optional)', 'free-surge-newsletter' ); ?></label>
				<input id="fsn-first-name" type="text" name="first_name" maxlength="100" autocomplete="given-name">
			</p>
			<p>
				<label for="fsn-email"><?php esc_html_e( 'Email address', 'free-surge-newsletter' ); ?></label>
				<input id="fsn-email" type="email" name="email" maxlength="191" autocomplete="email" required>
			</p>
			<p class="fsn-newsletter__trap" aria-hidden="true" style="position:absolute;left:-10000px;">
				<label for="fsn-website"><?php esc_html_e( 'Leave this field empty', 'free-surge-newsletter' ); ?></label>
				<input id="fsn-website" type="text" name="website" tabindex="-1" autocomplete="off">
			</p>
			<p>
				<label>
					<input type="checkbox" name="consent" value="1" required>
					<?php esc_html_e( 'I agree to receive newsletter emails and understand I can unsubscribe at any time.', 'free-surge-newsletter' ); ?>
					<?php if ( get_privacy_policy_url() ) : ?>
						<a href="<?php echo esc_url( get_privacy_policy_url() ); ?>"><?php esc_html_e( 'Privacy policy', 'free-surge-newsletter' ); ?></a>
					<?php endif; ?>
				</label>
			</p>
			<p><button type="submit"><?php esc_html_e( 'Subscribe', 'free-surge-newsletter' ); ?></button></p>
		</form>
	</div>
	<?php
	return ob_get_clean();
}

add_action( 'admin_post_nopriv_fsn_subscribe', 'fsn_handle_subscribe' );
add_action( 'admin_post_fsn_subscribe', 'fsn_handle_subscribe' );
function fsn_handle_subscribe() {
	if ( 'POST' !== $_SERVER['REQUEST_METHOD'] || ! isset( $_POST['fsn_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['fsn_nonce'] ) ), 'fsn_subscribe' ) ) {
		fsn_redirect_with_notice( 'error' );
	}

	$consent = isset( $_POST['consent'] ) ? sanitize_text_field( wp_unslash( $_POST['consent'] ) ) : '';
	if ( ! empty( $_POST['website'] ) || '1' !== $consent ) {
		fsn_redirect_with_notice( 'check_email' );
	}

	$email = isset( $_POST['email'] ) ? sanitize_email( strtolower( trim( wp_unslash( $_POST['email'] ) ) ) ) : '';
	if ( ! is_email( $email ) || strlen( $email ) > 191 ) {
		fsn_redirect_with_notice( 'error' );
	}

	$ip        = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$rate_key  = 'fsn_rate_' . substr( wp_hash( $ip ), 0, 32 );
	$attempts  = (int) get_transient( $rate_key );
	if ( $attempts >= 12 ) {
		fsn_redirect_with_notice( 'check_email' );
	}
	set_transient( $rate_key, $attempts + 1, 15 * MINUTE_IN_SECONDS );

	global $wpdb;
	$table       = $wpdb->prefix . 'fsn_subscribers';
	$first_name  = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
	$first_name  = wp_html_excerpt( $first_name, 100, '' );
	$subscriber  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s LIMIT 1", $email ) );
	$token       = bin2hex( random_bytes( 32 ) );
	$unsubscribe = bin2hex( random_bytes( 32 ) );
	$now         = current_time( 'mysql', true );
	$expires     = gmdate( 'Y-m-d H:i:s', time() + ( 2 * DAY_IN_SECONDS ) );

	if ( $subscriber && 'subscribed' === $subscriber->status ) {
		fsn_redirect_with_notice( 'check_email' );
	}

	$data = array(
		'first_name'               => $first_name,
		'status'                   => 'pending',
		'confirmation_token_hash'  => hash( 'sha256', $token ),
		'unsubscribe_token_hash'   => hash( 'sha256', $unsubscribe ),
		'confirmation_expires'     => $expires,
		'consented_at'             => $now,
		'confirmed_at'             => null,
		'consent_source'           => 'shortcode',
		'consent_version'          => '1',
		'updated_at'               => $now,
	);

	if ( $subscriber ) {
		$saved = $wpdb->update( $table, $data, array( 'id' => (int) $subscriber->id ) );
	} else {
		$data['email']      = $email;
		$data['created_at'] = $now;
		$saved = $wpdb->insert( $table, $data );
	}

	if ( false === $saved ) {
		fsn_redirect_with_notice( 'error' );
	}

	$confirm_url = add_query_arg(
		array(
			'action' => 'fsn_confirm',
			'email'  => $email,
			'token'  => $token,
			'unsubscribe' => $unsubscribe,
		),
		admin_url( 'admin-post.php' )
	);
	$subject = sprintf( __( 'Confirm your subscription to %s', 'free-surge-newsletter' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
	$message = sprintf( __( "Please confirm your newsletter subscription by opening this link:\n\n%s\n\nThis link expires in 48 hours. If you did not request this, you can ignore this email.", 'free-surge-newsletter' ), esc_url_raw( $confirm_url ) );
	wp_mail( $email, $subject, $message );

	fsn_redirect_with_notice( 'check_email' );
}

add_action( 'admin_post_nopriv_fsn_confirm', 'fsn_render_confirm_page' );
add_action( 'admin_post_fsn_confirm', 'fsn_render_confirm_page' );
add_action( 'admin_post_nopriv_fsn_confirm_submit', 'fsn_handle_confirm' );
add_action( 'admin_post_fsn_confirm_submit', 'fsn_handle_confirm' );
function fsn_render_confirm_page() {
	$email = isset( $_GET['email'] ) ? sanitize_email( wp_unslash( $_GET['email'] ) ) : '';
	$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
	$unsubscribe = isset( $_GET['unsubscribe'] ) ? sanitize_text_field( wp_unslash( $_GET['unsubscribe'] ) ) : '';
	if ( ! is_email( $email ) || ! preg_match( '/^[a-f0-9]{64}$/', $token ) || ! preg_match( '/^[a-f0-9]{64}$/', $unsubscribe ) ) {
		wp_die( esc_html__( 'This confirmation link is invalid.', 'free-surge-newsletter' ), esc_html__( 'Newsletter confirmation', 'free-surge-newsletter' ), array( 'response' => 400 ) );
	}

	$form  = '<p>' . esc_html__( 'Confirm that you want to receive the newsletter.', 'free-surge-newsletter' ) . '</p>';
	$form .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	$form .= '<input type="hidden" name="action" value="fsn_confirm_submit">';
	$form .= '<input type="hidden" name="email" value="' . esc_attr( $email ) . '">';
	$form .= '<input type="hidden" name="token" value="' . esc_attr( $token ) . '">';
	$form .= '<input type="hidden" name="unsubscribe" value="' . esc_attr( $unsubscribe ) . '">';
	$form .= wp_nonce_field( 'fsn_confirm', 'fsn_nonce', true, false );
	$form .= '<button type="submit">' . esc_html__( 'Confirm subscription', 'free-surge-newsletter' ) . '</button></form>';
	wp_die( $form, esc_html__( 'Newsletter confirmation', 'free-surge-newsletter' ), array( 'response' => 200 ) );
}

function fsn_handle_confirm() {
	if ( 'POST' !== $_SERVER['REQUEST_METHOD'] || ! isset( $_POST['fsn_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['fsn_nonce'] ) ), 'fsn_confirm' ) ) {
		wp_die( esc_html__( 'This confirmation request is invalid.', 'free-surge-newsletter' ), esc_html__( 'Newsletter confirmation', 'free-surge-newsletter' ), array( 'response' => 400 ) );
	}

	$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
	$unsubscribe = isset( $_POST['unsubscribe'] ) ? sanitize_text_field( wp_unslash( $_POST['unsubscribe'] ) ) : '';
	if ( ! is_email( $email ) || ! preg_match( '/^[a-f0-9]{64}$/', $token ) || ! preg_match( '/^[a-f0-9]{64}$/', $unsubscribe ) ) {
		wp_die( esc_html__( 'This confirmation link is invalid.', 'free-surge-newsletter' ), esc_html__( 'Newsletter confirmation', 'free-surge-newsletter' ), array( 'response' => 400 ) );
	}

	global $wpdb;
	$table      = $wpdb->prefix . 'fsn_subscribers';
	$subscriber = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s LIMIT 1", $email ) );
	if ( ! $subscriber || ! hash_equals( $subscriber->confirmation_token_hash, hash( 'sha256', $token ) ) || ! hash_equals( $subscriber->unsubscribe_token_hash, hash( 'sha256', $unsubscribe ) ) || empty( $subscriber->confirmation_expires ) || strtotime( $subscriber->confirmation_expires . ' UTC' ) < time() ) {
		wp_die( esc_html__( 'This confirmation link is invalid or has expired. You can submit the signup form again.', 'free-surge-newsletter' ), esc_html__( 'Newsletter confirmation', 'free-surge-newsletter' ), array( 'response' => 400 ) );
	}

	$wpdb->update(
		$table,
		array(
			'status'                  => 'subscribed',
			'confirmation_token_hash' => '',
			'confirmation_expires'    => null,
			'confirmed_at'            => current_time( 'mysql', true ),
			'updated_at'              => current_time( 'mysql', true ),
		),
		array( 'id' => (int) $subscriber->id )
	);

	$unsubscribe_url = add_query_arg(
		array(
			'action' => 'fsn_unsubscribe',
			'token'  => $unsubscribe,
		),
		admin_url( 'admin-post.php' )
	);
	$subject = sprintf( __( 'You are subscribed to %s', 'free-surge-newsletter' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
	$message = sprintf( __( "Your subscription is confirmed. You will receive newsletter updates from this site.\n\nTo unsubscribe at any time, open this link:\n%s", 'free-surge-newsletter' ), esc_url_raw( $unsubscribe_url ) );
	wp_mail( $email, $subject, $message );

	wp_die(
		'<p>' . esc_html__( 'Your subscription is confirmed. Thank you!', 'free-surge-newsletter' ) . '</p><p><a href="' . esc_url( $unsubscribe_url ) . '">' . esc_html__( 'Unsubscribe from the newsletter', 'free-surge-newsletter' ) . '</a></p>',
		esc_html__( 'Newsletter confirmation', 'free-surge-newsletter' ),
		array( 'response' => 200 )
	);
}

add_action( 'admin_post_nopriv_fsn_unsubscribe', 'fsn_render_unsubscribe_page' );
add_action( 'admin_post_fsn_unsubscribe', 'fsn_render_unsubscribe_page' );
add_action( 'admin_post_nopriv_fsn_unsubscribe_submit', 'fsn_handle_unsubscribe' );
add_action( 'admin_post_fsn_unsubscribe_submit', 'fsn_handle_unsubscribe' );
function fsn_render_unsubscribe_page() {
	$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
	if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
		wp_die( esc_html__( 'This unsubscribe link is invalid.', 'free-surge-newsletter' ), esc_html__( 'Newsletter unsubscribe', 'free-surge-newsletter' ), array( 'response' => 400 ) );
	}

	$form  = '<p>' . esc_html__( 'Click below to unsubscribe from the newsletter.', 'free-surge-newsletter' ) . '</p>';
	$form .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	$form .= '<input type="hidden" name="action" value="fsn_unsubscribe_submit">';
	$form .= '<input type="hidden" name="token" value="' . esc_attr( $token ) . '">';
	$form .= wp_nonce_field( 'fsn_unsubscribe', 'fsn_nonce', true, false );
	$form .= '<button type="submit">' . esc_html__( 'Unsubscribe', 'free-surge-newsletter' ) . '</button></form>';
	wp_die( $form, esc_html__( 'Newsletter unsubscribe', 'free-surge-newsletter' ), array( 'response' => 200 ) );
}

function fsn_handle_unsubscribe() {
	if ( 'POST' !== $_SERVER['REQUEST_METHOD'] || ! isset( $_POST['fsn_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['fsn_nonce'] ) ), 'fsn_unsubscribe' ) ) {
		wp_die( esc_html__( 'This unsubscribe request is invalid.', 'free-surge-newsletter' ), esc_html__( 'Newsletter unsubscribe', 'free-surge-newsletter' ), array( 'response' => 400 ) );
	}

	$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
	if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
		wp_die( esc_html__( 'This unsubscribe link is invalid.', 'free-surge-newsletter' ), esc_html__( 'Newsletter unsubscribe', 'free-surge-newsletter' ), array( 'response' => 400 ) );
	}

	global $wpdb;
	$table  = $wpdb->prefix . 'fsn_subscribers';
	$hash   = hash( 'sha256', $token );
	$result = $wpdb->update(
		$table,
		array(
			'status'     => 'unsubscribed',
			'updated_at' => current_time( 'mysql', true ),
		),
		array( 'unsubscribe_token_hash' => $hash )
	);
	if ( false === $result ) {
		wp_die( esc_html__( 'We could not process this request. Please try again later.', 'free-surge-newsletter' ), esc_html__( 'Newsletter unsubscribe', 'free-surge-newsletter' ), array( 'response' => 500 ) );
	}

	fsn_redirect_with_notice( 'unsubscribed' );
}

function fsn_redirect_with_notice( $notice ) {
	$url = wp_get_referer();
	if ( ! $url ) {
		$url = home_url( '/' );
	}
	$url = remove_query_arg( 'fsn_notice', $url );
	wp_safe_redirect( add_query_arg( 'fsn_notice', sanitize_key( $notice ), $url ) );
	exit;
}

add_action( 'admin_menu', 'fsn_register_admin_page' );
function fsn_register_admin_page() {
	add_menu_page(
		__( 'Newsletter subscribers', 'free-surge-newsletter' ),
		__( 'Newsletter', 'free-surge-newsletter' ),
		'manage_options',
		'fsn-subscribers',
		'fsn_render_admin_page',
		'dashicons-email-alt2'
	);
}

function fsn_render_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	global $wpdb;
	$table   = $wpdb->prefix . 'fsn_subscribers';
	$allowed = array( 'pending', 'subscribed', 'unsubscribed', 'bounced' );
	$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
	$where   = in_array( $status, $allowed, true ) ? $wpdb->prepare( 'WHERE status = %s', $status ) : '';
	$rows    = $wpdb->get_results( "SELECT id, email, first_name, status, consented_at, confirmed_at, created_at FROM {$table} {$where} ORDER BY created_at DESC LIMIT 100" );
	$counts  = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", OBJECT_K );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Newsletter subscribers', 'free-surge-newsletter' ); ?></h1>
		<p><?php esc_html_e( 'Signup records are stored here. Only confirmed subscribers have subscribed status. Campaign sending is not part of this version.', 'free-surge-newsletter' ); ?></p>
		<p>
			<?php foreach ( $allowed as $filter ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'status', $filter, admin_url( 'admin.php?page=fsn-subscribers' ) ) ); ?>"><?php echo esc_html( ucfirst( $filter ) ); ?> (<?php echo isset( $counts[ $filter ] ) ? esc_html( number_format_i18n( (int) $counts[ $filter ]->total ) ) : '0'; ?>)</a><?php echo ' ';
			endforeach; ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=fsn-subscribers' ) ); ?>"><?php esc_html_e( 'All', 'free-surge-newsletter' ); ?></a>
		</p>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Email', 'free-surge-newsletter' ); ?></th>
				<th><?php esc_html_e( 'First name', 'free-surge-newsletter' ); ?></th>
				<th><?php esc_html_e( 'Status', 'free-surge-newsletter' ); ?></th>
				<th><?php esc_html_e( 'Consent recorded', 'free-surge-newsletter' ); ?></th>
				<th><?php esc_html_e( 'Confirmed', 'free-surge-newsletter' ); ?></th>
				<th><?php esc_html_e( 'Action', 'free-surge-newsletter' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( $rows ) : foreach ( $rows as $row ) : ?>
				<tr>
					<td><?php echo esc_html( $row->email ); ?></td>
					<td><?php echo esc_html( $row->first_name ); ?></td>
					<td><?php echo esc_html( $row->status ); ?></td>
					<td><?php echo $row->consented_at ? esc_html( get_date_from_gmt( $row->consented_at, 'Y-m-d H:i' ) ) : '—'; ?></td>
					<td><?php echo $row->confirmed_at ? esc_html( get_date_from_gmt( $row->confirmed_at, 'Y-m-d H:i' ) ) : '—'; ?></td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.confirm('<?php echo esc_js( __( 'Permanently delete this subscriber record?', 'free-surge-newsletter' ) ); ?>');">
							<input type="hidden" name="action" value="fsn_delete_subscriber">
							<input type="hidden" name="subscriber_id" value="<?php echo esc_attr( (string) $row->id ); ?>">
							<?php wp_nonce_field( 'fsn_delete_subscriber_' . (int) $row->id ); ?>
							<button type="submit" class="button-link-delete"><?php esc_html_e( 'Delete', 'free-surge-newsletter' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; else : ?>
				<tr><td colspan="6"><?php esc_html_e( 'No subscribers found.', 'free-surge-newsletter' ); ?></td></tr>
			<?php endif; ?>
			</tbody>
		</table>
		<p><?php esc_html_e( 'The list is limited to the 100 most recent records. Search and CSV export can be added in a later iteration.', 'free-surge-newsletter' ); ?></p>
	</div>
	<?php
}

add_action( 'admin_post_fsn_delete_subscriber', 'fsn_handle_delete_subscriber' );
function fsn_handle_delete_subscriber() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'free-surge-newsletter' ), '', array( 'response' => 403 ) );
	}

	$id = isset( $_POST['subscriber_id'] ) ? absint( $_POST['subscriber_id'] ) : 0;
	if ( ! $id || ! check_admin_referer( 'fsn_delete_subscriber_' . $id ) ) {
		wp_die( esc_html__( 'This request is invalid.', 'free-surge-newsletter' ), '', array( 'response' => 400 ) );
	}

	global $wpdb;
	$wpdb->delete( $wpdb->prefix . 'fsn_subscribers', array( 'id' => $id ), array( '%d' ) );
	wp_safe_redirect( admin_url( 'admin.php?page=fsn-subscribers&fsn_deleted=1' ) );
	exit;
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'fsn_plugin_action_links' );
function fsn_plugin_action_links( $links ) {
	$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=fsn-subscribers' ) ) . '">' . esc_html__( 'Subscribers', 'free-surge-newsletter' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
}

add_filter( 'wp_privacy_personal_data_exporters', 'fsn_register_privacy_exporter' );
function fsn_register_privacy_exporter( $exporters ) {
	$exporters['free-surge-newsletter'] = array(
		'exporter_friendly_name' => __( 'Free Surge Newsletter subscribers', 'free-surge-newsletter' ),
		'callback'                => 'fsn_privacy_exporter',
	);
	return $exporters;
}

function fsn_privacy_exporter( $email_address, $page = 1 ) {
	global $wpdb;
	$table      = $wpdb->prefix . 'fsn_subscribers';
	$subscriber = $wpdb->get_row( $wpdb->prepare( "SELECT email, first_name, status, consented_at, confirmed_at, consent_source, consent_version, created_at, updated_at FROM {$table} WHERE email = %s LIMIT 1", sanitize_email( $email_address ) ) );
	$data       = array();

	if ( $subscriber ) {
		$items = array(
			'Email address'       => $subscriber->email,
			'First name'          => $subscriber->first_name,
			'Subscription status' => $subscriber->status,
			'Consent recorded'    => $subscriber->consented_at,
			'Confirmation date'   => $subscriber->confirmed_at,
			'Consent source'      => $subscriber->consent_source,
			'Consent wording version' => $subscriber->consent_version,
			'Record created'      => $subscriber->created_at,
			'Record updated'      => $subscriber->updated_at,
		);
		$export_data = array();
		foreach ( $items as $name => $value ) {
			$export_data[] = array( 'name' => $name, 'value' => (string) $value );
		}
		$data[] = array(
			'group_id'    => 'fsn-subscriber',
			'group_label' => __( 'Newsletter subscription', 'free-surge-newsletter' ),
			'item_id'     => 'fsn-' . md5( strtolower( $subscriber->email ) ),
			'data'        => $export_data,
		);
	}

	return array(
		'data' => $data,
		'done' => true,
	);
}

add_filter( 'wp_privacy_personal_data_erasers', 'fsn_register_privacy_eraser' );
function fsn_register_privacy_eraser( $erasers ) {
	$erasers['free-surge-newsletter'] = array(
		'eraser_friendly_name' => __( 'Free Surge Newsletter subscribers', 'free-surge-newsletter' ),
		'callback'               => 'fsn_privacy_eraser',
	);
	return $erasers;
}

function fsn_privacy_eraser( $email_address, $page = 1 ) {
	global $wpdb;
	$removed = $wpdb->delete( $wpdb->prefix . 'fsn_subscribers', array( 'email' => sanitize_email( $email_address ) ), array( '%s' ) );
	return array(
		'items_removed'  => false !== $removed && $removed > 0,
		'items_retained' => false,
		'messages'       => array(),
		'done'           => true,
	);
}

add_action( 'admin_init', 'fsn_add_privacy_policy_content' );
function fsn_add_privacy_policy_content() {
	if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
		$content = '<p>' . esc_html__( 'This site stores the email address and optional first name submitted for the newsletter, subscription status, and the dates and source of consent and confirmation. Confirmation and unsubscribe tokens are stored as hashes. A short-lived, site-salted rate-limit key derived from the request IP may be held in a transient to reduce signup abuse. Pending subscriptions expire after 48 hours. Newsletter delivery is not included in this plugin version.', 'free-surge-newsletter' ) . '</p>';
		wp_add_privacy_policy_content( __( 'Free Surge Newsletter', 'free-surge-newsletter' ), wp_kses_post( $content ) );
	}
}

