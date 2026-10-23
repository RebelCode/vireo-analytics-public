<?php
/**
 * Row 1 — Hero totals.
 *
 * Locals: $hero (from Dashboard::get_hero_stats).
 */

defined( 'ABSPATH' ) || exit;


// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial included inside a render method; locals are method-scoped at runtime, not true globals.
use VireoAnalytics\Dashboard;
use VireoAnalytics\Engagement_Basics;
use VireoAnalytics\Installer;

$pageviews   = (int) ( $hero['pageviews'] ?? 0 );
$visitors    = (int) ( $hero['visitors'] ?? 0 );
$avg_daily   = (int) ( $hero['avg_daily'] ?? 0 );
$prior       = $hero['prior'] ?? null;
$sparkline   = $hero['sparkline'] ?? [];

$pv_chip = null !== $prior ? Dashboard::delta_chip( $pageviews - (int) $prior['pageviews'], (int) $prior['pageviews'] ) : '';
$vi_chip = null !== $prior ? Dashboard::delta_chip( $visitors  - (int) $prior['visitors'],  (int) $prior['visitors']  ) : '';
$av_chip = null !== $prior ? Dashboard::delta_chip( $avg_daily - (int) $prior['avg_daily'], (int) $prior['avg_daily'] ) : '';

// Absent from a hero cached by the previous version for its last half minute.
$avg_time     = isset( $hero['avg_time'] ) ? (int) $hero['avg_time'] : null;
$time_samples = (int) ( $hero['time_samples'] ?? 0 );
$bounce_rate  = isset( $hero['bounce_rate'] ) ? (float) $hero['bounce_rate'] : null;
$filtered     = ! empty( $hero['filtered'] );
$prior_time   = isset( $prior['avg_time'] ) ? (int) $prior['avg_time'] : null;
$prior_bounce = isset( $prior['bounce_rate'] ) ? (float) $prior['bounce_rate'] : null;

$tm_chip = null !== $avg_time && null !== $prior_time ? Dashboard::delta_chip( $avg_time - $prior_time, $prior_time ) : '';
$br_chip = null !== $bounce_rate && null !== $prior_bounce ? Dashboard::delta_chip_pp( $bounce_rate - $prior_bounce, null, 'up_bad' ) : '';

// A filter combination the rollups cannot answer: the tiles say so instead
// of showing a number from the wrong table.
$unavailable = ! empty( $hero['unavailable'] );
if ( $unavailable ) {
	$pv_chip = $vi_chip = $av_chip = $tm_chip = $br_chip = '';
	$prior   = null;
}
$va_na_sub  = __( 'not available with these filters', 'vireo-analytics' );
$va_na_hint = isset( $va_unavailable['totals'] ) ? (string) $va_unavailable['totals'] : '';

// The number being compared against, and the dates it covers, under each
// tile. A percentage alone hides whether "up 40%" means 10 views or 10,000.
$prior_range = is_array( $hero['prior_range'] ?? null ) ? $hero['prior_range'] : null;
$prior_dates = null === $prior_range ? '' : sprintf(
	/* translators: 1: start date, 2: end date */
	__( '%1$s to %2$s', 'vireo-analytics' ),
	vireo_format_day( (string) $prior_range[0], 'M j' ),
	vireo_format_day( (string) $prior_range[1], 'M j, Y' )
);
$prior_title = 'year' === (string) ( $hero['compare_mode'] ?? 'prior' )
	? __( 'Same period last year', 'vireo-analytics' )
	: __( 'Previous period', 'vireo-analytics' );
$prior_line  = static function ( string $value ) use ( $prior_dates, $prior_title ): void {
	if ( '' === $prior_dates ) {
		return;
	}
	?>
	<div class="va-hero-prior" title="<?php echo esc_attr( $prior_title ); ?>">
		<span class="va-hero-prior-num"><?php echo esc_html( $value ); ?></span>
		<span class="va-hero-prior-dates"><?php echo esc_html( $prior_dates ); ?></span>
	</div>
	<?php
};
?>
<div class="va-row va-row-3 va-hero va-hero-5<?php echo $unavailable ? ' is-unavailable' : ''; ?>">

	<div class="va-card va-hero-card">
		<h2 class="va-card-title"><?php esc_html_e( 'Pageviews', 'vireo-analytics' ); ?></h2>
		<div class="va-hero-body">
			<?php if ( $unavailable ) : ?>
				<div class="va-hero-num">&mdash;</div>
			<?php else : ?>
			<div class="va-hero-num" title="<?php echo esc_attr( number_format_i18n( $pageviews ) ); ?>"
				data-va-tween="<?php echo esc_attr( $pageviews ); ?>">
				<?php echo esc_html( Dashboard::abbrev( $pageviews ) ); ?>
			</div>
			<?php endif; ?>
			<?php if ( '' !== $pv_chip ) : ?><div class="va-hero-chip"><?php echo wp_kses_post( $pv_chip ); ?></div><?php endif; ?>
		</div>
		<?php if ( null !== $prior ) { $prior_line( Dashboard::abbrev( (int) $prior['pageviews'] ) ); } ?>
		<?php // Each card carries its own trend, so the row reads as three equals. ?>
		<div class="va-hero-spark" data-va-hero-spark data-va-spark-metric="pageviews"
			data-va-series="<?php echo esc_attr( wp_json_encode( $sparkline ) ); ?>"></div>
		<?php if ( $unavailable ) : ?>
			<div class="va-hero-sub" title="<?php echo esc_attr( $va_na_hint ); ?>"><?php echo esc_html( $va_na_sub ); ?></div>
		<?php else : ?>
			<div class="va-hero-sub"><?php esc_html_e( 'in the selected range', 'vireo-analytics' ); ?></div>
		<?php endif; ?>
	</div>

	<?php
	// Visitor identity is a salted hash and the salt is regenerated daily, so the
	// same person is deliberately unrecognisable tomorrow. Uniqueness therefore
	// only exists within a day, and a multi-day figure is the sum of daily
	// uniques: someone who came back on three days counts three times. Say so
	// rather than calling it "unique this period", which it isn't.
	$multi_day = (int) ( $hero['days'] ?? 1 ) > 1;
	$vi_sub    = $multi_day
		? __( 'daily uniques, summed', 'vireo-analytics' )
		: __( 'unique today', 'vireo-analytics' );
	$vi_hint   = $multi_day
		? __( 'Someone who visits on more than one day is counted once per day. Visitors are identified by a hash whose salt is regenerated every night, so the same person cannot be recognised across days. That is deliberate: it is what lets Vireo track visitors without cookies and without consent.', 'vireo-analytics' )
		: __( 'Unique visitors today.', 'vireo-analytics' );
	?>
	<div class="va-card va-hero-card">
		<h2 class="va-card-title"><?php esc_html_e( 'Visitors', 'vireo-analytics' ); ?></h2>
		<div class="va-hero-body">
			<?php if ( $unavailable ) : ?>
				<div class="va-hero-num">&mdash;</div>
			<?php else : ?>
			<div class="va-hero-num" title="<?php echo esc_attr( number_format_i18n( $visitors ) ); ?>"
				data-va-tween="<?php echo esc_attr( $visitors ); ?>">
				<?php echo esc_html( Dashboard::abbrev( $visitors ) ); ?>
			</div>
			<?php endif; ?>
			<?php if ( '' !== $vi_chip ) : ?><div class="va-hero-chip"><?php echo wp_kses_post( $vi_chip ); ?></div><?php endif; ?>
		</div>
		<?php if ( null !== $prior ) { $prior_line( Dashboard::abbrev( (int) $prior['visitors'] ) ); } ?>
		<div class="va-hero-spark" data-va-hero-spark data-va-spark-metric="visitors"
			data-va-series="<?php echo esc_attr( wp_json_encode( $sparkline ) ); ?>"></div>
		<?php if ( $unavailable ) : ?>
			<div class="va-hero-sub" title="<?php echo esc_attr( $va_na_hint ); ?>"><?php echo esc_html( $va_na_sub ); ?></div>
		<?php else : ?>
			<div class="va-hero-sub" title="<?php echo esc_attr( $vi_hint ); ?>"><?php echo esc_html( $vi_sub ); ?></div>
		<?php endif; ?>
	</div>

	<div class="va-card va-hero-card va-hero-card-spark">
		<h2 class="va-card-title"><?php esc_html_e( 'Average daily traffic', 'vireo-analytics' ); ?></h2>
		<div class="va-hero-body">
			<?php if ( $unavailable ) : ?>
				<div class="va-hero-num">&mdash;</div>
			<?php else : ?>
			<div class="va-hero-num" title="<?php echo esc_attr( number_format_i18n( $avg_daily ) ); ?>"
				data-va-tween="<?php echo esc_attr( $avg_daily ); ?>">
				<?php echo esc_html( Dashboard::abbrev( $avg_daily ) ); ?>
			</div>
			<?php endif; ?>
			<?php if ( '' !== $av_chip ) : ?><div class="va-hero-chip"><?php echo wp_kses_post( $av_chip ); ?></div><?php endif; ?>
		</div>
		<?php if ( null !== $prior ) { $prior_line( Dashboard::abbrev( (int) $prior['avg_daily'] ) ); } ?>
		<div class="va-hero-spark" data-va-hero-spark data-va-spark-metric="pageviews" data-va-spark-average="1"
			data-va-series="<?php echo esc_attr( wp_json_encode( $sparkline ) ); ?>"></div>
		<div class="va-hero-sub"<?php echo $unavailable ? ' title="' . esc_attr( $va_na_hint ) . '"' : ''; ?>>
			<?php
			if ( $unavailable ) {
				echo esc_html( $va_na_sub );
			} else {
				// Built outside printf: nested there, the string never reached the
				// translation template, so every locale showed it in English.
				$va_days = (int) ( $hero['days'] ?? 0 );
				/* translators: %d: number of days */
				$va_days_label = _n( 'over %d day', 'over %d days', $va_days, 'vireo-analytics' );
				echo esc_html( sprintf( $va_days_label, $va_days ) );
			}
			?>
		</div>
	</div>

	<?php
	if ( $filtered ) {
		$tm_sub  = __( 'not split by filters', 'vireo-analytics' );
		$tm_hint = __( 'Time on page is measured for the whole site, so it is not shown while a filter is on.', 'vireo-analytics' );
	} elseif ( null === $avg_time ) {
		// Why it is empty and when it fills: the range may end before the
		// first timed view, or the number may simply not have arrived yet.
		$tm_empty = Engagement_Basics::time_empty_state(
			isset( $hero['time_since'] ) ? (string) $hero['time_since'] : null,
			(string) ( $hero['end'] ?? '' )
		);
		$tm_sub   = $tm_empty['sub'];
		$tm_hint  = $tm_empty['hint'];
	} else {
		$tm_sub  = __( 'average active time', 'vireo-analytics' );
		$tm_hint = sprintf(
			/* translators: %s: number of page views that reported a time. */
			_n(
				'The average of the %s view that reported a time. Only time with the tab in front of the reader counts, up to 30 minutes a view, and views closed before they reported are left out.',
				'The average of the %s views that reported a time. Only time with the tab in front of the reader counts, up to 30 minutes a view, and views closed before they reported are left out.',
				$time_samples,
				'vireo-analytics'
			),
			number_format_i18n( $time_samples )
		);
	}
	?>
	<div class="va-card va-hero-card">
		<h2 class="va-card-title"><?php esc_html_e( 'Time on page', 'vireo-analytics' ); ?></h2>
		<div class="va-hero-body">
			<div class="va-hero-num<?php echo null === $avg_time ? ' va-hero-num-empty' : ''; ?>"><?php echo null === $avg_time ? '&mdash;' : esc_html( Engagement_Basics::format_seconds( $avg_time ) ); ?></div>
			<?php if ( '' !== $tm_chip ) : ?><div class="va-hero-chip"><?php echo wp_kses_post( $tm_chip ); ?></div><?php endif; ?>
		</div>
		<?php if ( null !== $avg_time && null !== $prior_time ) { $prior_line( Engagement_Basics::format_seconds( $prior_time ) ); } ?>
		<div class="va-hero-spark" data-va-hero-spark data-va-spark-metric="value"
			data-va-series="<?php echo esc_attr( wp_json_encode( $hero['time_series'] ?? [] ) ); ?>"></div>
		<div class="va-hero-sub" title="<?php echo esc_attr( $tm_hint ); ?>"><?php echo esc_html( $tm_sub ); ?></div>
	</div>

	<?php
	$br_since = (string) get_option( Installer::BOUNCE_SINCE_OPTION, '' );
	if ( $filtered ) {
		$br_sub  = __( 'not split by filters', 'vireo-analytics' );
		$br_hint = __( 'Bounce rate is measured for the whole site, so it is not shown while a filter is on.', 'vireo-analytics' );
	} elseif ( null === $bounce_rate ) {
		// Names the first counted day: still ahead, before this range, or
		// reached with nothing flushed yet.
		$br_empty = Engagement_Basics::bounce_empty_state( $br_since, vireo_bucket_date(), (string) ( $hero['end'] ?? '' ) );
		$br_sub   = $br_empty['sub'];
		$br_hint  = $br_empty['hint'];
	} else {
		$br_sub  = __( 'visitors who viewed one page', 'vireo-analytics' );
		$br_hint = __( 'The share of visitors who viewed exactly one page in a day. A visitor is counted the same way as on the Visitors card, once per day, so someone who comes back on another day counts again.', 'vireo-analytics' );
	}
	?>
	<div class="va-card va-hero-card">
		<h2 class="va-card-title"><?php esc_html_e( 'Bounce rate', 'vireo-analytics' ); ?></h2>
		<div class="va-hero-body">
			<div class="va-hero-num<?php echo null === $bounce_rate ? ' va-hero-num-empty' : ''; ?>"><?php echo null === $bounce_rate ? '&mdash;' : esc_html( number_format_i18n( $bounce_rate, 1 ) . '%' ); ?></div>
			<?php if ( '' !== $br_chip ) : ?><div class="va-hero-chip"><?php echo wp_kses_post( $br_chip ); ?></div><?php endif; ?>
		</div>
		<?php if ( null !== $bounce_rate && null !== $prior_bounce ) { $prior_line( number_format_i18n( $prior_bounce, 1 ) . '%' ); } ?>
		<div class="va-hero-spark" data-va-hero-spark data-va-spark-metric="value"
			data-va-series="<?php echo esc_attr( wp_json_encode( $hero['bounce_series'] ?? [] ) ); ?>"></div>
		<div class="va-hero-sub" title="<?php echo esc_attr( $br_hint ); ?>"><?php echo esc_html( $br_sub ); ?></div>
	</div>

</div>
<?php if ( ! empty( $va_prior_import ) && null !== $prior ) : ?>
	<?php // The comparison reaches into history Vireo never recorded itself; say whose numbers those are. ?>
	<p class="va-hero-note">
		<?php
		echo esc_html(
			sprintf(
				/* translators: 1: the compared period's dates, 2: the name of the tool the history was imported from */
				__( 'The %1$s figures come from history imported from %2$s.', 'vireo-analytics' ),
				$prior_dates,
				$va_prior_import
			)
		);
		?>
	</p>
<?php endif; ?>
