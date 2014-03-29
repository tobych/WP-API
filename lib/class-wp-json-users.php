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

			)
		);
		return array_merge( $routes, $user_routes );
	}

	/**
	 * Retrieve posts.
	 *
	 * The optional $filter parameter modifies the query used to retrieve posts.
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

}
