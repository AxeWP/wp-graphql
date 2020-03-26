<?php

class CommentObjectQueriesTest extends \Codeception\TestCase\WPTestCase {

	public $current_time;
	public $current_date;
	public $current_date_gmt;
	public $admin;
	public $subscriber;

	public function setUp() {
		parent::setUp();

		$this->current_time     = strtotime( '- 1 day' );
		$this->current_date     = date( 'Y-m-d H:i:s', $this->current_time );
		$this->current_date_gmt = gmdate( 'Y-m-d H:i:s', $this->current_time );
		$this->admin            = $this->factory()->user->create( [
			'role' => 'administrator',
		] );
		$this->subscriber = $this->factory()->user->create( [
			'role' => 'subscriber',
		]);
	}

	public function tearDown() {
		parent::tearDown();
	}

	public function createCommentObject( $args = [] ) {

		$post_id = $this->factory()->post->create([
			'post_type' => 'post',
			'post_status' => 'publish',
			'post_title' => 'Post for commenting...'
		]);

		/**
		 * Set up the $defaults
		 */
		$defaults = [
			'comment_post_id' => $post_id,
			'comment_parent'   => 0,
			'comment_author'   => get_user_by( 'id', $this->admin )->user_email,
			'comment_content'  => 'Test comment content',
			'comment_approved' => 1,
			'comment_date'     => $this->current_date,
			'comment_date_gmt' => $this->current_date_gmt,
			'user_id'          => $this->admin,
		];

		/**
		 * Combine the defaults with the $args that were
		 * passed through
		 */
		$args = array_merge( $defaults, $args );

		/**
		 * Create the page
		 */
		$comment_id = $this->factory->comment->create( $args );

		/**
		 * Return the $id of the comment_object that was created
		 */
		return $comment_id;

	}

	/**
	 * testCommentQuery
	 *
	 * This tests creating a single comment with data and retrieving said comment via a GraphQL
	 * query
	 *
	 * @since 0.0.5
	 */
	public function testCommentQuery() {

		/**
		 * Create a comment
		 */
		wp_set_current_user( $this->admin );
		$comment_id = $this->createCommentObject();

		/**
		 * Create the global ID based on the comment_type and the created $id
		 */
		$global_id = \GraphQLRelay\Relay::toGlobalId( 'comment', $comment_id );

		/**
		 * Create the query string to pass to the $query
		 */
		$query = "
		query {
			comment(id: \"{$global_id}\") {
				agent
				approved
				author{
				    __typename
					...on User {
					  userId
					}
				}
				authorIp
				commentId
				children {
					edges {
						node {
							id
							commentId
							parent {
								commentId
							}
						}
					}
				}
				commentedOn {
					... on Post {
						id
					}
				}
				content
				date
				dateGmt
				id
				karma
				parent {
					id
				}
				type
			}
		}";

		/**
		 * Run the GraphQL query
		 */
		$actual = do_graphql_request( $query );

		codecept_debug( $actual );

		/**
		 * Establish the expectation for the output of the query
		 */
		$expected = [
			'data' => [
				'comment' => [
					'agent'       => null,
					'approved'    => true,
					'author'      => [
						'__typename' => 'User',
						'userId' => $this->admin,
					],
					'authorIp'    => null,
					'children'    => [
						'edges' => null,
					],
					'commentId'   => $comment_id,
					'commentedOn' => null,
					'content'     => apply_filters( 'comment_text', 'Test comment content' ),
					'date'        => $this->current_date,
					'dateGmt'     => $this->current_date_gmt,
					'id'          => $global_id,
					'karma'       => null,
					'parent'      => null,
					'type'        => null,
				],
			],
		];

		$this->assertEqualSets( $expected, $actual );
	}

	/**
	 * testCommentQuery
	 *
	 * This tests creating a single comment with data and retrieving said comment via a GraphQL
	 * query
	 *
	 * @since 0.0.5
	 */
	public function testCommentWithCommentAuthor() {

		/**
		 * Create a comment
		 */
		$comment_id = $this->createCommentObject( [
			'comment_author'       => 'Author Name',
			'comment_author_email' => 'test@test.com',
			'comment_author_url'   => 'http://example.com',
			'user_id' => 0,
		] );

		/**
		 * Create the global ID based on the comment_type and the created $id
		 */
		$global_id = \GraphQLRelay\Relay::toGlobalId( 'comment', $comment_id );

		/**
		 * Create the query string to pass to the $query
		 */
		$query = "
		query {
			comment(id: \"{$global_id}\") {
				agent
				approved
				author{
					...on CommentAuthor {
					  id
					  name
					  email
					  url
					}
				}
			}
		}";

		/**
		 * Run the GraphQL query
		 */
		$actual = do_graphql_request( $query );

		codecept_debug( $actual );

		/**
		 * Establish the expectation for the output of the query
		 */
		$expected = [
			'data' => [
				'comment' => [
					'agent'    => null,
					'approved' => true,
					'author'   => [
						'id'    => \GraphQLRelay\Relay::toGlobalId( 'commentAuthor', $comment_id ),
						'name'  => get_comment_author( $comment_id ),
						'email' => get_comment_author_email( $comment_id ),
						'url'   => get_comment_author_url( $comment_id ),
					],
				],
			],
		];

		$this->assertEqualSets( $expected, $actual );
	}

	/**
	 * testCommentQuery
	 *
	 * This tests creating a single comment with data and retrieving said comment via a GraphQL
	 * query
	 *
	 * @since 0.0.5
	 */
	public function testCommentQueryWithChildrenAssignedPostAndParent() {

		// Post object to assign comments to.
		$post_id = $this->factory()->post->create( [
			'post_content' => 'Post object',
			'post_author' => $this->admin,
			'post_status' => 'publish'
		] );

		// Parent comment.
		$parent_comment = $this->createCommentObject(
			[
				'comment_post_ID' => $post_id,
				'comment_content' => apply_filters( 'comment_text', 'Parent comment' ),
			]
		);

		/**
		 * Create a comment
		 */
		$comment_id = $this->createCommentObject( [
			'comment_post_ID' => $post_id,
			'comment_content' => apply_filters( 'comment_text', 'Test comment' ),
			'comment_parent'  => $parent_comment,
		] );

		// Create child comments.
		$child_1 = $this->createCommentObject( [
			'comment_post_ID' => $post_id,
			'comment_content' => apply_filters( 'comment_text', 'Child 1' ),
			'comment_parent'  => $comment_id,
		] );

		$child_2 = $this->createCommentObject( [
			'comment_post_ID' => $post_id,
			'comment_content' => apply_filters( 'comment_text', 'Child 2' ),
			'comment_parent'  => $comment_id,
		] );

		/**
		 * Create the global ID based on the comment_type and the created $id
		 */
		$global_id = \GraphQLRelay\Relay::toGlobalId( 'comment', $comment_id );

		/**
		 * Create the query string to pass to the $query
		 */
		$query = "
		query {
			comment(id: \"{$global_id}\") {
				children {
					edges {
						node {
							commentId
							content
						}
					}
				}
				commentId
				commentedOn {
					... on Post {
						content
					}
				}
				content
				parent {
					commentId
					content
				}
			}
		}";

		/**
		 * Run the GraphQL query
		 */
		$actual = do_graphql_request( $query );

		codecept_debug( $actual );

		/**
		 * Establish the expectation for the output of the query
		 */
		$expected = [
			'data' => [
				'comment' => [
					'children'    => [
						'edges' => [
							[
								'node' => [
									'commentId' => $child_2,
									'content'   => apply_filters( 'comment_text', 'Child 2' ),
								],
							],
							[
								'node' => [
									'commentId' => $child_1,
									'content'   => apply_filters( 'comment_text', 'Child 1' ),
								],
							],
						],
					],
					'commentId'   => $comment_id,
					'commentedOn' => [
						'content' => apply_filters( 'the_content', 'Post object' ),
					],
					'content'     => apply_filters( 'comment_text', 'Test comment' ),
					'parent'      => [
						'commentId' => $parent_comment,
						'content'   => apply_filters( 'comment_text', 'Parent comment' ),
					],
				],
			],
		];

		$this->assertEqualSets( $expected, $actual );
	}

	/**
	 * Assert that fields containing sensitive data are not exposed to users without proper caps
	 * @dataProvider dataProviderSwitchUser
	 * @param $user
	 * @param $should_display
	 */
	public function testCommentQueryHiddenFields( $user, $should_display ) {

		$post_id = $this->factory->post->create();

		$admin_args = [
			'comment_post_ID' => $post_id,
			'comment_content' => 'Admin Comment',
			'comment_author_email' => 'admin@test.com',
			'comment_author_IP' => '127.0.0.1',
			'comment_agent' => 'Admin Agent',
		];
		$admin_comment = $this->createCommentObject( $admin_args );
		$subscriber_args = [
			'comment_post_ID' => $post_id,
			'comment_content' => 'Subscriber Comment',
			'comment_author_email' => 'subscriber@test.com',
			'comment_author_IP' => '127.0.0.1',
			'comment_agent' => 'Subscriber Agent',
		];
		$subscriber_comment = $this->createCommentObject( $subscriber_args );

		$query = '
		query commentQuery( $id:ID! ) {
		  comment(id: $id) {
			commentId
		    id
		    authorIp
		    agent
		    approved
		    karma
		    content
		    commentedOn{
		      ... on Post{
		        postId
		      }
		    }
		  }
		} 
		';

		wp_set_current_user( $this->{$user} );
		$admin_actual = do_graphql_request( $query, 'commentQuery', wp_json_encode( [ 'id' => \GraphQLRelay\Relay::toGlobalId( 'comment', $admin_comment ) ] ) );
		$subscriber_actual = do_graphql_request( $query, 'commentQuery', wp_json_encode( [ 'id' => \GraphQLRelay\Relay::toGlobalId( 'comment', $subscriber_comment ) ] ) );

		$this->assertArrayNotHasKey( 'errors', $admin_actual );
		$this->assertArrayNotHasKey( 'errors', $subscriber_actual );

		$this->assertEquals( $admin_comment, $admin_actual['data']['comment']['commentId'] );
		$this->assertEquals( $subscriber_comment, $subscriber_actual['data']['comment']['commentId'] );

		$this->assertEquals( apply_filters( 'comment_text', $subscriber_args['comment_content'] ), $subscriber_actual['data']['comment']['content'] );
		$this->assertEquals( apply_filters( 'comment_text', $admin_args['comment_content'] ), $admin_actual['data']['comment']['content'] );

		if ( true === $should_display ) {
			$this->assertNotNull( $admin_actual['data']['comment']['authorIp'] );
			$this->assertNotNull( $admin_actual['data']['comment']['agent'] );
		} else {
			$this->assertNull( $admin_actual['data']['comment']['authorIp'] );
			$this->assertNull( $admin_actual['data']['comment']['agent'] );
		}

	}

	/**
	 * Assert that non-approved posts are hidden from users without proper caps
	 * @dataProvider dataProviderSwitchUser
	 * @param $user
	 * @param $should_display
	 */
	public function testUnapprovedCommentsNotQueryableWithoutAuth( $user, $should_display ) {

		$post_id = $this->factory->post->create();

		$admin_args = [
			'comment_post_ID' => $post_id,
			'comment_content' => 'Admin Comment',
			'comment_approved' => 0,
			'comment_author_email' => 'admin@test.com',
			'comment_author_IP' => '127.0.0.1',
			'comment_agent' => 'Admin Agent',
		];
		$admin_comment = $this->createCommentObject( $admin_args );
		$subscriber_args = [
			'comment_post_ID' => $post_id,
			'comment_approved' => 0,
			'comment_content' => 'Subscriber Comment',
			'comment_author_email' => 'subscriber@test.com',
			'comment_author_IP' => '127.0.0.1',
			'comment_agent' => 'Subscriber Agent',
		];
		$subscriber_comment = $this->createCommentObject( $subscriber_args );

		$query = '
		query commentQuery( $id:ID! ) {
		  comment(id: $id) {
			commentId
		    id
		    authorIp
		    agent
		    approved
		    karma
		    content
		    commentedOn{
		      ... on Post{
		        postId
		      }
		    }
		  }
		} 
		';

		wp_set_current_user( $this->{$user} );
		$admin_actual = do_graphql_request( $query, 'commentQuery', wp_json_encode( [ 'id' => \GraphQLRelay\Relay::toGlobalId( 'comment', $admin_comment ) ] ) );
		$subscriber_actual = do_graphql_request( $query, 'commentQuery', wp_json_encode( [ 'id' => \GraphQLRelay\Relay::toGlobalId( 'comment', $subscriber_comment ) ] ) );

		if ( true === $should_display ) {
			$this->assertArrayNotHasKey( 'errors', $admin_actual );
			$this->assertArrayNotHasKey( 'errors', $subscriber_actual );
			$this->assertNotNull( $admin_actual['data']['comment']['authorIp'] );
			$this->assertNotNull( $admin_actual['data']['comment']['agent'] );
			$this->assertEquals( $admin_comment, $admin_actual['data']['comment']['commentId'] );
			$this->assertEquals( $subscriber_comment, $subscriber_actual['data']['comment']['commentId'] );
			$this->assertEquals( apply_filters( 'comment_text', $subscriber_args['comment_content'] ), $subscriber_actual['data']['comment']['content'] );
			$this->assertEquals( apply_filters( 'comment_text', $admin_args['comment_content'] ), $admin_actual['data']['comment']['content'] );
		} else {
			$this->assertEmpty( $admin_actual['data']['comment'] );
		}

	}

	public function dataProviderSwitchUser() {
		return [
			[
				'user' => 'admin',
				'should_display' => true,
			],
			[
				'user' => 'subscriber',
				'should_display' => false,
			]
		];
	}


}
