<?php
/**
 * The checks behind the AI visibility scan: what each looks at, how it scores and what to do when it fails.
 */

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

final class AI_Scan_Checks {

	private const POINTS = [
		'pass' => 1.0,
		'warn' => 0.5,
		'fail' => 0.0,
	];

	/**
	 * @return array<string,string>
	 */
	public static function categories(): array {
		return [
			'access'    => __( 'AI crawler access', 'vireo-analytics' ),
			'content'   => __( 'Content AI can read', 'vireo-analytics' ),
			'structure' => __( 'Structured data', 'vireo-analytics' ),
			'discovery' => __( 'Discovery files', 'vireo-analytics' ),
		];
	}

	/**
	 * Search and answer crawlers decide whether the site can be cited today.
	 * Blocking training crawlers is a legitimate business choice, so it only warns.
	 *
	 * @return array<int,array{token:string,label:string,kind:string}>
	 */
	public static function robots_agents(): array {
		return [
			[
				'token' => 'OAI-SearchBot',
				'label' => __( 'ChatGPT search', 'vireo-analytics' ),
				'kind'  => 'search',
			],
			[
				'token' => 'ChatGPT-User',
				'label' => __( 'ChatGPT browsing', 'vireo-analytics' ),
				'kind'  => 'search',
			],
			[
				'token' => 'PerplexityBot',
				'label' => __( 'Perplexity', 'vireo-analytics' ),
				'kind'  => 'search',
			],
			[
				'token' => 'Claude-SearchBot',
				'label' => __( 'Claude search', 'vireo-analytics' ),
				'kind'  => 'search',
			],
			[
				'token' => 'Googlebot',
				'label' => __( 'Google and AI Overviews', 'vireo-analytics' ),
				'kind'  => 'search',
			],
			[
				'token' => 'Bingbot',
				'label' => __( 'Bing and Copilot', 'vireo-analytics' ),
				'kind'  => 'search',
			],
			[
				'token' => 'GPTBot',
				'label' => __( 'OpenAI training', 'vireo-analytics' ),
				'kind'  => 'training',
			],
			[
				'token' => 'ClaudeBot',
				'label' => __( 'Anthropic training', 'vireo-analytics' ),
				'kind'  => 'training',
			],
			[
				'token' => 'Google-Extended',
				'label' => __( 'Gemini training', 'vireo-analytics' ),
				'kind'  => 'training',
			],
			[
				'token' => 'Applebot-Extended',
				'label' => __( 'Apple Intelligence', 'vireo-analytics' ),
				'kind'  => 'training',
			],
			[
				'token' => 'CCBot',
				'label' => __( 'Common Crawl', 'vireo-analytics' ),
				'kind'  => 'training',
			],
		];
	}

	/**
	 * @param array{
	 *   path:string,
	 *   headers:array<string,string>,
	 *   facts:array<string,mixed>,
	 *   robots:?AI_Scan_Robots,
	 *   robots_status:int,
	 *   blog_public:bool,
	 *   bots:array<int,array{name:string,status:int,error:string,challenged?:bool}>,
	 *   sitemap:?string,
	 *   llms_length:int
	 * } $in
	 * @return array<int,array{id:string,category:string,title:string,status:string,weight:int,summary:string,fix:string}>
	 */
	public function build( array $in ): array {
		$facts  = $in['facts'];
		$checks = [];
		$add    = static function ( string $id, string $category, int $weight, string $title, string $status, string $summary, string $fix = '' ) use ( &$checks ): void {
			$checks[] = [
				'id'       => $id,
				'category' => $category,
				'title'    => $title,
				'status'   => $status,
				'weight'   => $weight,
				'summary'  => $summary,
				'fix'      => 'pass' === $status ? '' : $fix,
			];
		};

		$robots   = $in['robots'];
		$verdicts = null === $robots ? null : $robots->verdicts( array_column( self::robots_agents(), 'token' ), $in['path'] );
		if ( null === $robots ) {
			$missing = 404 === $in['robots_status'];
			$add(
				'robots',
				'access',
				2,
				__( 'robots.txt lets AI crawlers in', 'vireo-analytics' ),
				$missing ? 'pass' : 'warn',
				$missing
					? __( 'No robots.txt, so every crawler is allowed by default.', 'vireo-analytics' )
					: sprintf(
						/* translators: %s: HTTP status code or error */
						__( 'robots.txt didn\'t load cleanly (%s).', 'vireo-analytics' ),
						$in['robots_status'] ? (string) $in['robots_status'] : __( 'no response', 'vireo-analytics' )
					),
				__( 'Make /robots.txt return plain text with a 200 status. Crawlers that get errors may back off your whole site.', 'vireo-analytics' )
			);
		} elseif ( null === $verdicts ) {
			$add(
				'robots',
				'access',
				2,
				__( 'robots.txt lets AI crawlers in', 'vireo-analytics' ),
				'warn',
				__( 'robots.txt is too long or complex to evaluate reliably.', 'vireo-analytics' ),
				__( 'Simplify robots.txt: keep rules short, avoid long wildcard patterns, and give each AI crawler a plain Allow or Disallow line.', 'vireo-analytics' )
			);
		} else {
			$blocked = [
				'search'   => [],
				'training' => [],
			];
			foreach ( self::robots_agents() as $agent ) {
				if ( ! $verdicts[ $agent['token'] ] ) {
					$blocked[ $agent['kind'] ][] = $agent;
				}
			}
			$add(
				'robots-search',
				'access',
				5,
				__( 'AI search crawlers are allowed', 'vireo-analytics' ),
				$blocked['search'] ? 'fail' : 'pass',
				$blocked['search']
					? sprintf(
						/* translators: %s: list of crawler names */
						__( 'robots.txt blocks %s.', 'vireo-analytics' ),
						$this->agent_list( $blocked['search'] )
					)
					: __( 'ChatGPT search, Perplexity, Claude search, Google and Bing can all read this page.', 'vireo-analytics' ),
				sprintf(
					/* translators: %s: comma-separated crawler tokens */
					__( 'Remove the Disallow rules for %s. These crawlers fetch pages to cite in live AI answers, so blocking them keeps you out of those answers.', 'vireo-analytics' ),
					implode( ', ', array_column( $blocked['search'], 'token' ) )
				)
			);
			$add(
				'robots-training',
				'access',
				2,
				__( 'AI training crawlers are allowed', 'vireo-analytics' ),
				$blocked['training'] ? 'warn' : 'pass',
				$blocked['training']
					? sprintf(
						/* translators: %s: list of crawler names */
						__( 'Blocked: %s.', 'vireo-analytics' ),
						$this->agent_list( $blocked['training'] )
					)
					: __( 'Training crawlers (GPTBot, ClaudeBot, Google-Extended and others) can read this page.', 'vireo-analytics' ),
				__( 'This is a business choice. Blocking training crawlers keeps your content out of future models, which also means those models know less about you.', 'vireo-analytics' )
			);
		}

		$this->live_bots_check( $in['bots'], $add );

		$robots_meta = (string) $facts['robots_meta'];
		$noindex     = ! $in['blog_public']
			|| false !== strpos( $robots_meta, 'noindex' )
			|| false !== stripos( $in['headers']['x-robots-tag'] ?? '', 'noindex' );
		$noai        = 1 === preg_match( '/\bnoai\b|noimageai/', $robots_meta );
		if ( ! $in['blog_public'] ) {
			$index_summary = __( 'Settings > Reading has "Discourage search engines from indexing this site" turned on.', 'vireo-analytics' );
		} elseif ( $noindex ) {
			$index_summary = __( 'This page carries a noindex instruction.', 'vireo-analytics' );
		} elseif ( $noai ) {
			$index_summary = __( 'This page carries a "noai" instruction.', 'vireo-analytics' );
		} else {
			$index_summary = __( 'No noindex or noai instructions found.', 'vireo-analytics' );
		}
		$add(
			'indexable',
			'access',
			4,
			__( 'The page is allowed in search results', 'vireo-analytics' ),
			$noindex ? 'fail' : ( $noai ? 'warn' : 'pass' ),
			$index_summary,
			$noindex
				? __( 'Remove the noindex robots meta tag or X-Robots-Tag header. Check Settings > Reading and the page\'s settings in your SEO plugin.', 'vireo-analytics' )
				: __( 'Remove "noai" from the robots meta tag unless you want AI tools to skip this page.', 'vireo-analytics' )
		);

		$words = (int) $facts['word_count'];
		$shell = $words < 150 && $facts['app_shell'];
		$add(
			'readable-text',
			'content',
			5,
			__( 'Text is readable without JavaScript', 'vireo-analytics' ),
			$words < 150 ? 'fail' : ( $words < 300 ? 'warn' : 'pass' ),
			$shell
				? sprintf(
					/* translators: %d: word count */
					__( 'Only %d words in the raw HTML. The page builds its content with JavaScript, which most AI crawlers don\'t run.', 'vireo-analytics' ),
					$words
				)
				: sprintf(
					/* translators: %d: word count */
					_n( '%d word of readable text in the raw HTML.', '%d words of readable text in the raw HTML.', $words, 'vireo-analytics' ),
					$words
				),
			$shell
				? __( 'Render the content on the server so the text is in the HTML itself.', 'vireo-analytics' )
				: __( 'Say more on the page: what you do, who it\'s for, where you work and what it costs. AI tools can only repeat what you\'ve written down.', 'vireo-analytics' )
		);

		$h1 = (array) $facts['h1'];
		$add(
			'h1',
			'content',
			2,
			__( 'One clear main heading', 'vireo-analytics' ),
			1 === count( $h1 ) ? 'pass' : ( $h1 ? 'warn' : 'fail' ),
			$h1
				? sprintf(
					/* translators: 1: number of H1 headings, 2: first heading text */
					_n( '%1$d H1 heading: "%2$s"', '%1$d H1 headings, the first is "%2$s"', count( $h1 ), 'vireo-analytics' ),
					count( $h1 ),
					$this->clip( (string) $h1[0], 80 )
				)
				: __( 'No H1 heading.', 'vireo-analytics' ),
			__( 'Use exactly one H1 that states what the page is about in plain words.', 'vireo-analytics' )
		);

		$h2 = count( (array) $facts['h2'] );
		$add(
			'sections',
			'content',
			2,
			__( 'Content is split into sections', 'vireo-analytics' ),
			$h2 >= 2 ? 'pass' : 'warn',
			sprintf(
				/* translators: %d: count */
				_n( '%d H2 subheading.', '%d H2 subheadings.', $h2, 'vireo-analytics' ),
				$h2
			),
			__( 'Break the page into sections with descriptive H2 headings. AI tools pull answers section by section.', 'vireo-analytics' )
		);

		$questions = count(
			array_filter(
				(array) $facts['headings'],
				static function ( string $h ): bool {
					return '?' === substr( rtrim( $h ), -1 );
				}
			)
		);
		$has_faq   = in_array( 'FAQPage', (array) $facts['schema_types'], true );
		$add(
			'answers',
			'content',
			2,
			__( 'Answers the questions people ask', 'vireo-analytics' ),
			$has_faq || $questions >= 2 ? 'pass' : 'warn',
			$has_faq
				? __( 'FAQ content with FAQPage markup found.', 'vireo-analytics' )
				: sprintf(
					/* translators: %d: count */
					_n( '%d question-style heading found.', '%d question-style headings found.', $questions, 'vireo-analytics' ),
					$questions
				),
			__( 'Add an FAQ with the questions people actually ask, one question per heading with the answer straight under it. AI tools pull answers section by section.', 'vireo-analytics' )
		);

		$images    = (int) $facts['images'];
		$alt       = (int) $facts['images_alt'];
		$alt_ratio = $images > 0 ? $alt / $images : 1;
		$add(
			'alt',
			'content',
			1,
			__( 'Images have alt text', 'vireo-analytics' ),
			$alt_ratio >= 0.9 ? 'pass' : ( $alt_ratio >= 0.5 ? 'warn' : 'fail' ),
			$images
				? sprintf(
					/* translators: 1: images with alt text, 2: total images */
					__( '%1$d of %2$d images have alt text.', 'vireo-analytics' ),
					$alt,
					$images
				)
				: __( 'No images in the HTML.', 'vireo-analytics' ),
			__( 'Describe each image in its alt text. AI tools read alt text instead of looking at the picture.', 'vireo-analytics' )
		);

		$lang = (string) $facts['lang'];
		$add(
			'lang',
			'content',
			1,
			__( 'Language is declared', 'vireo-analytics' ),
			'' !== $lang ? 'pass' : 'warn',
			'' !== $lang
				/* translators: %s: language code */
				? sprintf( __( 'lang="%s"', 'vireo-analytics' ), $lang )
				: __( 'The <html> tag has no lang attribute.', 'vireo-analytics' ),
			__( 'Your theme should print language_attributes() on the <html> tag so AI tools answer in the right language.', 'vireo-analytics' )
		);

		$this->identity_checks( $facts, $add );

		$sitemap = $in['sitemap'];
		$add(
			'sitemap',
			'discovery',
			2,
			__( 'XML sitemap', 'vireo-analytics' ),
			null !== $sitemap ? 'pass' : 'fail',
			null !== $sitemap
				/* translators: %s: sitemap URL */
				? sprintf( __( 'Found at %s', 'vireo-analytics' ), $sitemap )
				: __( 'No sitemap found in robots.txt, at /sitemap.xml, /sitemap_index.xml or /wp-sitemap.xml.', 'vireo-analytics' ),
			__( 'Publish an XML sitemap and list it in robots.txt with a "Sitemap:" line. WordPress core and most SEO plugins generate one.', 'vireo-analytics' )
		);

		$llms = (int) $in['llms_length'];
		$add(
			'llms',
			'discovery',
			1,
			__( 'llms.txt file', 'vireo-analytics' ),
			$llms > 0 ? 'pass' : 'warn',
			$llms > 0
				? sprintf(
					/* translators: %s: number of characters */
					__( 'Found /llms.txt (%s characters).', 'vireo-analytics' ),
					number_format_i18n( $llms )
				)
				: __( 'No valid /llms.txt. The URL is missing or returns an HTML page instead of plain text.', 'vireo-analytics' ),
			__( 'Optional. Add a plain-text file at /llms.txt that says what your site is and links to your most useful pages. Some SEO plugins can generate one.', 'vireo-analytics' )
		);

		return $checks;
	}

	/**
	 * @param array<int,array{weight:int,status:string,category:string}> $checks
	 * @return array{score:int,categories:array<string,int|null>}
	 */
	public function score( array $checks ): array {
		$categories = [];
		foreach ( array_keys( self::categories() ) as $key ) {
			$categories[ $key ] = $this->percent(
				array_filter(
					$checks,
					static function ( array $c ) use ( $key ): bool {
						return $c['category'] === $key;
					}
				)
			);
		}
		return [
			'score'      => (int) $this->percent( $checks ),
			'categories' => $categories,
		];
	}

	/**
	 * @param array<int,array{weight:int,status:string}> $checks
	 */
	private function percent( array $checks ): ?int {
		$total = 0;
		$got   = 0.0;
		foreach ( $checks as $c ) {
			if ( 'skip' === $c['status'] ) {
				continue;
			}
			$total += (int) $c['weight'];
			$got   += (int) $c['weight'] * ( self::POINTS[ $c['status'] ] ?? 0.0 );
		}
		return $total > 0 ? (int) round( 100 * $got / $total ) : null;
	}

	/**
	 * @param array<int,array{name:string,status:int,error:string,challenged?:bool}> $bots
	 */
	private function live_bots_check( array $bots, callable $add ): void {
		$refused = [];
		$failed  = [];
		foreach ( $bots as $b ) {
			if ( ! empty( $b['challenged'] ) ) {
				/* translators: %s: crawler name */
				$refused[] = sprintf( __( '%s (got a challenge or a different page)', 'vireo-analytics' ), $b['name'] );
			} elseif ( in_array( $b['status'], [ 401, 403, 429, 503 ], true ) ) {
				/* translators: 1: crawler name, 2: HTTP status code */
				$refused[] = sprintf( __( '%1$s (HTTP %2$d)', 'vireo-analytics' ), $b['name'], $b['status'] );
			} elseif ( $b['status'] < 200 || $b['status'] >= 400 ) {
				$failed[] = sprintf(
					'%1$s (%2$s)',
					$b['name'],
					$b['status'] ? (string) $b['status'] : ( '' !== $b['error'] ? $b['error'] : __( 'no response', 'vireo-analytics' ) )
				);
			}
		}

		if ( $refused ) {
			$summary = sprintf(
				/* translators: %s: list of crawlers with status codes */
				__( 'Requests identifying as %s didn\'t get your page.', 'vireo-analytics' ),
				implode( ', ', $refused )
			);
		} elseif ( $failed ) {
			$summary = sprintf(
				/* translators: %s: list of crawlers with errors */
				__( 'Some crawler requests failed: %s.', 'vireo-analytics' ),
				implode( ', ', $failed )
			);
		} else {
			$summary = sprintf(
				/* translators: %s: list of crawler names */
				__( 'Requests identifying as %s all got the page.', 'vireo-analytics' ),
				implode( ', ', array_column( $bots, 'name' ) )
			);
		}

		$add(
			'live-bots',
			'access',
			4,
			__( 'Your server answers AI crawlers', 'vireo-analytics' ),
			$refused || $failed ? 'warn' : 'pass',
			$summary,
			$refused
				? __( 'A firewall, CDN or security plugin is probably blocking AI crawlers even though robots.txt allows them. Check your CDN\'s AI bot blocking, your security plugin and your host\'s bot protection. Some firewalls verify real crawlers by IP address and reject imitations like this test, so if those settings are off this can be a false alarm.', 'vireo-analytics' )
				: __( 'Check that your server answers quickly and that loopback requests aren\'t blocked, then run the scan again.', 'vireo-analytics' )
		);
	}

	/**
	 * @param array<string,mixed> $facts
	 */
	private function identity_checks( array $facts, callable $add ): void {
		$org       = ( new AI_Scan_Page_Parser() )->find_org( (array) $facts['json_ld'] );
		$micro_org = (bool) array_filter( (array) $facts['microdata'], [ AI_Scan_Page_Parser::class, 'is_org_type' ] );
		$same_as   = null === $org ? 0 : count( array_filter( (array) ( $org['sameAs'] ?? [] ) ) );

		if ( null !== $org ) {
			$type    = (array) ( $org['@type'] ?? 'Organization' );
			$summary = $same_as
				? sprintf(
					/* translators: 1: schema type, 2: number of linked profiles */
					_n( '%1$s markup found with %2$d linked profile.', '%1$s markup found with %2$d linked profiles.', $same_as, 'vireo-analytics' ),
					(string) reset( $type ),
					$same_as
				)
				: sprintf(
					/* translators: %s: schema type */
					__( '%s markup found, but no sameAs links to your other profiles.', 'vireo-analytics' ),
					(string) reset( $type )
				);
		} elseif ( $micro_org ) {
			$summary = __( 'Organization found only as microdata, not JSON-LD.', 'vireo-analytics' );
		} else {
			$summary = __( 'No Organization or LocalBusiness JSON-LD.', 'vireo-analytics' );
		}
		if ( (int) $facts['json_ld_errors'] > 0 ) {
			$summary .= ' ' . sprintf(
				/* translators: %d: count */
				_n( '%d JSON-LD block failed to parse.', '%d JSON-LD blocks failed to parse.', (int) $facts['json_ld_errors'], 'vireo-analytics' ),
				(int) $facts['json_ld_errors']
			);
		}
		$add(
			'org-schema',
			'structure',
			3,
			__( 'Business identity markup', 'vireo-analytics' ),
			null !== $org ? ( $same_as ? 'pass' : 'warn' ) : ( $micro_org ? 'warn' : 'fail' ),
			$summary,
			null !== $org
				? __( 'Add sameAs links to your social profiles, Google Business Profile and directory listings. They tie your site to the same business elsewhere, which is how AI tools confirm who you are.', 'vireo-analytics' )
				: __( 'Add Organization or LocalBusiness JSON-LD with your name, logo, address, phone and sameAs links. Most SEO plugins can output it.', 'vireo-analytics' )
		);

		$contact = (int) $facts['tel_links'] > 0 || (int) $facts['mailto_links'] > 0
			|| ( null !== $org && ( ! empty( $org['telephone'] ) || ! empty( $org['address'] ) || ! empty( $org['email'] ) ) );
		$add(
			'contact',
			'structure',
			2,
			__( 'Contact details are machine-readable', 'vireo-analytics' ),
			$contact ? 'pass' : 'warn',
			$contact
				? __( 'Phone, email or address found as links or markup.', 'vireo-analytics' )
				: __( 'No tel: or mailto: links and no address in the markup.', 'vireo-analytics' ),
			__( 'Link your phone number with tel: and your email with mailto:, and add your address to your Organization markup, so an AI tool can tell someone how to reach you.', 'vireo-analytics' )
		);

		$og       = (array) $facts['og'];
		$og_count = count( array_intersect_key( $og, array_flip( [ 'og:title', 'og:description', 'og:image' ] ) ) );
		$add(
			'og',
			'structure',
			1,
			__( 'Open Graph tags', 'vireo-analytics' ),
			3 === $og_count ? 'pass' : ( $og_count ? 'warn' : 'fail' ),
			sprintf(
				/* translators: %d: count */
				__( '%d of 3 core Open Graph tags (title, description, image).', 'vireo-analytics' ),
				$og_count
			),
			__( 'Add og:title, og:description and og:image. Most SEO plugins output them. Chat apps and AI tools use them for link previews and summaries.', 'vireo-analytics' )
		);

		$canonical = (string) $facts['canonical'];
		$add(
			'canonical',
			'structure',
			1,
			__( 'Canonical URL', 'vireo-analytics' ),
			'' !== $canonical ? 'pass' : 'warn',
			'' !== $canonical
				/* translators: %s: URL */
				? sprintf( __( 'Points to %s', 'vireo-analytics' ), $this->clip( $canonical, 100 ) )
				: __( 'No canonical link.', 'vireo-analytics' ),
			__( 'Add <link rel="canonical"> so duplicate versions of the page consolidate into one.', 'vireo-analytics' )
		);
	}

	/**
	 * @param array<int,array{token:string,label:string}> $agents
	 */
	private function agent_list( array $agents ): string {
		return implode(
			', ',
			array_map(
				static function ( array $a ): string {
					return $a['token'] . ' (' . $a['label'] . ')';
				},
				$agents
			)
		);
	}

	private function clip( string $text, int $max ): string {
		return mb_strlen( $text ) > $max ? rtrim( mb_substr( $text, 0, $max ) ) . '...' : $text;
	}
}
