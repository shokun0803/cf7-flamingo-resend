<?php
/**
 * Plugin Name: CF7 Flamingo Resend
 * Description: Resend Contact Form 7 notification emails from Flamingo inbound messages after spam is cleared.
 * Version: 0.1.1
 * Author: GitHub Copilot, shokun0803
 * Text Domain: cf7-flamingo-resend
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CF7_Flamingo_Resend {
	const ACTION = 'cf7fr_resend_flamingo_message';
	const NONCE_ACTION = 'cf7fr_resend_message';
	private static $active_resend_context = null;

	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'load_textdomain' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_resend_request' ) );
		add_action( 'load-flamingo_page_flamingo_inbound', array( __CLASS__, 'register_edit_meta_box' ) );
		add_filter( 'manage_flamingo_inbound_posts_columns', array( __CLASS__, 'add_resend_column' ) );
		add_filter( 'wpcf7_special_mail_tags', array( __CLASS__, 'filter_special_mail_tags' ), 10, 4 );
		add_action( 'manage_flamingo_inbound_posts_custom_column', array( __CLASS__, 'render_resend_column' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'render_admin_notice' ) );
	}

	public static function load_textdomain() {
		load_plugin_textdomain(
			'cf7-flamingo-resend',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);
	}

	public static function register_edit_meta_box() {
		if ( 'edit' !== self::current_flamingo_action() ) {
			return;
		}

		$post_id = isset( $_REQUEST['post'] ) ? absint( $_REQUEST['post'] ) : 0;

		if ( ! $post_id || ! self::is_resendable_message( $post_id ) ) {
			return;
		}

		add_meta_box(
			'cf7fr-resend-message',
			__( 'Resend', 'cf7-flamingo-resend' ),
			array( __CLASS__, 'render_edit_meta_box' ),
			null,
			'side',
			'default'
		);
	}

	public static function render_edit_meta_box( $message ) {
		$post_id = method_exists( $message, 'id' ) ? (int) $message->id() : 0;

		if ( ! $post_id ) {
			return;
		}

		$link = self::get_resend_url( $post_id, self::get_current_url() );

		echo '<p>';
		echo esc_html__( 'Resend the Contact Form 7 mail templates using the data saved in this Flamingo message.', 'cf7-flamingo-resend' );
		echo '</p>';
		echo '<p>';
		echo '<a class="button button-primary" href="' . esc_url( $link ) . '">';
		echo esc_html__( 'Resend Now', 'cf7-flamingo-resend' );
		echo '</a>';
		echo '</p>';
	}

	public static function add_resend_column( $columns ) {
		$updated = array();

		foreach ( $columns as $key => $label ) {
			$updated[ $key ] = $label;

			if ( 'date' === $key ) {
				$updated['cf7fr_resend'] = __( 'Resend', 'cf7-flamingo-resend' );
			}
		}

		if ( ! isset( $updated['cf7fr_resend'] ) ) {
			$updated['cf7fr_resend'] = __( 'Resend', 'cf7-flamingo-resend' );
		}

		return $updated;
	}

	public static function render_resend_column( $column_name, $post_id ) {
		if ( 'cf7fr_resend' !== $column_name ) {
			return;
		}

		$post_id = absint( $post_id );

		if ( ! self::is_resendable_message( $post_id ) ) {
			echo '&#8212;';
			return;
		}

		$link = self::get_resend_url( $post_id, self::get_current_url() );

		echo '<a class="button button-small" href="' . esc_url( $link ) . '">';
		echo esc_html__( 'Resend', 'cf7-flamingo-resend' );
		echo '</a>';
	}

	public static function handle_resend_request() {
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		$redirect_to = '';

		if ( isset( $_GET['redirect_to'] ) ) {
			$redirect_to = base64_decode( wp_unslash( $_GET['redirect_to'] ), true );
			$redirect_to = is_string( $redirect_to ) ? $redirect_to : '';
		}

		if ( ! $redirect_to ) {
			$redirect_to = admin_url( 'admin.php?page=flamingo_inbound' );
		}

		if ( ! $post_id ) {
			self::redirect_with_notice( $redirect_to, 'invalid_message' );
		}

		check_admin_referer( self::NONCE_ACTION . '_' . $post_id );

		if ( ! current_user_can( 'flamingo_edit_inbound_message', $post_id ) ) {
			wp_die( esc_html__( 'You are not allowed to resend this Flamingo message.', 'cf7-flamingo-resend' ) );
		}

		$result = self::resend_message( $post_id );

		if ( is_wp_error( $result ) ) {
			self::redirect_with_notice( $redirect_to, $result->get_error_code() );
		}

		self::redirect_with_notice(
			$redirect_to,
			'success',
			array(
				'cf7fr_sent' => $result['sent'],
				'cf7fr_total' => $result['total'],
			)
		);
	}

	private static function resend_message( $post_id ) {
		if ( ! class_exists( 'Flamingo_Inbound_Message' ) || ! class_exists( 'WPCF7_Mail' ) ) {
			return new WP_Error( 'missing_dependency' );
		}

		$message = new Flamingo_Inbound_Message( $post_id );

		if ( empty( $message ) || empty( $message->id() ) ) {
			return new WP_Error( 'invalid_message' );
		}

		if ( ! empty( $message->spam ) ) {
			return new WP_Error( 'message_is_spam' );
		}

		$contact_form = self::resolve_contact_form( $message );

		if ( ! $contact_form ) {
			return new WP_Error( 'contact_form_not_found' );
		}

		$submission = self::build_mock_submission( $contact_form, $message );

		if ( ! $submission ) {
			return new WP_Error( 'submission_setup_failed' );
		}

		$previous_submission = self::get_submission_instance();
		$previous_contact_form = function_exists( 'wpcf7_get_current_contact_form' )
			? wpcf7_get_current_contact_form()
			: null;
		$previous_resend_context = self::$active_resend_context;
		$previous_post_data = isset( $_POST ) && is_array( $_POST ) ? $_POST : array();
		$raw_post_data = self::build_raw_post_data( $contact_form, self::get_submission_posted_data( $submission ) );

		self::set_submission_instance( $submission );
		self::$active_resend_context = array(
			'contact_form' => $contact_form,
			'posted_data' => self::get_submission_posted_data( $submission ),
		);
		$_POST = $raw_post_data;
		wpcf7_contact_form( $contact_form );

		$templates = array(
			'mail' => $contact_form->prop( 'mail' ),
		);

		$sent = 0;
		$total = 0;

		try {
			foreach ( $templates as $template_name => $template ) {
				if ( empty( $template ) || ! is_array( $template ) ) {
					continue;
				}

				$total++;

				if ( WPCF7_Mail::send( $template, $template_name ) ) {
					$sent++;
				}
			}
		} finally {
			self::set_submission_instance( $previous_submission );
			self::$active_resend_context = $previous_resend_context;
			$_POST = $previous_post_data;

			if ( $previous_contact_form ) {
				wpcf7_contact_form( $previous_contact_form );
			}
		}

		if ( ! $total ) {
			return new WP_Error( 'mail_template_not_found' );
		}

		if ( ! $sent ) {
			return new WP_Error( 'mail_send_failed' );
		}

		return array(
			'sent' => $sent,
			'total' => $total,
		);
	}

	private static function build_mock_submission( $contact_form, $message ) {
		if ( ! class_exists( 'ReflectionClass' ) || ! class_exists( 'WPCF7_Submission' ) ) {
			return null;
		}

		try {
			$reflection = new ReflectionClass( 'WPCF7_Submission' );
			$submission = $reflection->newInstanceWithoutConstructor();

			$posted_data = self::normalize_posted_data( $message->fields );
			$meta = self::build_submission_meta( $contact_form, $message );
			$status = self::normalize_submission_status( $message->submission_status );

			self::set_object_property( $reflection, $submission, 'contact_form', $contact_form );
			self::set_object_property( $reflection, $submission, 'status', $status );
			self::set_object_property( $reflection, $submission, 'posted_data', $posted_data );
			self::set_object_property( $reflection, $submission, 'posted_data_hash', self::extract_hash( $message ) );
			self::set_object_property( $reflection, $submission, 'uploaded_files', array() );
			self::set_object_property( $reflection, $submission, 'extra_attachments', array() );
			self::set_object_property( $reflection, $submission, 'skip_mail', false );
			self::set_object_property( $reflection, $submission, 'response', '' );
			self::set_object_property( $reflection, $submission, 'invalid_fields', array() );
			self::set_object_property( $reflection, $submission, 'meta', $meta );
			self::set_object_property( $reflection, $submission, 'consent', is_array( $message->consent ) ? $message->consent : array() );
			self::set_object_property( $reflection, $submission, 'spam_log', array() );
			self::set_object_property( $reflection, $submission, 'result_props', array() );

			return $submission;
		} catch ( ReflectionException $exception ) {
			return null;
		}
	}

	private static function build_submission_meta( $contact_form, $message ) {
		$meta = array();
		$saved_meta = is_array( $message->meta ) ? $message->meta : array();

		foreach ( $saved_meta as $key => $value ) {
			$meta[ $key ] = is_scalar( $value ) ? (string) $value : $value;
		}

		$post = get_post( $message->id() );

		if ( $post instanceof WP_Post ) {
			$meta['timestamp'] = get_post_timestamp( $post );
		}

		$meta['remote_ip'] = $meta['remote_ip'] ?? '';
		$meta['user_agent'] = $meta['user_agent'] ?? '';
		$meta['url'] = $meta['url'] ?? '';
		$meta['unit_tag'] = $meta['unit_tag'] ?? WPCF7_ContactForm::get_instance( $contact_form )->unit_tag();
		$meta['container_post_id'] = isset( $meta['post_id'] ) ? absint( $meta['post_id'] ) : 0;

		return $meta;
	}

	private static function normalize_posted_data( $fields ) {
		$normalized = array();

		foreach ( (array) $fields as $key => $value ) {
			$normalized[ strtr( (string) $key, '.', '_' ) ] = $value;
		}

		return $normalized;
	}

	private static function normalize_submission_status( $status ) {
		$status = (string) $status;

		if ( in_array( $status, array( 'spam', 'mail_failed', 'mail_sent' ), true ) ) {
			return $status;
		}

		return 'mail_sent';
	}

	public static function filter_special_mail_tags( $output, $name, $html, $mail_tag ) {
		unset( $html, $mail_tag );

		if ( 0 !== strpos( (string) $name, '_raw_' ) ) {
			return $output;
		}

		if ( empty( self::$active_resend_context['contact_form'] ) ) {
			return $output;
		}

		$field_name = substr( (string) $name, 5 );

		if ( '' === $field_name ) {
			return $output;
		}

		$raw_value = self::resolve_raw_mail_tag_value(
			self::$active_resend_context['contact_form'],
			$field_name,
			self::$active_resend_context['posted_data'] ?? array()
		);

		if ( null === $raw_value ) {
			return $output;
		}

		return $raw_value;
	}

	private static function extract_hash( $message ) {
		if ( ! empty( $message->meta['posted_data_hash'] ) ) {
			return (string) $message->meta['posted_data_hash'];
		}

		$post_id = method_exists( $message, 'id' ) ? $message->id() : 0;

		if ( $post_id ) {
			return (string) get_post_meta( $post_id, '_hash', true );
		}

		return null;
	}

	private static function resolve_contact_form( $message ) {
		if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
			return null;
		}

		$channel_slug = isset( $message->channel ) ? (string) $message->channel : '';
		$term = $channel_slug
			? get_term_by( 'slug', $channel_slug, Flamingo_Inbound_Message::channel_taxonomy )
			: null;

		if ( $channel_slug ) {
			$contact_forms = WPCF7_ContactForm::find(
				array(
					'name' => $channel_slug,
					'posts_per_page' => 1,
				)
			);

			if ( ! empty( $contact_forms ) ) {
				return wpcf7_contact_form( $contact_forms[0] );
			}
		}

		if ( $term && ! is_wp_error( $term ) && function_exists( 'wpcf7_get_contact_form_by_title' ) ) {
			$contact_form = wpcf7_get_contact_form_by_title( $term->name );

			if ( $contact_form ) {
				return $contact_form;
			}
		}

		return null;
	}

	private static function is_resendable_message( $post_id ) {
		if ( ! class_exists( 'Flamingo_Inbound_Message' ) || ! class_exists( 'WPCF7_ContactForm' ) ) {
			return false;
		}

		$message = new Flamingo_Inbound_Message( $post_id );

		if ( empty( $message ) || empty( $message->id() ) || ! empty( $message->spam ) ) {
			return false;
		}

		return (bool) self::resolve_contact_form( $message );
	}

	private static function get_resend_url( $post_id, $redirect_to ) {
		$url = add_query_arg(
			array(
				'action' => self::ACTION,
				'post_id' => absint( $post_id ),
				'redirect_to' => base64_encode( $redirect_to ),
			),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, self::NONCE_ACTION . '_' . absint( $post_id ) );
	}

	private static function redirect_with_notice( $redirect_to, $notice, $extra_args = array() ) {
		$redirect_to = add_query_arg(
			array_merge(
				array(
					'cf7fr_notice' => $notice,
				),
				$extra_args
			),
			$redirect_to
		);

		wp_safe_redirect( $redirect_to );
		exit;
	}

	public static function render_admin_notice() {
		if ( ! is_admin() || empty( $_GET['cf7fr_notice'] ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'flamingo_page_flamingo_inbound' !== $screen->id ) {
			return;
		}

		$notice = sanitize_key( wp_unslash( $_GET['cf7fr_notice'] ) );
		$message = '';
		$type = 'notice-error';

		switch ( $notice ) {
			case 'success':
				$sent = isset( $_GET['cf7fr_sent'] ) ? absint( $_GET['cf7fr_sent'] ) : 0;
				$total = isset( $_GET['cf7fr_total'] ) ? absint( $_GET['cf7fr_total'] ) : 0;
				$message = sprintf(
					/* translators: 1: sent mail count, 2: total mail template count. */
					__( 'Flamingo message resent. %1$d of %2$d mail template(s) were sent.', 'cf7-flamingo-resend' ),
					$sent,
					$total
				);
				$type = 'notice-success';
				break;
			case 'missing_dependency':
				$message = __( 'Contact Form 7 or Flamingo is not available.', 'cf7-flamingo-resend' );
				break;
			case 'invalid_message':
				$message = __( 'The selected Flamingo message could not be found.', 'cf7-flamingo-resend' );
				break;
			case 'message_is_spam':
				$message = __( 'Clear the spam status before resending this message.', 'cf7-flamingo-resend' );
				break;
			case 'contact_form_not_found':
				$message = __( 'The original Contact Form 7 form could not be resolved from this Flamingo message.', 'cf7-flamingo-resend' );
				break;
			case 'submission_setup_failed':
				$message = __( 'The resend context could not be prepared.', 'cf7-flamingo-resend' );
				break;
			case 'mail_template_not_found':
				$message = __( 'No mail template was available to resend.', 'cf7-flamingo-resend' );
				break;
			case 'mail_send_failed':
				$message = __( 'The resend was attempted, but no email could be sent.', 'cf7-flamingo-resend' );
				break;
		}

		if ( ! $message ) {
			return;
		}

		echo '<div class="notice ' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	private static function current_flamingo_action() {
		foreach ( array( 'action', 'action2' ) as $key ) {
			if ( isset( $_REQUEST[ $key ] ) && '-1' !== $_REQUEST[ $key ] ) {
				return sanitize_key( wp_unslash( $_REQUEST[ $key ] ) );
			}
		}

		return false;
	}

	private static function get_current_url() {
		$current_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		return $current_uri ? home_url( $current_uri ) : admin_url( 'admin.php?page=flamingo_inbound' );
	}

	private static function get_submission_instance() {
		if ( ! class_exists( 'WPCF7_Submission' ) ) {
			return null;
		}

		return WPCF7_Submission::get_instance();
	}

	private static function get_submission_posted_data( $submission ) {
		if ( ! is_object( $submission ) || ! method_exists( $submission, 'get_posted_data' ) ) {
			return array();
		}

		$posted_data = $submission->get_posted_data();

		return is_array( $posted_data ) ? $posted_data : array();
	}

	private static function build_raw_post_data( $contact_form, $posted_data ) {
		if ( ! is_object( $contact_form ) || ! method_exists( $contact_form, 'scan_form_tags' ) ) {
			return is_array( $posted_data ) ? $posted_data : array();
		}

		$raw_post_data = array();
		$form_tags = $contact_form->scan_form_tags();

		foreach ( (array) $posted_data as $field_name => $field_value ) {
			$raw_value = self::resolve_raw_mail_tag_value( $contact_form, (string) $field_name, $posted_data );

			if ( null === $raw_value ) {
				$raw_post_data[ $field_name ] = $field_value;
				continue;
			}

			foreach ( $form_tags as $form_tag ) {
				if ( empty( $form_tag->name ) || $field_name !== $form_tag->name ) {
					continue;
				}

				$raw_post_data[ $field_name ] = is_array( $field_value )
					? self::resolve_raw_post_values( $form_tag->pipes ?? null, $field_value )
					: $raw_value;
				continue 2;
			}

			$raw_post_data[ $field_name ] = $raw_value;
		}

		return $raw_post_data;
	}

	private static function resolve_raw_mail_tag_value( $contact_form, $field_name, $posted_data ) {
		if ( ! is_object( $contact_form ) || ! method_exists( $contact_form, 'scan_form_tags' ) ) {
			return null;
		}

		if ( ! is_array( $posted_data ) || ! array_key_exists( $field_name, $posted_data ) ) {
			return null;
		}

		$posted_values = is_array( $posted_data[ $field_name ] )
			? $posted_data[ $field_name ]
			: array( $posted_data[ $field_name ] );
		$form_tags = $contact_form->scan_form_tags();
		$resolved_values = array();

		foreach ( $posted_values as $posted_value ) {
			$resolved_value = (string) $posted_value;

			foreach ( $form_tags as $form_tag ) {
				if ( empty( $form_tag->name ) || $field_name !== $form_tag->name ) {
					continue;
				}

				$resolved_pipe = self::resolve_pipe_label( $form_tag->pipes ?? null, $resolved_value );

				if ( null !== $resolved_pipe ) {
					$resolved_value = $resolved_pipe;
					break;
				}
			}

			$resolved_values[] = $resolved_value;
		}

		return implode( ', ', $resolved_values );
	}

	private static function resolve_pipe_label( $pipes, $submitted_value ) {
		if ( ! is_object( $pipes ) || ! method_exists( $pipes, 'to_array' ) ) {
			return null;
		}

		foreach ( $pipes->to_array() as $pipe ) {
			if ( ! is_array( $pipe ) || 2 > count( $pipe ) ) {
				continue;
			}

			if ( (string) $pipe[1] === (string) $submitted_value ) {
				return (string) $pipe[0];
			}
		}

		return null;
	}

	private static function resolve_raw_post_values( $pipes, $submitted_values ) {
		$submitted_values = is_array( $submitted_values ) ? $submitted_values : array( $submitted_values );
		$resolved_values = array();

		foreach ( $submitted_values as $submitted_value ) {
			$resolved_values[] = self::resolve_pipe_label( $pipes, $submitted_value ) ?? $submitted_value;
		}

		return $resolved_values;
	}

	private static function set_submission_instance( $submission ) {
		if ( ! class_exists( 'WPCF7_Submission' ) ) {
			return;
		}

		try {
			$reflection = new ReflectionClass( 'WPCF7_Submission' );
			$property = $reflection->getProperty( 'instance' );
			$property->setAccessible( true );
			$property->setValue( null, $submission );
		} catch ( ReflectionException $exception ) {
			return;
		}
	}

	private static function set_object_property( ReflectionClass $reflection, $object, $property_name, $value ) {
		$property = $reflection->getProperty( $property_name );
		$property->setAccessible( true );
		$property->setValue( $object, $value );
	}
}

CF7_Flamingo_Resend::init();