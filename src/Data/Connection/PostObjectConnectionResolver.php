<?php

namespace WPGraphQL\Data\Connection;

use GraphQL\Deferred;
use GraphQL\Error\UserError;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQLRelay\Connection\ArrayConnection;
use WPGraphQL\AppContext;
use WPGraphQL\Model\Post;
use WPGraphQL\Model\PostType;
use WPGraphQL\Model\Term;
use WPGraphQL\Model\User;
use WPGraphQL\Types;

class PostObjectConnectionResolver {

	/**
	 * @var string
	 */
	protected $post_type;

	/**
	 * @var mixed
	 */
	protected $source;

	/**
	 * @var array
	 */
	protected $args;

	/**
	 * @var AppContext
	 */
	protected $context;

	/**
	 * @var ResolveInfo
	 */
	protected $info;

	/**
	 * @var array array
	 */
	protected $query_args;

	/**
	 * @var \WP_Query
	 */
	protected $query;

	/**
	 * @var array
	 */
	protected $items;

	/**
	 * @var array
	 */
	protected $nodes;

	/**
	 * @var array
	 */
	protected $edges;

	/**
	 * @var int
	 */
	protected $query_amount;

	/**
	 * PostObjectConnectionResolver constructor.
	 *
	 * @param mixed       $source    The object passed down from the previous level in the Resolve
	 *                               tree
	 * @param array       $args      The input arguments for the query
	 * @param AppContext  $context   The context of the request
	 * @param ResolveInfo $info      The resolve info passed down the Resolve tree
	 * @param string      $post_type The post type to resolve for
	 *
	 * @throws \Exception
	 */
	public function __construct( $source, $args, $context, $info, $post_type ) {

		/**
		 * Set the post type for the resolver
		 */
		$this->post_type = $post_type;

		/**
		 * Set the source (the root object) for the resolver
		 */
		$this->source = $source;

		/**
		 * Set the args for the resolver
		 */
		$this->args = $args;

		/**
		 * Set the context of the resolver
		 */
		$this->context = $context;

		/**
		 * Set the resolveInfo for the resolver
		 */
		$this->info = $info;

		/**
		 * Determine the query amount for the resolver.
		 *
		 * This is the amount of items to query from the database. We determine this by
		 * determining how many items were asked for (first/last), then compare with the
		 * max amount allowed to query (default is 100), and then we fetch 1 more than
		 * that amount, so we know whether hasNextPage/hasPreviousPage should be true.
		 *
		 * If there are more items than were asked for, then there's another page.
		 */
		$this->query_amount = $this->get_query_amount();

		/**
		 * Get the Query Args. This accepts the input args and maps it to how it should be
		 * used in the WP_Query
		 */
		$this->query_args = $this->get_query_args();

		/**
		 * Check if the connection should execute. If conditions are met that should prevent
		 * the execution, we can bail from resolving early, before the query is executed.
		 */
		$should_execute = $this->should_execute();
		if ( ! $should_execute ) {
			return [];
		}

		/**
		 * Set the query for the resolver, for use as reference in filters, etc
		 */
		$this->query = new \WP_Query( $this->query_args );

		/**
		 * The items returned from the query
		 */
		$this->items = $this->query->posts;

		/**
		 * Set the items. These are the "nodes" that make up the connection.
		 */
		$this->nodes = $this->get_nodes();
	}

	public function should_execute() {

		$should_execute = true;

		/**
		 * For revisions, we only want to execute the connection query if the user
		 * has access to edit the parent post.
		 *
		 * If the user doesn't have permission to edit the parent post, then we shouldn't
		 * even execute the connection
		 */
		if ( isset( $this->post_type ) && 'revision' === $this->post_type && $this->source instanceof Post ) {
			$parent_post_type_obj = get_post_type_object( $this->source->post_type );
			if ( ! current_user_can( $parent_post_type_obj->cap->edit_post, $this->source->ID ) ) {
				$should_execute = false;
			}
		}

		return apply_filters( 'graphql_connection_should_execute', $should_execute, $this );
	}

	/**
	 * Get the nodes from the query.
	 *
	 * We slice the array to match the amount of items that was asked for, as we over-fetched
	 * by 1 item to calculate pageInfo.
	 *
	 * For backward pagination, we reverse the order of nodes.
	 *
	 * @return array
	 */
	public function get_nodes() {
		if ( empty( $this->query->posts ) ) {
			return [];
		}
		$nodes = array_slice( $this->query->posts, 0, $this->query_amount );

		return empty( $this->args['last'] ) ? array_reverse( $nodes ) : $nodes;
	}

	/**
	 * This iterates over the items returned
	 *
	 * @return mixed
	 */
	public function get_edges() {
		$this->edges = [];
		if ( ! empty( $this->nodes ) ) {
			foreach ( $this->nodes as $node ) {
				$this->edges[] = [
					'cursor' => base64_encode( 'arrayconnection:' . $node ),
					'node'   => $node,
				];
			}
		}

		return $this->edges;
	}

	/**
	 * Here, we map the args from the input, then we make sure that we're only querying
	 * for IDs. The IDs are then passed down the resolve tree, and deferred resolvers
	 * handle batch resolution of the posts.
	 *
	 * @return array
	 */
	public function get_query_args() {
		$query_args           = $this->map_query_args();
		$query_args['fields'] = 'ids';
		return $query_args;
	}

	/**
	 * @return mixed string|null
	 */
	public function get_start_cursor() {
		$first_edge = $this->edges ? $this->edges[0] : null;

		return isset( $first_edge['cursor'] ) ? $first_edge['cursor'] : null;
	}

	/**
	 * @return mixed string|null
	 */
	public function get_end_cursor() {
		$last_edge = $this->edges ? $this->edges[ count( $this->edges ) - 1 ] : null;

		return isset( $last_edge['cursor'] ) ? $last_edge['cursor'] : null;
	}

	/**
	 * @return boolean
	 */
	public function has_next_page() {
		return ! empty( $this->args['first'] ) && ( $this->items > $this->query_amount ) ? true : false;
	}

	/**
	 * @return boolean
	 */
	public function has_previous_page() {
		return ! empty( $this->args['last'] ) && ( $this->items > $this->query_amount ) ? true : false;
	}

	/**
	 * @return array
	 */
	public function get_page_info() {
		return [
			'startCursor'     => $this->get_start_cursor(),
			'endCursor'       => $this->get_end_cursor(),
			'hasNextPage'     => $this->has_next_page(),
			'hasPreviousPage' => $this->has_previous_page(),
		];
	}

	/**
	 * @return Deferred
	 */
	public function get_connection() {
		$connection = new Deferred(function() {
			return [
				'edges'    => $this->get_edges(),
				'pageInfo' => $this->get_page_info(),
				'nodes'    => $this->get_nodes(),
			];
		});
		$connection->promise;
		return $connection;
	}

	/**
	 * @return mixed
	 */
	public function map_query_args() {

		/**
		 * Prepare for later use
		 */
		$last  = ! empty( $this->args['last'] ) ? $this->args['last'] : null;
		$first = ! empty( $this->args['first'] ) ? $this->args['first'] : null;

		/**
		 * Ignore sticky posts by default
		 */
		$query_args['ignore_sticky_posts'] = true;

		/**
		 * Set the post_type for the query based on the type of post being queried
		 */
		$query_args['post_type'] = ! empty( $this->post_type ) ? $this->post_type : 'post';

		/**
		 * Don't calculate the total rows, it's not needed and can be expensive
		 */
		$query_args['no_found_rows'] = true;

		/**
		 * Set the post_status to "publish" by default
		 */
		$query_args['post_status'] = 'publish';

		/**
		 * Set posts_per_page the highest value of $first and $last, with a (filterable) max of 100
		 */
		$query_args['posts_per_page'] = min( max( absint( $first ), absint( $last ), 10 ), $this->query_amount ) + 1;

		/**
		 * Set the default to only query posts with no post_parent set
		 */
		$query_args['post_parent'] = 0;

		/**
		 * Set the graphql_cursor_offset which is used by Config::graphql_wp_query_cursor_pagination_support
		 * to filter the WP_Query to support cursor pagination
		 */
		$query_args['graphql_cursor_offset']  = $this->get_offset();
		$query_args['graphql_cursor_compare'] = ( ! empty( $last ) ) ? '>' : '<';

		/**
		 * Pass the graphql $args to the WP_Query
		 */
		$query_args['graphql_args'] = $this->args;

		/**
		 * Collect the input_fields and sanitize them to prepare them for sending to the WP_Query
		 */
		$input_fields = [];
		if ( ! empty( $this->args['where'] ) ) {
			$input_fields = $this->sanitize_input_fields( $this->args['where'] );
		}

		/**
		 * If the post_type is "attachment" set the default "post_status" $query_arg to "inherit"
		 */
		if ( 'attachment' === $this->post_type || 'revision' === $this->post_type ) {
			$query_args['post_status'] = 'inherit';

			/**
			 * Unset the "post_parent" for attachments, as we don't really care if they
			 * have a post_parent set by default
			 */
			unset( $query_args['post_parent'] );

		}

		/**
		 * Determine where we're at in the Graph and adjust the query context appropriately.
		 *
		 * For example, if we're querying for posts as a field of termObject query, this will automatically
		 * set the query to pull posts that belong to that term.
		 */
		if ( true === is_object( $this->source ) ) {
			switch ( true ) {
				case $this->source instanceof Post:
					$query_args['post_parent'] = $this->source->ID;
					break;
				case $this->source instanceof PostType:
					$query_args['post_type'] = $this->source->name;
					break;
				case $this->source instanceof Term:
					$query_args['tax_query'] = [
						[
							'taxonomy' => $this->source->taxonomy->name,
							'terms'    => [ $this->source->term_id ],
							'field'    => 'term_id',
						],
					];
					break;
				case $this->source instanceof User:
					$query_args['author'] = $this->source->userId;
					break;
			}
		}

		/**
		 * Merge the input_fields with the default query_args
		 */
		if ( ! empty( $input_fields ) ) {
			$query_args = array_merge( $query_args, $input_fields );
		}

		/**
		 * If the query is a search, the source is not another Post, and the parent input $arg is not
		 * explicitly set in the query, unset the $query_args['post_parent'] so the search
		 * can search all posts, not just top level posts.
		 */
		if ( ! $this->source instanceof \WP_Post && isset( $query_args['search'] ) && ! isset( $input_fields['parent'] ) ) {
			unset( $query_args['post_parent'] );
		}

		/**
		 * Map the orderby inputArgs to the WP_Query
		 */
		if ( ! empty( $this->args['where']['orderby'] ) && is_array( $this->args['where']['orderby'] ) ) {
			$query_args['orderby'] = [];
			foreach ( $this->args['where']['orderby'] as $orderby_input ) {
				/**
				 * These orderby options should not include the order parameter.
				 */
				if ( in_array( $orderby_input['field'], [
					'post__in',
					'post_name__in',
					'post_parent__in'
				], true ) ) {
					$query_args['orderby'] = esc_sql( $orderby_input['field'] );
				} else if ( ! empty( $orderby_input['field'] ) ) {
					$query_args['orderby'] = [
						esc_sql( $orderby_input['field'] ) => esc_sql( $orderby_input['order'] ),
					];
				}
			}
		}

		/**
		 * If there's no orderby params in the inputArgs, set order based on the first/last argument
		 */
		if ( empty( $query_args['orderby'] ) ) {
			$query_args['order'] = ! empty( $last ) ? 'ASC' : 'DESC';
		}

		/**
		 * Filter the $query args to allow folks to customize queries programmatically
		 *
		 * @param array       $query_args The args that will be passed to the WP_Query
		 * @param mixed       $source     The source that's passed down the GraphQL queries
		 * @param array       $args       The inputArgs on the field
		 * @param AppContext  $context    The AppContext passed down the GraphQL tree
		 * @param ResolveInfo $info       The ResolveInfo passed down the GraphQL tree
		 */
		$query_args = apply_filters( 'graphql_post_object_connection_query_args', $query_args, $this->source, $this->args, $this->context, $this->info );

		return $query_args;

	}

	/**
	 * This returns the offset to be used in the $query_args based on the $args passed to the
	 * GraphQL query.
	 *
	 * @return int|mixed
	 */
	public function get_offset() {

		/**
		 * Defaults
		 */
		$offset = 0;

		/**
		 * Get the $after offset
		 */
		if ( ! empty( $this->args['after'] ) ) {
			$offset = ArrayConnection::cursorToOffset( $this->args['after'] );
		} elseif ( ! empty( $this->args['before'] ) ) {
			$offset = ArrayConnection::cursorToOffset( $this->args['before'] );
		}

		/**
		 * Return the higher of the two values
		 */
		return max( 0, $offset );

	}

	/**
	 * This sets up the "allowed" args, and translates the GraphQL-friendly keys to WP_Query
	 * friendly keys. There's probably a cleaner/more dynamic way to approach this, but
	 * this was quick. I'd be down to explore more dynamic ways to map this, but for
	 * now this gets the job done.
	 *
	 * @since  0.0.5
	 * @access public
	 * @return array
	 */
	public function sanitize_input_fields( $where_args ) {

		$arg_mapping = [
			'authorName'    => 'author_name',
			'authorIn'      => 'author__in',
			'authorNotIn'   => 'author__not_in',
			'categoryId'    => 'cat',
			'categoryName'  => 'category_name',
			'categoryIn'    => 'category__in',
			'categoryNotIn' => 'category__not_in',
			'tagId'         => 'tag_id',
			'tagIds'        => 'tag__and',
			'tagIn'         => 'tag__in',
			'tagNotIn'      => 'tag__not_in',
			'tagSlugAnd'    => 'tag_slug__and',
			'tagSlugIn'     => 'tag_slug__in',
			'search'        => 's',
			'id'            => 'p',
			'parent'        => 'post_parent',
			'parentIn'      => 'post_parent__in',
			'parentNotIn'   => 'post_parent__not_in',
			'in'            => 'post__in',
			'notIn'         => 'post__not_in',
			'nameIn'        => 'post_name__in',
			'hasPassword'   => 'has_password',
			'password'      => 'post_password',
			'status'        => 'post_status',
			'stati'         => 'post_status',
			'dateQuery'     => 'date_query',
		];

		/**
		 * Map and sanitize the input args to the WP_Query compatible args
		 */
		$query_args = Types::map_input( $where_args, $arg_mapping );

		if ( ! empty( $query_args['post_status'] ) ) {
			$query_args['post_status'] = $this->sanitize_post_stati( $query_args['post_status'] );
		}

		/**
		 * Filter the input fields
		 * This allows plugins/themes to hook in and alter what $args should be allowed to be passed
		 * from a GraphQL Query to the WP_Query
		 *
		 * @param array       $query_args The mapped query arguments
		 * @param array       $args       Query "where" args
		 * @param mixed       $source     The query results for a query calling this
		 * @param array       $all_args   All of the arguments for the query (not just the "where" args)
		 * @param AppContext  $context    The AppContext object
		 * @param ResolveInfo $info       The ResolveInfo object
		 * @param string      $post_type  The post type for the query
		 *
		 * @since 0.0.5
		 * @return array
		 */
		$query_args = apply_filters( 'graphql_map_input_fields_to_wp_query', $query_args, $where_args, $this->source, $this->args, $this->context, $this->info, $this->post_type );

		/**
		 * Return the Query Args
		 */
		return ! empty( $query_args ) && is_array( $query_args ) ? $query_args : [];

	}

	/**
	 * Limit the status of posts a user can query.
	 *
	 * By default, published posts are public, and other statuses require permission to access.
	 *
	 * This strips the status from the query_args if the user doesn't have permission to query for
	 * posts of that status.
	 *
	 * @param $stati
	 *
	 * @return array|null
	 */
	public function sanitize_post_stati( $stati ) {
		if ( empty( $stati ) ) {
			$stati = [ 'publish' ];
		}
		$statuses = wp_parse_slug_list( $stati );
		$post_type_obj = get_post_type_object( $this->post_type );
		$allowed_statuses = array_filter( array_map(function( $status ) use ( $post_type_obj ) {
			if ( $status === 'publish' ) {
				return $status;
			}
			if ( current_user_can( $post_type_obj->cap->edit_posts ) || 'private' === $status && current_user_can( $post_type_obj->cap->read_private_posts ) ) {
				return $status;
			} else {
				return null;
			}
		}, $statuses ) );

		return $allowed_statuses;
	}

	/**
	 * get_query_amount
	 *
	 * Returns the max between what was requested and what is defined as the $max_query_amount to
	 * ensure that queries don't exceed unwanted limits when querying data.
	 *
	 * @return int
	 * @throws \Exception
	 */
	public function get_query_amount() {

		/**
		 * Filter the maximum number of posts per page that should be quried. The default is 100 to prevent queries from
		 * being exceedingly resource intensive, however individual systems can override this for their specific needs.
		 *
		 * This filter is intentionally applied AFTER the query_args filter, as
		 *
		 * @param array       $query_args array of query_args being passed to the
		 * @param mixed       $source     source passed down from the resolve tree
		 * @param array       $args       array of arguments input in the field as part of the GraphQL query
		 * @param AppContext  $context    Object containing app context that gets passed down the resolve tree
		 * @param ResolveInfo $info       Info about fields passed down the resolve tree
		 *
		 * @since 0.0.6
		 */
		$max_query_amount = apply_filters( 'graphql_connection_max_query_amount', 100, $this->source, $this->args, $this->context, $this->info );

		return min( $max_query_amount, absint( $this->get_amount_requested() ) );

	}

	/**
	 * This checks the $args to determine the amount requested, and if
	 *
	 * @return int|null
	 * @throws \Exception
	 */
	public function get_amount_requested() {

		/**
		 * Set the default amount
		 */
		$amount_requested = 10;

		/**
		 * If both first & last are used in the input args, throw an exception as that won't
		 * work properly
		 */
		if ( ! empty( $this->args['first'] ) && ! empty( $this->args['last'] ) ) {
			throw new UserError( esc_html__( 'first and last cannot be used together. For forward pagination, use first & after. For backward pagination, use last & before.', 'wp-graphql' ) );
		}

		/**
		 * If first is set, and is a positive integer, use it for the $amount_requested
		 * but if it's set to anything that isn't a positive integer, throw an exception
		 */
		if ( ! empty( $this->args['first'] ) && is_int( $this->args['first'] ) ) {
			if ( 0 > $this->args['first'] ) {
				throw new UserError( esc_html__( 'first must be a positive integer.', 'wp-graphql' ) );
			} else {
				$amount_requested = $this->args['first'];
			}
		}

		/**
		 * If last is set, and is a positive integer, use it for the $amount_requested
		 * but if it's set to anything that isn't a positive integer, throw an exception
		 */
		if ( ! empty( $this->args['last'] ) && is_int( $this->args['last'] ) ) {
			if ( 0 > $this->args['last'] ) {
				throw new UserError( esc_html__( 'last must be a positive integer.', 'wp-graphql' ) );
			} else {
				$amount_requested = $this->args['last'];
			}
		}

		return max( 0, $amount_requested );

	}

}
