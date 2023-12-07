<?php
/**
 * Fetch emails sent by WordPress.
 * NOTE: THIS IS FOR TESTING PURPOSES ONLY.
 */

( function () {
	$add_action = $add_filter = function_exists( '\add_filter' ) ? '\add_filter' : function ( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['wp_filter'][ $hook_name ][ $priority ][] = [
			'accepted_args' => $accepted_args,
			'function'      => $callback,
		];
	};

	$handler = function (): void {
		switch ( $_REQUEST['op'] ?? '' ) {
			case 'start':
				update_option( 'mail_fetcher', true );
				update_option( 'mail_fetcher_data', [] );

				wp_send_json_success();
				break;

			case 'get':
				wp_send_json_success( get_option( 'mail_fetcher_data', [] ) );
				break;

			case 'match':
				$data = get_option( 'mail_fetcher_data', [] );

				$found = array_filter( $data, function ( $item ) {
					return (
						( isset( $_REQUEST['to'] ) && in_array( $_REQUEST['to'], $item['to'], true ) )
						||
						( isset( $_REQUEST['subject'] ) && str_contains( $item['subject'], $_REQUEST['subject'] ) )
						||
						( isset( $_REQUEST['message'] ) && str_contains( $item['message'], $_REQUEST['message'] ) )
					);
				} );

				wp_send_json( [ 'success' => ! empty( $found ), 'data' => $found ] );
				break;

			case 'preg_match':
				$data = get_option( 'mail_fetcher_data', [] );

				$found = array_filter( $data, function ( $item ) {
					return (
						( isset( $_REQUEST['to'] ) && ! empty( preg_grep( $_REQUEST['to'], $item['to'] ) ) )
						||
						( isset( $_REQUEST['subject'] ) && preg_match( $_REQUEST['subject'], $item['subject'] ) )
						||
						( isset( $_REQUEST['message'] ) && preg_match( $_REQUEST['message'], $item['message'] ) )
					);
				} );

				wp_send_json( [ 'success' => ! empty( $found ), 'data' => $found ] );
				break;

			case 'clear':
				$data = get_option( 'mail_fetcher_data', [] );

				update_option( 'mail_fetcher_data', [] );

				wp_send_json_success( $data );
				break;

			case 'end':
				update_option( 'mail_fetcher', false );

				$data = get_option( 'mail_fetcher_data', [] );

				update_option( 'mail_fetcher_data', [] );

				wp_send_json_success( $data );
				break;
		}
	};

	$add_action( 'wp_ajax_mail_fetcher', $handler );
	$add_action( 'wp_ajax_nopriv_mail_fetcher', $handler );


	$add_filter( 'wp_mail', function ( $atts ) {
		if ( get_option( 'mail_fetcher' ) ) {
			$data   = get_option( 'mail_fetcher_data', [] );
			$data[] = [
				'to'      => (array) $atts['to'],
				'subject' => $atts['subject'],
				'message' => $atts['message'],
				'headers' => (array) $atts['headers'],
				//'attachments' => (array) $atts['attachments'],
			];

			update_option( 'mail_fetcher_data', $data );
		}

		return $atts;
	}, PHP_INT_MIN );
} )();
