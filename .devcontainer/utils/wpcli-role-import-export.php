<?php
/**
 * Role Import/Export function for WP-CLI
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'role import', function( $args ) {
		foreach ( $args as $filename ) {
			if ( ! file_exists( $filename ) ) {
				WP_CLI::error( "File not found: $filename" );
			}

			WP_CLI::log( "Processing file: $filename" );

			$roles = json_decode( file_get_contents( $filename ), true, 512, JSON_THROW_ON_ERROR );

			foreach ( $roles as $role_name => $data ) {
				if ( $role = get_role( $role_name ) ) {
					WP_CLI::log( "Processing existing role: $role_name" );
	
					if ( ! empty( $data['name'] ) ) {
						/**
						 * WordPress core API doesn't support renaming role name.
						 *
						 * @see \WP_Roles::add_role()
						 */
						global $wp_roles;
	
						$wp_roles->roles[ $role_name ]['name'] = $data['name'];
	
						if ( $wp_roles->use_db ) {
							update_option( $wp_roles->role_key, $wp_roles->roles );
						}
	
						$wp_roles->role_names[ $role_name ] = $data['name'];
					}
	
					$msg = "Capabilities for role $role_name, ";
					foreach ( $data['capabilities'] ?? [] as $cap => $grant ) {
						$role->add_cap( $cap, $grant );
	
						if ( $grant ) {
							$msg .= "+$cap ";
						} else {
							$msg .= "-$cap ";
						}
					}
					WP_CLI::success( $msg );
	
				} else {
					WP_CLI::log( "Processing role: $role_name" );
	
					add_role( $role_name, $data['name'] ?? $role_name, $data['capabilities'] ?? [] ) && WP_CLI::success( "Created role: $role_name" );
				}
			}
	
		}
	} );
	WP_CLI::add_command( 'role export', function() {
		echo json_encode( $GLOBALS['wp_roles']->roles, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
	} );
}
