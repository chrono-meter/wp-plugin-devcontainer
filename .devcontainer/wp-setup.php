<?php
/**
 * WordPress plugin UI test entrypoint for docker.
 *
 * @package devcontainer-wordpress-plugin
 * @link https://github.com/docker-library/wordpress/blob/master/Dockerfile.template
 * @link https://github.com/docker-library/wordpress/blob/master/docker-entrypoint.sh
 */


/**
 * Transparent database connection retrying
 *
 * NOTE: If you execute PHP code in bash terminal, escape $ with backslash like "\$".
 *
 * @param mixed ...$args The arguments to pass to the WP CLI command.
 * @return int The result code of the WP CLI command.
 *
 * @see \dead_db(), \wpdb::db_connect(), \require_wp_db(), wp-settings.php, wp-config.php, wp-load.php
 * @link https://github.com/wp-cli/wp-cli/blob/main/php/wp-settings-cli.php#L131
 * @link https://github.com/wp-cli/wp-cli/blob/ee2be5aed2abe6d251b152fe61533e40cc45606a/php/utils-wp.php#L12
 */
function wp_cli( ...$args ): int {
	while ( 1 ) {
		$process = proc_open(
			array(
				'/usr/local/bin/wp',
				'--path=/var/www/html',
				'--allow-root',
				'--exec=WP_CLI::add_wp_hook("wp_die_handler", fn() => function($message, $title, $args){
					if (is_wp_error($message) && $message->get_error_code() === "db_connect_fail") {
						//fwrite(STDERR, "\033[31m" . strip_tags($message->get_error_message()) . "\033[0m");
						WP_CLI::halt(107);
					}
					if (is_string($message) && str_contains($message, "Error establishing a database connection")) {
						//fwrite(STDERR, "\033[31m" . strip_tags($message) . "\033[0m");
						WP_CLI::halt(107);
					}
				}, 20);',
				...$args,
			),
			array(
				0 => array( 'pipe', 'r' ),
				1 => STDOUT,
				2 => STDERR,
			),
			$pipes
		);

		fclose( $pipes[0] );

		$result_code = proc_close( $process );

		if ( $result_code === 107 ) {
			print( "\033[36mIt seems failed to connect to MySQL. Retrying...\033[0m\n" );
			sleep( 3 );
			continue;
		}

		return $result_code;
	}
}


/**
 * If /var/www/wp-setup.complete exists, exit
 */
if ( file_exists( '/var/www/wp-setup.complete' ) ) {
	echo "\033[36mWordPress setup is already completed.\033[0m\n";
	exit( 0 );
}


/**
 * wp core is-installed || wp core install
 *
 * @link https://developer.wordpress.org/cli/commands/core/is-installed/
 * @link https://developer.wordpress.org/cli/commands/core/install/
 */
if ( wp_cli( 'core', 'is-installed' ) !== 0 ) {
	if ( wp_cli(
		'core',
		'install',
		// https://github.com/wp-cli/core-command/blob/7a81a8658620078bf5f2785836cb33aa382e8bb4/src/Core_Command.php#L650
		// https://github.com/wp-cli/wp-cli/blob/ee2be5aed2abe6d251b152fe61533e40cc45606a/php/class-wp-cli.php#L118
		'--url=' . ( $_ENV['SITE_URL'] ?? 'http://localhost' ),
		'--title=' . ( $_ENV['WORDPRESS_INSTALL_TITLE'] ?? 'WordPress in docker' ),
		'--admin_user=' . ( $_ENV['WORDPRESS_INSTALL_ADMIN_USER'] ?? 'admin' ),
		'--admin_password=' . ( $_ENV['WORDPRESS_INSTALL_ADMIN_PASSWORD'] ?? 'password' ),
		'--admin_email=' . ( $_ENV['WORDPRESS_INSTALL_ADMIN_EMAIL'] ?? 'admin@wordpress.local' ),
		'--locale=' . ( $_ENV['WORDPRESS_LOCALE'] ?? 'en_US' ),
		'--skip-email',
	) !== 0 ) {
		echo "\033[31mFailed to install WordPress.\033[0m\n";
		exit( 1 );
	}
} else {
	echo "\033[36mWordPress is already installed.\033[0m\n";
}


/**
 * If WORDPRESS_SETUP_SCRIPT environment variable is set, execute it by bash.
 */
if ( isset( $_ENV['WORDPRESS_SETUP_SCRIPT'] ) ) {
	$setup_script = $_ENV['WORDPRESS_SETUP_SCRIPT'];

	if ( ! file_exists( $setup_script ) ) {
		echo "\033[31mFailed to execute setup script. File not found: $setup_script\033[0m\n";
		exit( 1 );
	}

	$process = proc_open(
		array(
			'bash',
			$setup_script,
		),
		array(
			0 => array( 'pipe', 'r' ),
			1 => STDOUT,
			2 => STDERR,
		),
		$pipes
	);
	fclose( $pipes[0] );
	$result_code = proc_close( $process );

	if ( $result_code !== 0 ) {
		echo "\033[31mFailed to execute setup script. Exit code: $result_code\033[0m\n";
		exit( 1 );
	}

	echo "\033[36mSetup script executed successfully.\033[0m\n";
}


/**
 * Create wp-setup.complete file for healthcheck.
 */
touch( '/var/www/wp-setup.complete' );
