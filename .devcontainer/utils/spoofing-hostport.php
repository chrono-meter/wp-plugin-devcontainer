<?php
/**
 * Spoofing host and port for WordPress environment.
 * To use this, include from your "wp-config.php" file.
 * Note that this functions is not intended to be used in production.
 *
 * @see \is_ssl()
 * @link https://ngrok.com/docs/using-ngrok-with/wordpress/
 */


if ( ! empty( $host = @$_SERVER['HTTP_HOST'] ) ) {
	$scheme = ! empty( $_SERVER['HTTPS'] ) ? 'https' : ( $_SERVER['REQUEST_SCHEME'] ?? 'http' );

	if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) || isset( $_SERVER['HTTP_X_SCHEME'] ) ) {
		// reverse proxy environment
		$scheme = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? $_SERVER['HTTP_X_SCHEME'];

		if ( $scheme === 'https' && $_SERVER['REQUEST_SCHEME'] === 'http' ) {
			// faking https environment for WordPress generated URLs
			defined( 'FORCE_SSL_ADMIN' ) || define( 'FORCE_SSL_ADMIN', false );
			$_SERVER['HTTPS'] = 'on';
		}
	} elseif (
		@$_SERVER['SERVER_PORT'] != ( $scheme === 'https' ? 443 : 80 )
		&&
		! str_contains( $host, ':' )
	) {
		// non-standard port
		$host .= ':' . $_SERVER['SERVER_PORT'];
	}

	defined( 'WP_SITEURL' ) || define( 'WP_SITEURL', $scheme . '://' . $host );
	defined( 'WP_HOME' ) || define( 'WP_HOME', WP_SITEURL );
	defined( 'FORCE_SSL_ADMIN' ) || define( 'FORCE_SSL_ADMIN', $scheme === 'https' );
	defined( 'COOKIE_DOMAIN' ) || define( 'COOKIE_DOMAIN', preg_replace( '/:\d+$/', '', $host ) );
	defined( 'SITECOOKIEPATH' ) || define( 'SITECOOKIEPATH', '.' );

	unset( $host, $scheme );
}
