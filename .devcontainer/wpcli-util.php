<?php
/**
 * Import/Export function for WP-CLI
 *
 * @link https://github.com/wp-cli/role-command
 * @link https://github.com/wp-cli/entity-command
 */
if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command(
		'role import',
		function ( $args ) {
			foreach ( $args as $filename ) {
				if ( ! file_exists( $filename ) ) {
					WP_CLI::error( "File not found: $filename" );
				}

				WP_CLI::log( "Processing file: $filename" );

				$roles = json_decode( file_get_contents( $filename ), true, 512, JSON_THROW_ON_ERROR );  // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

				if ( json_last_error() !== JSON_ERROR_NONE ) {
					WP_CLI::error( 'Failed to parse JSON: ' . json_last_error_msg() );
				}

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

						foreach ( $data['capabilities'] ?? array() as $cap => $grant ) {
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

						add_role( $role_name, $data['name'] ?? $role_name, $data['capabilities'] ?? array() ) && WP_CLI::success( "Created role: $role_name" );
					}
				}
			}
		}
	);


	WP_CLI::add_command(
		'role export',
		function () {
			echo json_encode( $GLOBALS['wp_roles']->roles, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );  // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		}
	);


	WP_CLI::add_command(
		'user import',
		function ( $args, $assoc_args ) {
			foreach ( $args as $filename ) {
				if ( file_exists( $filename ) ) {
					$blob = file_get_contents( $filename );  // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

				} elseif ( '-' === $filename ) {
					$blob = stream_get_contents( STDIN );

				} else {
					WP_CLI::error( "File not found: $filename" );
				}

				WP_CLI::log( "Processing file: $filename" );

				$users = json_decode( $blob, true, 512, JSON_THROW_ON_ERROR );

				if ( json_last_error() !== JSON_ERROR_NONE ) {
					WP_CLI::error( 'Failed to parse JSON: ' . json_last_error_msg() );
				}

				foreach ( $users as $userdata ) {
					$user_id = username_exists( $userdata['user_login'] ) ?: email_exists( $userdata['user_email'] );  // phpcs:ignore Universal.Operators.DisallowShortTernary.Found

					if ( $user_id && empty( $assoc_args['force'] ) ) {
						$user = get_user_by( 'ID', $user_id );

						WP_CLI::warning( "User already exists: {$user->user_login} ({$user->user_email})" );

						continue;
					}

					// Check roles before creating user.
					if ( ! empty( $userdata['roles'] ) ) {
						foreach ( $userdata['roles'] as $role ) {
							if ( ! get_role( $role ) ) {
								WP_CLI::error( "Role does not exist: $role" );
							}
						}
					}

					$data = array_diff_key( $userdata, array_flip( array( 'ID', 'roles', 'caps', 'meta' ) ) );

					if ( ! $user_id ) {
						if ( empty( $data['user_pass'] ) ) {
							$data['user_pass'] = uniqid();
							WP_CLI::warning( 'User password is empty, generating random password: ' . $data['user_pass'] );
						}

						$user_id = wp_insert_user( $data );

						if ( is_wp_error( $user_id ) ) {
							WP_CLI::error( 'Failed to create user: ' . $user_id->get_error_message() );
						}

						$user = get_user_by( 'ID', $user_id );

						WP_CLI::success( "Created user: {$user->user_login} ({$user->user_email})" );

					} else {
						$data['ID'] = $user_id;

						$user_id = wp_update_user( $data );

						if ( is_wp_error( $user_id ) ) {
							WP_CLI::error( 'Failed to update user: ' . $user_id->get_error_message() );
						}

						$user = get_user_by( 'ID', $user_id );

						WP_CLI::success( "Updated user: {$user->user_login} ({$user->user_email})" );
					}

					if ( ! empty( $userdata['roles'] ) ) {
						$first = true;

						foreach ( $userdata['roles'] as $role ) {
							if ( $first ) {
								$user->set_role( $role );
								$first = false;
							} else {
								$user->add_role( $role );
							}
						}

						WP_CLI::success( 'Set role: ' . implode( ', ', $userdata['roles'] ) );
					}

					if ( ! empty( $userdata['caps'] ) ) {
						$msg = 'Set caps: ';

						foreach ( $userdata['caps'] as $cap => $grant ) {
							$user->add_cap( $cap, $grant );

							if ( $grant ) {
								$msg .= "+$cap ";
							} else {
								$msg .= "-$cap ";
							}
						}

						WP_CLI::success( $msg );
					}

					if ( ! empty( $userdata['meta'] ) ) {
						foreach ( $userdata['meta'] as $meta_key => $meta_value ) {
							update_user_meta( $user_id, $meta_key, $meta_value );
							WP_CLI::success( "Updated meta: $meta_key" );
						}
					}
				}
			}
		},
		// https://github.com/wp-cli/wp-cli/blob/f701f406aa39f6aeca2af16e92712780e2675bad/features/command.feature#L559
		array(
			'shortdesc' => 'Import users from a JSON file.',
			'synopsis'  => array(
				array(
					'name'        => 'file',
					'type'        => 'positional',
					'optional'    => false,
					'repeating'   => false,
					'description' => 'The JSON file to import. If you pass a hyphen (-) as the filename, data will be read from STDIN.',
				),
				array(
					'name'        => 'force',
					'type'        => 'flag',
					'optional'    => true,
					'description' => 'Update existing users.',
				),
			),
		)
	);


	WP_CLI::add_command(
		'user export',
		function ( $args, $assoc_args ) {
			$rows = array();

			foreach ( get_users() as $user ) {
				$row = array(
					'ID'              => $user->ID,
					'user_login'      => $user->user_login,
					'user_nicename'   => $user->user_nicename,
					'user_email'      => $user->user_email,
					'user_url'        => $user->user_url,
					'user_registered' => $user->user_registered,
					'user_status'     => $user->user_status,
					'display_name'    => $user->display_name,
					'roles'           => array_values( $user->roles ),
					'caps'            => $user->caps,
				);

				if ( ! empty( $assoc_args['meta'] ) ) {
					$row['meta'] = get_user_meta( $user->ID );
				}

				$rows[] = $row;
			}

			echo json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );  // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		},
		array(
			'shortdesc' => 'Export users to a JSON file.',
			'synopsis'  => array(
				array(
					'name'        => 'meta',
					'type'        => 'flag',
					'optional'    => true,
					'description' => 'Include user meta.',
				),
			),
		)
	);
}
