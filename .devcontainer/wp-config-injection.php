<?php  // phpcs:ignore
// phpcs:disable Squiz.Commenting, WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.PHP.DevelopmentFunctions, WordPress.NamingConventions, WordPress.Security.ValidatedSanitizedInput

/**
 * Log exceptions.
 */
set_exception_handler( '\error_log' );

$GLOBALS['wp_filter']['is_wp_error_instance'][10][] = array(
	'accepted_args' => 1,
	'function'      => function ( $wp_error ) {
		if ( ! $wp_error->has_errors() ) {
			return;
		}

		$result = '`is_wp_error` detected an instance of `WP_Error` class.';

		foreach ( $wp_error->get_error_codes() as $index => $code ) {
			$result .= PHP_EOL . sprintf(
				'    #%d %s: %s',
				$index + 1,
				$code,
				$wp_error->get_error_message( $code ),
			);

			$error_data = $wp_error->get_error_data( $code );
			if ( ! empty( $error_data ) ) {
				$result .= PHP_EOL . sprintf(
					'        Data: %s',
					print_r( $error_data, true ),
				);
			}
		}

		error_log( $result );
	},
);


/**
 * Define other constants.
 */
foreach ( $_ENV as $key => $value ) {
	if ( strpos( $key, 'PHPINI_' ) === 0 ) {
		$key = substr( $key, strlen( 'PHPINI_' ) );
		ini_set( $key, $value );  // phpcs:ignore WordPress.PHP.IniSet.Risky

	} elseif ( strpos( $key, 'WORDPRESS_CONST_' ) === 0 ) {
		$key = substr( $key, strlen( 'WORDPRESS_CONST_' ) );
		defined( $key ) || define( $key, $value );
	}
}


/**
 * Xdebug via WP-Cron.
 */
$GLOBALS['wp_filter']['cron_request'][10][] = array(
	'accepted_args' => 1,
	'function'      => function ( $args ) {
		$args['url'] = add_query_arg( 'XDEBUG_SESSION_START', ini_get( 'xdebug.idekey' ) ?: 'VSCODE', $args['url'] );  // phpcs:ignore Universal.Operators.DisallowShortTernary.Found

		return $args;
	},
);


/**
 * SMTP settings.
 */
$GLOBALS['wp_filter']['phpmailer_init'][10][] = array(
	'accepted_args' => 1,
	'function'      => function ( $phpmailer ) {
		$phpmailer->Host = 'mailhog';
		$phpmailer->Port = 1025;
		$phpmailer->IsSMTP();
	},
);
$GLOBALS['wp_filter']['wp_mail_from'][10][]   = array(
	'accepted_args' => 0,
	'function'      => fn () => 'noreply@wordpress.local',
);


/**
 * Spoofing host and port for WordPress environment.
 * To use this, include from your "wp-config.php" file.
 * Note that this functions is not intended to be used in production.
 *
 * @see \is_ssl()
 * @link https://ngrok.com/docs/using-ngrok-with/wordpress/
 */
( function () {
	if ( ! empty( $_SERVER['HTTP_HOST'] ) ) {
		$host   = $_SERVER['HTTP_HOST'];
		$scheme = ! empty( $_SERVER['HTTPS'] ) ? 'https' : ( $_SERVER['REQUEST_SCHEME'] ?? 'http' );

		if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) || isset( $_SERVER['HTTP_X_SCHEME'] ) ) {
			// reverse proxy environment
			$scheme = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? $_SERVER['HTTP_X_SCHEME'];

			if ( 'https' === $scheme && 'http' === $_SERVER['REQUEST_SCHEME'] ) {
				// faking https environment for WordPress generated URLs
				defined( 'FORCE_SSL_ADMIN' ) || define( 'FORCE_SSL_ADMIN', false );
				$_SERVER['HTTPS'] = 'on';
			}
		} elseif (
			! empty( $_SERVER['SERVER_PORT'] )
			&&
			( 'https' === $scheme ? 443 : 80 ) !== (int) $_SERVER['SERVER_PORT']
			&&
			! str_contains( $host, ':' )
		) {
			// non-standard port
			$host .= ':' . $_SERVER['SERVER_PORT'];
		}

		defined( 'WP_SITEURL' ) || define( 'WP_SITEURL', $scheme . '://' . $host );
		defined( 'WP_HOME' ) || define( 'WP_HOME', WP_SITEURL );
		defined( 'FORCE_SSL_ADMIN' ) || define( 'FORCE_SSL_ADMIN', 'https' === $scheme );
		defined( 'COOKIE_DOMAIN' ) || define( 'COOKIE_DOMAIN', preg_replace( '/:\d+$/', '', $host ) );
		defined( 'SITECOOKIEPATH' ) || define( 'SITECOOKIEPATH', '.' );
	}
} )();


/**
 * Add `phpinfo()` at site-health.php
 */
$GLOBALS['wp_filter']['site_health_navigation_tabs'][10][] = array(
	'accepted_args' => 1,
	'function'      => function ( $tabs ) {
		$tabs['phpinfo'] = 'phpinfo()';
		return $tabs;
	},
);
$GLOBALS['wp_filter']['site_health_tab_content'][10][]     = array(
	'accepted_args' => 1,
	'function'      => function ( $tab ) {
		if ( 'phpinfo' === $tab ) {
			ob_start();
			phpinfo();
			$phpinfo = preg_replace( '%^.*<body>(.*)</body>.*$%ms', '$1', ob_get_clean() );

			?>
				<div class="health-check-body">
					<?php echo $phpinfo;  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			<?php

			return;
		}
	},
);


require_once __DIR__ . '/wpcli-util.php';
