<?php
/**
 * Prevent email sending in WordPress.
 */


if ( function_exists( 'add_filter' ) ) {
	add_filter( 'pre_wp_mail', '__return_true' );

} else {
	$GLOBALS['wp_filter'] = array_replace_recursive( $GLOBALS['wp_filter'] ?? [], array(
		'pre_wp_mail' => [
			10 => [
				[
					'accepted_args' => 0,
					'function'      => '__return_true',
				],
			],
		],
	) );
}
