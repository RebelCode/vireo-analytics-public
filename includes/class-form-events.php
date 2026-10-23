<?php
/**
 * Form submissions that actually went through.
 *
 * The tracker's `form_submit` auto-event listens for the browser's submit
 * event, which fires before validation, so a form that bounced on a required
 * field counts the same as one that sent. Each form plugin has a server-side
 * hook that runs only once the submission has been accepted; these listen to
 * that and record a `form_success` event carrying the form's id and title,
 * nothing the visitor typed.
 *
 * Only the plugins that are active get a listener. Breakdance has no hook
 * for this, so it gets an action in its own "Actions after submission" list
 * instead, the same way Independent Analytics does it.
 */

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

final class Form_Events {

	public const EVENT = 'form_success';

	/**
	 * Which plugins are present, as slug => name. Checked on plugins_loaded,
	 * when every plugin's main file has run.
	 *
	 * @return array<string,string>
	 */
	public static function active(): array {
		$all = [
			'cf7'        => [ 'Contact Form 7', defined( 'WPCF7_VERSION' ) ],
			'wpforms'    => [ 'WPForms', defined( 'WPFORMS_VERSION' ) ],
			'gravity'    => [ 'Gravity Forms', class_exists( '\GFForms' ) ],
			'fluent'     => [ 'Fluent Forms', defined( 'FLUENTFORM' ) ],
			'elementor'  => [ 'Elementor Pro', defined( 'ELEMENTOR_PRO_VERSION' ) ],
			'breakdance' => [ 'Breakdance', defined( '__BREAKDANCE_VERSION' ) ],
		];

		$out = [];
		foreach ( $all as $slug => [ $name, $present ] ) {
			if ( $present ) {
				$out[ $slug ] = $name;
			}
		}
		return $out;
	}

	/**
	 * How each plugin marks its forms in the page, so the tracker's generic
	 * submit listener can leave those forms to the server-side hook.
	 *
	 * @return array<string,string>
	 */
	public static function selectors(): array {
		return [
			'cf7'        => '.wpcf7-form',
			'wpforms'    => '.wpforms-form',
			'gravity'    => 'form[id^="gform_"]',
			'fluent'     => '.frm-fluent-form',
			'elementor'  => '.elementor-form',
			'breakdance' => '.breakdance-form',
		];
	}

	/** Is this on at all? Default yes whenever one of the plugins is active. */
	public static function enabled(): bool {
		return [] !== self::active() && (bool) apply_filters( 'vireo_track_form_success', true );
	}

	public static function boot(): void {
		if ( ! self::enabled() ) {
			return;
		}
		$active = self::active();

		if ( isset( $active['cf7'] ) ) {
			add_action(
				'wpcf7_mail_sent',
				static function ( $form ): void {
					if ( is_object( $form ) && method_exists( $form, 'id' ) && method_exists( $form, 'title' ) ) {
						self::record( (int) $form->id(), (string) $form->title() );
					}
				}
			);
		}

		if ( isset( $active['wpforms'] ) ) {
			add_action(
				'wpforms_process_complete',
				static function ( $fields, $entry, $form_data ): void {
					$form_data = (array) $form_data;
					self::record( (int) ( $form_data['id'] ?? 0 ), (string) ( $form_data['settings']['form_title'] ?? '' ) );
				},
				10,
				3
			);
		}

		if ( isset( $active['gravity'] ) ) {
			add_action(
				'gform_after_submission',
				static function ( $entry, $form ): void {
					$form = (array) $form;
					self::record( (int) ( $form['id'] ?? 0 ), (string) ( $form['title'] ?? '' ) );
				},
				10,
				2
			);
		}

		if ( isset( $active['fluent'] ) ) {
			add_action(
				'fluentform/submission_inserted',
				static function ( $entry_id, $form_data, $form ): void {
					self::record( (int) ( $form->id ?? 0 ), (string) ( $form->title ?? '' ) );
				},
				10,
				3
			);
		}

		if ( isset( $active['elementor'] ) ) {
			add_action(
				'elementor_pro/forms/new_record',
				static function ( $record ): void {
					if ( ! is_object( $record ) || ! method_exists( $record, 'get_form_settings' ) ) {
						return;
					}
					// Elementor ids are short hex strings, not integers.
					self::record( (string) $record->get_form_settings( 'id' ), (string) $record->get_form_settings( 'form_name' ) );
				}
			);
		}

		if ( isset( $active['breakdance'] ) ) {
			add_action( 'init', [ self::class, 'register_breakdance_action' ] );
		}
	}

	/**
	 * Breakdance runs the actions a form has ticked once the submission has
	 * passed its checks. This adds "Vireo Analytics" to that list; the site
	 * owner ticks it on each form they want counted.
	 */
	public static function register_breakdance_action(): void {
		if ( ! function_exists( '\Breakdance\Forms\Actions\registerAction' ) || ! class_exists( '\Breakdance\Forms\Actions\Action' ) ) {
			return;
		}

		$action = new class() extends \Breakdance\Forms\Actions\Action {
			public static function name() {
				return 'Vireo Analytics';
			}

			public static function slug() {
				return 'vireo_form_success';
			}

			/**
			 * @param mixed $form
			 * @param mixed $settings
			 * @param mixed $extra
			 * @return array{type:string,message:string}
			 */
			public function run( $form, $settings, $extra ) {
				$extra    = (array) $extra;
				$settings = (array) $settings;
				Form_Events::record( (string) ( $extra['formId'] ?? '' ), (string) ( $settings['form']['form_name'] ?? '' ) );
				return [
					'type'    => 'success',
					'message' => 'Submission counted',
				];
			}
		};

		\Breakdance\Forms\Actions\registerAction( $action );
	}

	/**
	 * The event value: the form's id and title, clipped to the column. Titles
	 * are the site owner's own labels, so they carry nothing personal.
	 *
	 * @param int|string $id
	 */
	public static function value( $id, string $title ): string {
		$id    = trim( (string) $id );
		$title = trim( sanitize_text_field( wp_strip_all_tags( $title ) ) );

		$value = '' === $id ? $title : '#' . $id . ( '' === $title ? '' : ' ' . $title );
		return Collector::clip( $value, 100 );
	}

	/**
	 * @param int|string $id
	 */
	public static function record( $id, string $title ): void {
		$value = self::value( $id, $title );
		if ( '' === $value || ! function_exists( 'vireo_track_event' ) ) {
			return;
		}

		// The same gate the beacon applies: a bot, an excluded address or an
		// excluded role testing the form does not count.
		$ua   = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$ip   = function_exists( 'vireo_get_client_ip' ) ? vireo_get_client_ip() : '';
		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
		if ( Bot_Filter::request_is_excluded( [ 'ua' => $ua, 'ip' => $ip, 'path' => $path ] ) ) {
			return;
		}

		vireo_track_event( self::EVENT, $value );
	}
}
