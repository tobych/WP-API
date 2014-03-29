<?php

class WP_JSON_Users {
	/**
	 * Server object
	 *
	 * @var WP_JSON_ResponseHandler
	 */
	protected $server;

	/**
	 * Constructor
	 *
	 * @param WP_JSON_ResponseHandler $server Server object
	 */
	public function __construct( WP_JSON_ResponseHandler $server ) {
		$this->server = $server;
	}

	/**
	 * Register the user-related routes
	 *
	 * @param array $routes Existing routes
	 * @return array Modified routes
	 */
	public function registerRoutes( $routes ) {
		$user_routes = array(
			// User endpoints
			'/users' => array(
				array( array( $this, 'get_users' ), WP_JSON_Server::READABLE ),
			),
			'/users/(?P<id>\d+)' => array(
				array( array( $this, 'get_user' ), WP_JSON_Server::READABLE ),
				array( array( $this, 'edit_user' ), WP_JSON_Server::EDITABLE | WP_JSON_Server::ACCEPT_JSON ),
				array( array( $this, 'delete_user' ), WP_JSON_Server::DELETABLE ),
			)
		);
		return array_merge( $routes, $user_routes );
	}

	/**
	 * Retrieve users.
	 *
	 * The optional $filter parameter modifies the query used to retrieve users.
	 * Accepted keys are TBW. (not implemented)
	 *
	 * The optional $fields parameter specifies what fields will be included
	 * in the response array. (not implemented)
	 *
	 * @param array $filter optional
	 * @param array $fields optional
	 * @return array contains a collection of User entities.
	 */
	public function get_users( $filter = array(), $context = 'view', $type = 'user', $page = 1 ) {

		if ( ! current_user_can( 'edit_users' ) ) {
			return new WP_Error( 'json_cannot_get', __( 'Sorry, you are not allowed to get users.' ), array( 'status' => 401 ) );
		}

		$args = array( 'orderby' => 'user_login', 'order' => 'ASC' );
		$user_query = new WP_User_Query( $args );
		$struct = array();
		if ( ! empty( $user_query->results ) ) {
			foreach ( $user_query->results as $user ) {
				$struct[ ] = $this->prepare_user( $user, $context );
			}
		} else {
			return array();
		}
		return $struct;
	}

	/**
	 * Retrieve a user.
	 *
	 * @param int $id User ID
	 * @param array $fields User fields to return (optional; NOT IMPLEMENTED)
	 * @return array User entity
	 */
	public function get_user( $id, $context = 'view' ) {
		$id = (int) $id;

		if ( ! current_user_can( 'edit_users' ) ) {
			return new WP_Error( 'json_cannot_get', __( 'Sorry, you are not allowed to get users.' ), array( 'status' => 401 ) );
		}

		if ( empty( $id ) )
			return new WP_Error( 'json_user_invalid_id', __( 'Invalid user ID.' ), array( 'status' => 404 ) );

		// http://codex.wordpress.org/Function_Reference/get_userdata
		$user = get_userdata( $id );

		if ( empty( $user->ID ) )
			return new WP_Error( 'json_user_invalid_id', __( 'Invalid user ID.' ), array( 'status' => 404 ) );

		// Link headers (see RFC 5988)

		$response = new WP_JSON_Response();
		// user model doesn't appear to include a last-modified date
		// $response->header( 'Last-Modified', mysql2date( 'D, d M Y H:i:s', $user->TBW ) . 'GMT' );

		$user = $this->prepare_user( $user, $context );
		if ( is_wp_error( $user ) )
			return $user;

		$response->set_data( $user );
		return $response;
	}

	/**
	 * @param WP_User $user
	 * @param string $context
	 * @return array
	 */
	protected function prepare_user( $user, $context = 'view' ) {
		// We're ignoring $fields for now, so you get all these fields
		// http://codex.wordpress.org/Function_Reference/get_metadata
		// http://code.tutsplus.com/articles/mastering-wordpress-meta-data-understanding-and-using-arrays--wp-34596
		$user_fields = array(
			'ID' => $user->ID,
			'login' => $user->user_login,
			'pass' => $user->user_pass, // Is this plaintext?
			'nicename' => $user->user_nicename,
			'email' => $user->user_email,
			'url' => $user->user_url,
			'registered' => $user->user_registered,
			'display_name' => $user->display_name,
			'first_name' => $user->first_name,  // is also in meta
			'last_name' => $user->last_name,  // is also in meta
			'nickname' => $user->nickname,  // is also in meta
			'description' => $user->description,  // is also in meta
			'meta' => array(
				'links' => array(
					'self' => json_url( '/users/' . $user->ID ),
					'archives' => json_url( '/users/' . $user->ID . '/posts' ), // not implemented at time of writing
				),
			),
		);
		// https://codex.wordpress.org/Metadata_API
		// "Objects may contain multiple metadata entries that share the same key and differ only in their value."
		// JSON can have duplicate keys in an object: http://stackoverflow.com/a/19927061/76452
		// "The names within an object SHOULD be unique"... but clearly this is a bad idea.
		// So we can't just use a dictionary here...
		// Ah, I've read that "When you store multiple key/value pairs for a post, that key is turned into
		// an array." So I guess we're okay. I'll ignore this for now.
		// Wooah there's serialization too. My assumption is that serialized data is gonna be useless to users.
		// I'm using Python, for one thing. So we'll maybe_unserialize each metadata value.
		// Okay, so each metadata thing comes out as an array. I get it. Because there can be multiple values per key.
		// So we need to maybe_unserialize each member. But how to know which to serialize when we write back?
		// Maybe we need a separate entity for a serialized PHP structure. It can be a dictionary, with a special
		// key, 'unserialized'. We could then have a 'serialized' key too. When updating/creating, 'serialized' would
		// take precedence, unless there was an error.
		// TODO: consider representing single arrays just as the item (we can already cope with this coming back)
		// TODO: check-in with WP-API folks about true/false (in metadata)... I imagine we should just pass the
		// strings back, and for common fields just send nicer stuff back outside of the meta
		$user_meta = get_user_meta( $user->ID );

		// TODO: Don't use "meta" as it's used in the JSON response for eg links.self; use user_meta instead
		// TODO: Add our own "meta" stuff with links.self
		$user_fields['user_meta'] = $this->prepare_meta( $user_meta );
		return $user_fields;
	}

	protected function prepare_meta( $meta ) {
		$prepared_meta = [];
		foreach ( $meta as $meta_key => $meta_values ) {
			$prepared_meta[$meta_key] = [];
			foreach ( $meta_values as $meta_value ) {
				if ( is_serialized( $meta_value ) ) {
					$prepared_meta_value = [
						'unserialized' => @unserialize( $meta_value ),
						'serialized' => $meta_value,  // for completeness, testing, debugging
					];
			    } else {
					$prepared_meta_value = $meta_value;
				}
				$prepared_meta[$meta_key][] = $prepared_meta_value;
			}
		}
		return $prepared_meta;
	}

	/**
	 * Delete a user
	 *
	 * @param int $id
	 * @return true on success
	 */
	public function delete_user( $id, $force = false ) {
		$id = (int) $id;

		if ( empty( $id ) )
			return new WP_Error( 'json_user_invalid_id', __( 'Invalid user ID.' ), array( 'status' => 404 ) );

		// Permissions check
		if ( ! current_user_can( 'edit_users' ) ) {
			return new WP_Error( 'json_cannot_edit', __( 'Sorry, you are not allowed to edit this user.' ), array( 'status' => 401 ) );
		}

		$user = get_userdata( $id );

		if ( ! $user )
			return new WP_Error( 'json_user_invalid_id', __( 'Invalid user ID.' ), array( 'status' => 404 ) );

		// https://codex.wordpress.org/Function_Reference/wp_delete_user
		// TODO: Allow posts to be reassigned (see the docs for wp_delete_user) - use a HTTP parameter?
		$result = wp_delete_user( $id );

		if ( ! $result )
			return new WP_Error( 'json_cannot_delete', __( 'The user cannot be deleted.' ), array( 'status' => 500 ) );

	}

	/**
	 * Edit a user.
	 *
	 * The $data parameter only needs to contain fields that should be changed.
	 * All other fields will retain their existing values.
	 *
	 * @param int $id User ID to edit
	 * @param array $data Data construct
	 * @param array $_headers Header data
	 * @return true on success
	 */
	function edit_user( $id, $data, $_headers = array() ) {
		$id = (int) $id;

		if ( empty( $id ) )
			return new WP_Error( 'json_user_invalid_id', __( 'Invalid user ID (EMPTY).' ), array( 'status' => 404 ) );

		// http://codex.wordpress.org/Function_Reference/get_userdata
		$user = get_userdata( $id ); // returns False on failure

		if ( ! $user )
			return new WP_Error( 'json_user_invalid_id', __( 'Invalid user ID (COULD NOT LOAD).' ), array( 'status' => 404 ) );

		// Permissions check
		if ( ! current_user_can( 'edit_users' ) ) {
			return new WP_Error( 'json_cannot_edit', __( 'Sorry, you are not allowed to edit this user.' ), array( 'status' => 401 ) );
		}

		// Update attributes of the user from $data
		$retval = $this->update_user( $user, $data );
		if ( is_wp_error( $retval ) ) {
			return $retval;
		}

		// TBD Pre-insert/update hook (I don't understand what one of those is yet)

		// Update the user in the database
		// http://codex.wordpress.org/Function_Reference/wp_update_user
		$retval = wp_update_user( $user );
		if ( is_wp_error( $retval ) ) {
			return $retval;
		}

		// http://codex.wordpress.org/Function_Reference/do_action
		do_action( 'json_insert_user', $user, $data, true ); // $update is always true

		return $this->get_user( $id );
	}

	// I don't like the insert_post method this is based on;
	// doing things my way; can refactor later; needs discussion;
	// this can be used when creating users too, with defaults already
	// in place.
	// user is a WP_User; $data is an array of fields to update
	protected function update_user( $user, $data ) {

		// Won't let them update these fields: ID, login, pass, registered (silently ignored)
		// TODO: Raise an exception if they try to update those. Ignore ID though.
		// WP won't let you change login (username)
		// Note that you can pass wp_update_user() an array of fields to
		// update; they're not the same as being used here (and probably
		// in the existing WP-API User entity definition). Won't bother
		// using that capability here either way, for now.
		// https://github.com/WP-API/WP-API/blob/master/docs/schema.md#user
		// That uses ID, name (=display_name? user_nicename?), slug, URL, avatar, meta
		// (where are these in WP_User?)
		// http://codex.wordpress.org/Class_Reference/WP_User
		// http://wpsmith.net/2012/wp/an-introduction-to-wp_user-class/
		// There's tonnes more stuff in WP_User to work with. This is a start.

		if ( ! empty( $data[ 'nicename' ] ) ) {
			$user->user_nicename = $data[ 'nicename' ];
		}
		if ( ! empty( $data[ 'email' ] ) ) {
			$user->user_email = $data[ 'email' ];
		}
		if ( ! empty( $data[ 'url' ] ) ) {
			$user->user_url = $data[ 'url' ];
		}
		if ( ! empty( $data[ 'display_name' ] ) ) {
			$user->display_name = $data[ 'display_name' ];
		}
		if ( ! empty( $data[ 'first_name' ] ) ) {
			$user->first_name = $data[ 'first_name' ];
		}
		if ( ! empty( $data[ 'last_name' ] ) ) {
			$user->last_name = $data[ 'last_name' ];
		}
		if ( ! empty( $data[ 'nickname' ] ) ) {
			$user->nickname = $data[ 'nickname' ];
		}
		if ( ! empty( $data[ 'description' ] ) ) {
			$user->description = $data[ 'description' ];
		}

		if ( ! empty( $data['user_meta'] ) ) {
			// https://codex.wordpress.org/Function_Reference/update_metadata
			// https://codex.wordpress.org/Function_Reference/update_user_meta
			// "update_user_meta does not delete the meta if the new value is empty"
			// TODO: Do something about that. Maybe treat each metadata item as a separate resource?
			foreach ( $data['user_meta'] as $meta_key => $meta_value_or_values ) {
				// "The new desired value of the meta_key, which must be different from the existing value.
				// Arrays and objects will be automatically serialized"
				// Really? It MUST be different? Actually, it just returns false. And we don't care.
				// TODO: Something's wrong here. Eg we get wp_user_level back as u'wp_user_level': [u'0']
				// but if we send that, it gets serialized as "[u'a:1:{i:0;s:1:"0";}'],"...
				// need to test round-trip metadata properly... we need to unserialize stuff... is WP-API doing this?
				// OMG this is a whole issue: http://wpgarage.com/tips/data-portability-and-data-serialization-in-wordpress/
				// s2member does this, for one thing.
				// I guess we might need to use some heuristic: if the existing value is serialized, serialize before updating
				// https://codex.wordpress.org/Function_Reference/maybe_unserialize
				// TODO: Error out here if $meta_values is not an array (and pass that back up to the server)
				// I don't get it. You get an array from get_user_meta, but if you provide one, it serializes it. Uh?

				// This is so users can provide single values rather than an array
				if ( ! is_array($meta_value_or_values)) {
					$meta_values = array( $meta_value_or_values );
				} else {
					$meta_values = $meta_value_or_values;
				}

				foreach ( $meta_values as $meta_value) {
					// We're letting update_user_meta deal with multiple values... I can't see how to get it to
					// accept multiple values (from an array) without ending up serializing it.
					// TODO: Fix that
					if ( is_array( $meta_value ) ) {
						$unprepared_meta_value = $meta_value['unserialized']; // ignore any 'serialized'
						update_user_meta( $user->ID, $meta_key, $unprepared_meta_value );
					} else {
						$unprepared_meta_value = $meta_value;
						update_user_meta( $user->ID, $meta_key, $unprepared_meta_value );
					}
				}
				// update_user_meta( $user->ID, $meta_key, $unprepared_meta_values );
			}

		}
	}

}
