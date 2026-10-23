<?php
/**
 * Classic widget wrapper for the popular posts list, for sites still on
 * classic widget screens. Block-based widget areas use the block instead.
 */

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

final class Popular_Posts_Widget extends \WP_Widget {

	public const ID_BASE = 'vireo_popular_posts';

	public function __construct() {
		parent::__construct(
			self::ID_BASE,
			__( 'Popular posts (Vireo)', 'vireo-analytics' ),
			[
				'classname'             => 'widget_vireo_popular_posts',
				'description'           => __( 'Your most viewed posts, from Vireo Analytics.', 'vireo-analytics' ),
				'show_instance_in_rest' => true,
			]
		);
	}

	/**
	 * @param array<string,mixed> $args
	 * @param array<string,mixed> $instance
	 */
	public function widget( $args, $instance ): void {
		$html = Popular_Posts::render( Popular_Posts::normalize_atts( (array) $instance, Popular_Posts::allowed_types() ) );
		if ( '' === $html ) {
			return;
		}

		$title = apply_filters( 'widget_title', (string) ( $instance['title'] ?? '' ), $instance, $this->id_base );

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- before/after markup comes from the theme's register_sidebar(); $html is escaped in Popular_Posts::render()
		echo $args['before_widget'];
		if ( '' !== $title ) {
			echo $args['before_title'] . esc_html( $title ) . $args['after_title'];
		}
		echo $html;
		echo $args['after_widget'];
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * @param array<string,mixed> $new_instance
	 * @param array<string,mixed> $old_instance
	 * @return array<string,mixed>
	 */
	public function update( $new_instance, $old_instance ): array {
		$atts          = Popular_Posts::normalize_atts(
			[
				'number'          => $new_instance['number'] ?? 5,
				'period'          => $new_instance['period'] ?? '30',
				'post_types'      => $new_instance['post_types'] ?? [],
				'display'         => $new_instance['display'] ?? 'list',
				'show_views'      => ! empty( $new_instance['show_views'] ),
				'exclude_current' => ! empty( $new_instance['exclude_current'] ),
			],
			Popular_Posts::allowed_types()
		);
		$atts['title'] = sanitize_text_field( (string) ( $new_instance['title'] ?? '' ) );
		return $atts;
	}

	/**
	 * @param array<string,mixed> $instance
	 */
	public function form( $instance ): string {
		$title = (string) ( $instance['title'] ?? __( 'Popular posts', 'vireo-analytics' ) );
		$atts  = Popular_Posts::normalize_atts( (array) $instance, Popular_Posts::allowed_types() );

		$periods = [
			'7'   => __( 'Last 7 days', 'vireo-analytics' ),
			'30'  => __( 'Last 30 days', 'vireo-analytics' ),
			'90'  => __( 'Last 90 days', 'vireo-analytics' ),
			'all' => __( 'All time', 'vireo-analytics' ),
		];
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'vireo-analytics' ); ?></label>
			<input class="widefat" type="text" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" value="<?php echo esc_attr( $title ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'number' ) ); ?>"><?php esc_html_e( 'Number of posts:', 'vireo-analytics' ); ?></label>
			<input class="tiny-text" type="number" min="1" max="20" step="1" id="<?php echo esc_attr( $this->get_field_id( 'number' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'number' ) ); ?>" value="<?php echo esc_attr( (string) $atts['number'] ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'period' ) ); ?>"><?php esc_html_e( 'Period:', 'vireo-analytics' ); ?></label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'period' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'period' ) ); ?>">
				<?php foreach ( $periods as $value => $label ) : ?>
					<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $atts['period'], (string) $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<fieldset>
			<legend><?php esc_html_e( 'Post types:', 'vireo-analytics' ); ?></legend>
			<?php
			foreach ( Popular_Posts::allowed_types() as $type ) :
				$object = get_post_type_object( $type );
				$label  = $object ? $object->labels->name : $type;
				?>
				<label style="display:block">
					<input type="checkbox" name="<?php echo esc_attr( $this->get_field_name( 'post_types' ) ); ?>[]" value="<?php echo esc_attr( $type ); ?>" <?php checked( in_array( $type, $atts['post_types'], true ) ); ?>>
					<?php echo esc_html( $label ); ?>
				</label>
			<?php endforeach; ?>
		</fieldset>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'display' ) ); ?>"><?php esc_html_e( 'Display:', 'vireo-analytics' ); ?></label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'display' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'display' ) ); ?>">
				<option value="list" <?php selected( $atts['display'], 'list' ); ?>><?php esc_html_e( 'List', 'vireo-analytics' ); ?></option>
				<option value="thumbnails" <?php selected( $atts['display'], 'thumbnails' ); ?>><?php esc_html_e( 'List with featured images', 'vireo-analytics' ); ?></option>
			</select>
		</p>
		<p>
			<input type="checkbox" id="<?php echo esc_attr( $this->get_field_id( 'show_views' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'show_views' ) ); ?>" value="1" <?php checked( $atts['show_views'] ); ?>>
			<label for="<?php echo esc_attr( $this->get_field_id( 'show_views' ) ); ?>"><?php esc_html_e( 'Show view counts', 'vireo-analytics' ); ?></label>
			<br>
			<input type="checkbox" id="<?php echo esc_attr( $this->get_field_id( 'exclude_current' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'exclude_current' ) ); ?>" value="1" <?php checked( $atts['exclude_current'] ); ?>>
			<label for="<?php echo esc_attr( $this->get_field_id( 'exclude_current' ) ); ?>"><?php esc_html_e( 'Leave out the post being viewed', 'vireo-analytics' ); ?></label>
		</p>
		<?php
		return '';
	}
}
