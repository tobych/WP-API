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
	public function __construct(WP_JSON_ResponseHandler $server) {
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

		$args = array('orderby' => 'user_login', 'order' => 'ASC');
                $user_query = new WP_User_Query($args);
                $struct = array();
                if (!empty($user_query->results)) {
                  foreach ( $user_query->results as $user ) {
                    $struct[] = $this->prepare_user($user, $context);
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

        protected function prepare_user($user, $context = 'view') {
		  // We're ignoring $fields for now, so you get all these fields
		  $user_fields = array(
		       'ID' => $user->ID,
		       'login' => $user->user_login,
		       'pass' => $user->user_pass, // Is this plaintext?
		       'nicename' => $user->user_nicename,
		       'email' => $user->user_email,
		       'url' => $user->user_url,
		       'registered' => $user->user_registered,
		       'display_name' => $user->display_name,
		       'first_name' => $user->first_name,
		       'last_name' => $user->last_name,
		       'nickname' => $user->nickname,
		       'description' => $user->description,
		  );
		  return $user_fields;
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
		$user = get_userdata( $id );  // returns False on failure

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
		do_action( 'json_insert_user', $user, $data, true );  // $update is always true

		return $this->get_user( $id );
	}

	// I don't like the insert_post method this is based on;
	// doing things my way; can refactor later; needs discussion;
	// this can be used when creating users too, with defaults already
	// in place.
	// user is a WP_User; $data is an array of fields to update
	protected function update_user( $user, $data ) {

		  // Won't let them update these fields: ID, login, pass, registered,
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

		  if ( ! empty( $data['nicename'] ) ) {
			$user->user_nicename = $data['nicename'];
		  }
		  if ( ! empty( $data['email'] ) ) {
			$user->user_email = $data['email'];
		  }
		  if ( ! empty( $data['url'] ) ) {
			$user->user_url = $data['url'];
		  }
		  if ( ! empty( $data['display_name'] ) ) {
			$user->display_name = $data['display_name'];
		  }
		  if ( ! empty( $data['first_name'] ) ) {
			$user->first_name = $data['first_name'];
		  }
		  if ( ! empty( $data['last_name'] ) ) {
			$user->last_name = $data['last_name'];
		  }
		  if ( ! empty( $data['nickname'] ) ) {
			$user->nickname = $data['nickname'];
		  }
		  if ( ! empty( $data['description'] ) ) {
			$user->description = $data['description'];
		  }

	}

}
