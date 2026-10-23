<?php
/**
 * Thrown when a robots.txt pattern would take too many steps to evaluate.
 */

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

final class AI_Scan_Robots_Too_Complex extends \RuntimeException {}
