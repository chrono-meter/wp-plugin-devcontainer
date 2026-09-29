<?php
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.Security.ValidatedSanitizedInput

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

			if ( isset( $_SERVER['HTTP_CF_VISITOR'] ) ) {
				/**
				 * Cloudflare environment
				 *
				 * @link https://developers.cloudflare.com/fundamentals/reference/http-headers/#cf-visitor
				 */
				$cf_visitor = json_decode( $_SERVER['HTTP_CF_VISITOR'], true );
				if ( isset( $cf_visitor['scheme'] ) ) {
					$scheme = $cf_visitor['scheme'];
				}
			}

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

$GLOBALS['wp_filter']['cron_request'][10][] = array(
	'accepted_args' => 1,
	'function'      => function ( $args ) {
		if ( ! empty( $_SERVER['HTTP_HOST'] ) ) {
			// Parse url.
			$url_parts = wp_parse_url( $args['url'] );

			// Replace scheme and host by http://localhost.
			$url_parts['scheme'] = 'http';
			$url_parts['host']   = 'localhost';
			$url_parts['port']   = 80; // Default port for HTTP.

			// Rebuild URL.
			$args['url'] = ( function ( array $parts ) {
				return ( isset( $parts['scheme'] ) ? "{$parts['scheme']}:" : '' ) .
				( ( isset( $parts['user'] ) || isset( $parts['host'] ) ) ? '//' : '' ) .
				( isset( $parts['user'] ) ? "{$parts['user']}" : '' ) .
				( isset( $parts['pass'] ) ? ":{$parts['pass']}" : '' ) .
				( isset( $parts['user'] ) ? '@' : '' ) .
				( isset( $parts['host'] ) ? "{$parts['host']}" : '' ) .
				( isset( $parts['port'] ) ? ":{$parts['port']}" : '' ) .
				( isset( $parts['path'] ) ? "{$parts['path']}" : '' ) .
				( isset( $parts['query'] ) ? "?{$parts['query']}" : '' ) .
				( isset( $parts['fragment'] ) ? "#{$parts['fragment']}" : '' );
			} )( $url_parts );
		}

		return $args;
	},
);


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
		$phpmailer->Host = 'smtp';
		$phpmailer->Port = 1025;
		$phpmailer->IsSMTP();
	},
);
$GLOBALS['wp_filter']['wp_mail_from'][10][]   = array(
	'accepted_args' => 0,
	'function'      => fn () => 'noreply@wordpress.local',
);


/**
 * Disable SSL verification for local development.
 *
 * @since 7.0.0
 * @link https://core.trac.wordpress.org/changeset/62025/
 * @link https://core.trac.wordpress.org/ticket/64762
 * @link https://github.com/WordPress/wordpress-develop/pull/11255
 * @see \wp_admin_bar_add_color_scheme_to_front_end()
 */
$GLOBALS['wp_filter']['https_ssl_verify'][10][] = array(
	'accepted_args' => 0,
	'function'      => '__return_false',
);


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


/**
 * User Switching.
 *
 * # `user-switching` capabilities:
 *  * switch_to_user - Switch to the user with specified ID.
 *  * switch_users   - Switch to any user.
 *  * switch_off     - Temporary logout. The user can easily return to the original user account.
 *
 * @link https://github.com/johnbillion/user-switching/blob/develop/user-switching.php
 */
$GLOBALS['wp_filter']['admin_bar_menu'][100][] = array(
	'accepted_args' => 1,
	'function'      => function ( WP_Admin_Bar $wp_admin_bar ) {
		if (
			class_exists( 'user_switching', false )
			&&
			is_admin_bar_showing()
		) {
			$old_user = user_switching::get_old_user();

			if ( $old_user ) {
				$wp_admin_bar->add_menu(
					array(
						'id'     => 'switch_off',
						'parent' => false,
						'title'  => sprintf( '↩️ Revert to %s', esc_html( $old_user->display_name ) ),
						'href'   => user_switching::maybe_switch_url( $old_user ),
					)
				);
			} elseif ( current_user_can( 'list_users' ) ) {
				$switchable_users = array();

				// Enum all users.
				foreach ( get_users() as $user ) {
					$url = user_switching::maybe_switch_url( $user );
					if ( empty( $url ) ) {
						continue;
					}

					$switchable_users[] = array(
						'user' => $user,
						'url'  => $url,
					);
				}

				if ( ! empty( $switchable_users ) ) {
					$wp_admin_bar->add_menu(
						array(
							'id'     => 'switch_users',
							'parent' => false,
							'title'  => '🎭 Switch user',
							'href'   => '#',
							'meta'   => array(
								'onclick' => 'return false;',
							),
						)
					);

					foreach ( $switchable_users as $switchable_user ) {
						$wp_admin_bar->add_node(
							array(
								'id'     => 'switch_user_' . $switchable_user['user']->ID,
								'parent' => 'switch_users',
								'title'  => esc_html( sprintf( '%s <%s>', $switchable_user['user']->display_name, $switchable_user['user']->user_email ) ),
								'href'   => $switchable_user['url'],
							)
						);
					}
				}
			}
		}
	},
);


/**
 * Allow to any users to view Query Monitor.
 */
$GLOBALS['wp_filter']['user_has_cap'][10][] = array(
	'accepted_args' => 2,
	'function'      => function ( $result, array $caps ) {
		if ( isset( $caps[0] ) && 'view_query_monitor' === $caps[0] ) {
			$result['view_query_monitor'] = true;
		}

		return $result;
	},
);


/**
 * Prevent `query-monitor` problem.
 *
 * @link https://github.com/johnbillion/query-monitor/issues/916
 */
$GLOBALS['wp_filter']['lang_dir_for_domain'][10][] = array(
	'accepted_args' => 2,
	'function'      => function ( $path, $domain ) {
		if ( 'query-monitor' === $domain && ( ! doing_action( 'after_setup_theme' ) && ! did_action( 'after_setup_theme' ) ) ) {
			$path = false;
		}

		return $path;
	},
);


/**
 * Fix `upload_size_limit` value is not unlimited when `upload_max_filesize` or `post_max_size` is set to 0 (unlimited).
 */
// $GLOBALS['wp_filter']['upload_size_limit'][ PHP_INT_MIN ][] = array(
// 	'accepted_args' => 3,
// 	'function'      => function ( $result, $u_bytes, $p_bytes ) {
// 		if ( 0 === $u_bytes ) {
// 			$u_bytes = PHP_INT_MAX;
// 		}
// 		if ( 0 === $p_bytes ) {
// 			$p_bytes = PHP_INT_MAX;
// 		}
// 		if ( 0 === $result ) {
// 			$result = min( $u_bytes, $p_bytes );
// 		}

// 		return $result;
// 	},
// );
