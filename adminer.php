<?php
if ( ! count( $_GET ) ) {
	require_once __DIR__ . '/wp-load.php';

	if ( current_user_can( 'manage_options' ) ) {
		$_POST['auth'] = array(
			'driver'   => 'server',
			'server'   => $_ENV['WORDPRESS_DB_HOST'] ?? 'db',
			'username' => $_ENV['WORDPRESS_DB_USER'] ?? 'wordpress',
			'password' => $_ENV['WORDPRESS_DB_PASSWORD'] ?? 'wordpress',
			'db'       => $_ENV['WORDPRESS_DB_NAME'] ?? 'wordpress',
		);
	}
}
require_once __DIR__ . '/adminer-mysql.php';
