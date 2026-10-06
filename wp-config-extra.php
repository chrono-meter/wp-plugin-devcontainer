<?php
/**
 * Please refer to this page for available settings.
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/
 * @link https://developer.wordpress.org/reference/functions/wp_initial_constants/
 */

// Enable `display_errors` to trap early error. https://www.php.net/manual/en/errorfunc.configuration.php#ini.display-errors
ini_set( 'display_errors', 1 );

// set_exception_handler( '\error_log' );

require_once __DIR__ . '/wp-config-injection.php';
require_once __DIR__ . '/wpcli-util.php';

// Enable unlimited upload size.
ini_set( 'upload_max_filesize', PHP_INT_MAX );
ini_set( 'post_max_size', PHP_INT_MAX );

/**
 * Enable debug features.
 *
 * @link https://developer.wordpress.org/advanced-administration/debug/debug-wordpress/
 */
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );  // Enable Debug logging to the /wp-content/debug.log file
define( 'WP_DEBUG_DISPLAY', true );  // Control whether debug messages are shown inside the HTML of pages or not
// define( 'SCRIPT_DEBUG', true );  // Use dev versions of core JS and CSS files (only needed if you are modifying these core files)
define( 'SAVEQUERIES', true );

/**
 * `wp_debug_mode()` executes `error_reporting( E_ALL );` if `WP_DEBUG`. Reset `error_reporting()` using the nearest filter.
 *
 * @link https://developer.wordpress.org/reference/functions/wp_debug_mode/
 */
$GLOBALS['wp_filter']['enable_loading_object_cache_dropin'][ PHP_INT_MIN ][] = array(
	'accepted_args' => 1,
	'function'      => function ( $result ) {
		// Show errors, but suppress deprecation and notice to reduce noise. Even well-known plugins issue deprecation warnings. https://www.php.net/manual/en/errorfunc.configuration.php#ini.error-reporting
		error_reporting( E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR );

		return $result;
	},
);

// https://developer.wordpress.org/reference/functions/wp_is_fatal_error_handler_enabled/
// define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true );
// https://make.wordpress.org/core/2023/07/14/configuring-development-mode-in-6-3/
define( 'WP_DEVELOPMENT_MODE', 'all' );  // core, plugin, theme, all
// https://make.wordpress.org/core/2020/08/27/wordpress-environment-types/
define( 'WP_ENVIRONMENT_TYPE', 'local' );  // production (default), staging, development, local
// https://developer.wordpress.org/advanced-administration/upgrade/upgrading/#constant-to-disable-all-updates
define( 'AUTOMATIC_UPDATER_DISABLED', true );
// https://developer.wordpress.org/advanced-administration/upgrade/upgrading/#constant-to-configure-core-updates
// define( 'WP_AUTO_UPDATE_CORE', 'minor' );  // true | false | 'minor
// Not well documented. https://wordpress.org/search/CORE_UPGRADE_SKIP_NEW_BUNDLED/
define( 'CORE_UPGRADE_SKIP_NEW_BUNDLED', true );

// Disable to call `spawn_cron()` at "init" action. Crons would be processed via external triggers.
define( 'DISABLE_WP_CRON', true );

// https://github.com/johnbillion/query-monitor/blob/0741b15ea0bc05dc9b6fd71af246cf83cbc45f33/collectors/php_errors.php#L75
define( 'QM_DISABLE_ERROR_HANDLER', true );
define( 'QM_ENABLE_CAPS_PANEL', true );
// define( 'QM_DARK_MODE', true );

// Common workaround for PHP version exposed to the public.
header_remove( 'X-Powered-By' );
