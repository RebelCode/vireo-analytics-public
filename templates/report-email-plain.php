<?php
/**
 * Plain weekly report email template.
 *
 * Expected in scope (set by Email_Reports::build_and_send):
 *   $data          array   gather_week_data() output
 *   $period_label  string  e.g. "Apr 13 – Apr 19, 2026"
 *   $site_name     string
 *   $site_url      string
 *   $dashboard_url string
 *   $frequency     string  daily | weekly | monthly (weekly when unset)
 */
defined( 'ABSPATH' ) || exit;


// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial included inside a render method; locals are method-scoped at runtime, not true globals.
$totals       = $data['totals'] ?? [];
$visitors     = (int) ( $totals['visitors'] ?? 0 );
$pageviews    = (int) ( $totals['pageviews'] ?? 0 );
$prior_vis    = (int) ( $totals['prior_visitors'] ?? 0 );
$prior_pv     = (int) ( $totals['prior_pageviews'] ?? 0 );
$visitors_pct = $prior_vis > 0 ? (int) round( ( $visitors - $prior_vis ) / $prior_vis * 100 ) : 0;
$pv_pct       = $prior_pv > 0 ? (int) round( ( $pageviews - $prior_pv ) / $prior_pv * 100 ) : 0;

// Absent when Pro renders this template from its own payload, or when there's
// no prior week to compare against.
$insight = trim( (string) ( $data['insight'] ?? '' ) );

// The week's notable facts, from Highlights. Absent from Pro's own payload and
// empty on a quiet week, and then the block is left out.
$highlights = is_array( $data['highlights'] ?? null ) ? array_slice( $data['highlights'], 0, 3 ) : [];

$top_pages     = is_array( $data['top_pages'] ?? null ) ? $data['top_pages'] : [];
$top_refs      = is_array( $data['top_referrers'] ?? null ) ? $data['top_referrers'] : [];
$top_countries = is_array( $data['top_countries'] ?? null ) ? $data['top_countries'] : [];

$country_name_fn = [ '\\VireoAnalytics\\Email_Reports', 'country_name' ];

$frequency = isset( $frequency ) && in_array( $frequency, [ 'daily', 'weekly', 'monthly' ], true ) ? $frequency : 'weekly';
switch ( $frequency ) {
	case 'daily':
		/* translators: %s: site name */
		$heading = sprintf( __( 'Your day on %s', 'vireo-analytics' ), $site_name );
		/* translators: %s: period label, in the hidden inbox preview text */
		$preheader = sprintf( __( 'Your daily snapshot · %s', 'vireo-analytics' ), $period_label );
		$delta_label = _x( 'DoD', 'change against the previous day', 'vireo-analytics' );
		break;
	case 'monthly':
		/* translators: %s: site name */
		$heading = sprintf( __( 'Your month on %s', 'vireo-analytics' ), $site_name );
		/* translators: %s: period label, in the hidden inbox preview text */
		$preheader = sprintf( __( 'Your monthly snapshot · %s', 'vireo-analytics' ), $period_label );
		$delta_label = _x( 'MoM', 'change against the previous month', 'vireo-analytics' );
		break;
	default:
		/* translators: %s: site name */
		$heading = sprintf( __( 'Your week on %s', 'vireo-analytics' ), $site_name );
		/* translators: %s: period label, in the hidden inbox preview text */
		$preheader = sprintf( __( 'Your weekly snapshot · %s', 'vireo-analytics' ), $period_label );
		$delta_label = _x( 'WoW', 'change against the previous week', 'vireo-analytics' );
}

/**
 * How the email is branded. Pro's white-label settings supply a logo, an
 * accent colour and whether the Vireo name appears in the footer.
 *
 * @param array{logo_url:string,accent:string,show_vireo:bool} $brand
 */
$brand = apply_filters(
	'vireo_email_brand',
	[
		'logo_url'   => '',
		'accent'     => '#0f172a',
		'show_vireo' => true,
	]
);
$brand_logo   = (string) ( $brand['logo_url'] ?? '' );
$brand_accent = (string) ( $brand['accent'] ?? '#0f172a' );
$brand_accent = preg_match( '/^#[0-9a-fA-F]{6}$/', $brand_accent ) ? $brand_accent : '#0f172a';
$brand_vireo  = ! empty( $brand['show_vireo'] );
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $heading ); ?></title>
</head>
<body style="margin:0;padding:0;background-color:#f4f5f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;color:#0f172a;">

<div style="display:none;font-size:1px;color:#f4f5f7;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">
	<?php if ( '' !== $insight ) : ?>
		<?php echo esc_html( $insight ); ?>
	<?php else : ?>
		<?php echo esc_html( $preheader ); ?>
	<?php endif; ?>
</div>

<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#f4f5f7;">
	<tr>
		<td align="center" style="padding:32px 16px;">

			<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:640px;background:#ffffff;border:1px solid #e5e7eb;border-radius:8px;">

				<tr>
					<td style="padding:28px 32px 20px;border-bottom:1px solid #f1f5f9;">
						<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
							<tr>
								<td>
									<?php if ( '' !== $brand_logo ) : ?>
										<img src="<?php echo esc_url( $brand_logo ); ?>" alt="" height="32" style="display:block;height:32px;max-width:200px;margin:0 0 12px;border:0;">
									<?php endif; ?>
									<div style="font-size:20px;font-weight:700;color:#0f172a;"><?php echo esc_html( $heading ); ?></div>
									<div style="margin-top:2px;font-size:14px;color:#64748b;"><?php echo esc_html( $period_label ); ?></div>
								</td>
								<td align="right" valign="top" style="padding-top:4px;">
									<?php if ( 0 !== $visitors_pct ) : ?>
										<span style="display:inline-block;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:600;white-space:nowrap;<?php echo $visitors_pct > 0 ? 'background:#ecfdf5;color:#047857;' : 'background:#fef2f2;color:#b91c1c;'; ?>">
											<?php echo $visitors_pct > 0 ? '+' : ''; ?><?php echo esc_html( (string) $visitors_pct ); ?>% <?php echo esc_html( $delta_label ); ?>
										</span>
									<?php endif; ?>
								</td>
							</tr>
						</table>
					</td>
				</tr>

				<?php if ( '' !== $insight ) : ?>
				<tr>
					<td style="padding:20px 32px 0;">
						<div style="font-size:16px;line-height:1.5;font-weight:600;color:#0f172a;"><?php echo esc_html( $insight ); ?></div>
					</td>
				</tr>
				<?php endif; ?>

				<?php if ( ! empty( $highlights ) ) : ?>
				<tr>
					<td style="padding:12px 32px 0;" data-va-highlights>
						<ul style="margin:0;padding:0 0 0 18px;font-size:14px;line-height:1.5;color:#334155;">
							<?php foreach ( $highlights as $highlight ) : ?>
								<li style="margin:0 0 4px;"><?php echo esc_html( (string) ( $highlight['text'] ?? '' ) ); ?></li>
							<?php endforeach; ?>
						</ul>
					</td>
				</tr>
				<?php endif; ?>

				<tr>
					<td style="padding:20px 32px 4px;">
						<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f8fafc;border-radius:6px;">
							<tr>
								<td style="padding:16px;text-align:center;border-right:1px solid #e5e7eb;">
									<div style="font-size:11px;font-weight:600;letter-spacing:0.06em;text-transform:uppercase;color:#64748b;">Visitors</div>
									<div style="margin-top:4px;font-size:22px;font-weight:700;color:#0f172a;"><?php echo esc_html( number_format_i18n( $visitors ) ); ?></div>
									<?php if ( 0 !== $visitors_pct ) : ?>
										<div style="margin-top:2px;font-size:12px;font-weight:600;color:<?php echo $visitors_pct > 0 ? '#047857' : '#b91c1c'; ?>;">
											<?php echo $visitors_pct > 0 ? '+' : ''; ?><?php echo esc_html( (string) $visitors_pct ); ?>%
										</div>
									<?php endif; ?>
								</td>
								<td style="padding:16px;text-align:center;">
									<div style="font-size:11px;font-weight:600;letter-spacing:0.06em;text-transform:uppercase;color:#64748b;">Pageviews</div>
									<div style="margin-top:4px;font-size:22px;font-weight:700;color:#0f172a;"><?php echo esc_html( number_format_i18n( $pageviews ) ); ?></div>
									<?php if ( 0 !== $pv_pct ) : ?>
										<div style="margin-top:2px;font-size:12px;font-weight:600;color:<?php echo $pv_pct > 0 ? '#047857' : '#b91c1c'; ?>;">
											<?php echo $pv_pct > 0 ? '+' : ''; ?><?php echo esc_html( (string) $pv_pct ); ?>%
										</div>
									<?php endif; ?>
								</td>
							</tr>
						</table>
					</td>
				</tr>

				<?php if ( ! empty( $top_pages ) ) : ?>
				<tr>
					<td style="padding:20px 32px 4px;">
						<div style="font-size:13px;font-weight:600;color:#0f172a;margin-bottom:8px;">Top Pages</div>
						<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="font-size:13px;">
							<?php foreach ( $top_pages as $i => $page ) :
								$title = (string) ( $page['title'] ?? '' );
								$path  = (string) ( $page['path'] ?? '' );
								$pv    = (int) ( $page['pageviews'] ?? 0 );
								$label = '' !== $title ? $title : $path;
							?>
							<tr style="border-bottom:1px solid #f1f5f9;">
								<td style="padding:7px 0;color:#64748b;width:24px;"><?php echo esc_html( (string) ( (int) $i + 1 ) ); ?>.</td>
								<td style="padding:7px 8px 7px 0;color:#0f172a;">
									<?php echo esc_html( $label ); ?>
									<?php if ( $path && $title && $path !== $title ) : ?>
										<div style="color:#94a3b8;font-size:11px;"><?php echo esc_html( $path ); ?></div>
									<?php endif; ?>
								</td>
								<td style="padding:7px 0;text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums;"><?php echo esc_html( number_format_i18n( $pv ) ); ?></td>
							</tr>
							<?php endforeach; ?>
						</table>
					</td>
				</tr>
				<?php endif; ?>

				<?php if ( ! empty( $top_refs ) ) : ?>
				<tr>
					<td style="padding:20px 32px 4px;">
						<div style="font-size:13px;font-weight:600;color:#0f172a;margin-bottom:8px;">Top Referrers</div>
						<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="font-size:13px;">
							<?php foreach ( $top_refs as $ref ) :
								$host = (string) ( $ref['host'] ?? '' );
								$vis  = (int) ( $ref['visitors'] ?? 0 );
							?>
							<tr style="border-bottom:1px solid #f1f5f9;">
								<td style="padding:7px 0;color:#0f172a;"><?php echo esc_html( vireo_referrer_label( $host ) ); ?></td>
								<td style="padding:7px 0;text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums;"><?php echo esc_html( number_format_i18n( $vis ) ); ?></td>
							</tr>
							<?php endforeach; ?>
						</table>
					</td>
				</tr>
				<?php endif; ?>

				<?php if ( ! empty( $top_countries ) ) : ?>
				<tr>
					<td style="padding:20px 32px 24px;">
						<div style="font-size:13px;font-weight:600;color:#0f172a;margin-bottom:8px;">Top Countries</div>
						<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="font-size:13px;">
							<?php foreach ( array_slice( $top_countries, 0, 5 ) as $c ) :
								$code = (string) ( $c['code'] ?? '' );
								$vis  = (int) ( $c['visitors'] ?? 0 );
								$name = '' !== $code ? call_user_func( $country_name_fn, $code ) : '';
							?>
							<tr style="border-bottom:1px solid #f1f5f9;">
								<td style="padding:7px 0;color:#0f172a;"><?php echo esc_html( '' !== $name ? $name : $code ); ?></td>
								<td style="padding:7px 0;text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums;"><?php echo esc_html( number_format_i18n( $vis ) ); ?></td>
							</tr>
							<?php endforeach; ?>
						</table>
					</td>
				</tr>
				<?php endif; ?>

				<tr>
					<td style="padding:20px 32px 28px;border-top:1px solid #f1f5f9;text-align:center;">
						<a href="<?php echo esc_url( $dashboard_url ); ?>" style="display:inline-block;padding:10px 20px;background:<?php echo esc_attr( $brand_accent ); ?>;color:#ffffff;text-decoration:none;border-radius:6px;font-size:13px;font-weight:600;">View full dashboard</a>
						<div style="margin-top:14px;font-size:12px;color:#94a3b8;">
							<a href="<?php echo esc_url( $site_url ); ?>" style="color:#94a3b8;text-decoration:none;"><?php echo esc_html( (string) wp_parse_url( $site_url, PHP_URL_HOST ) ); ?></a>
							<?php if ( $brand_vireo ) : ?>
								&middot; Vireo Analytics
							<?php endif; ?>
						</div>
					</td>
				</tr>

			</table>

		</td>
	</tr>
</table>

</body>
</html>
