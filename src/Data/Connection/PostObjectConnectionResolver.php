<?php

namespace WPGraphQL\Data\Connection;

use GraphQL\Type\Definition\ResolveInfo;
use WPGraphQL\AppContext;
use WPGraphQL\Model\Post;
use WPGraphQL\Model\PostType;
use WPGraphQL\Model\Term;
use WPGraphQL\Model\User;
use WPGraphQL\Types;

/**
 * Class PostObjectConnectionResolver
 *
 * @package WPGraphQL\Data\Connection
 */
class PostObjectConnectionResolver extends AbstractConnectionResolver {

	/**
	 * @var string
	 */
	protected $post_type;

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
		 * Call the parent construct to setup class data
		 */
		parent::__construct( $source, $args, $context, $info );

	}

	/**
	 * @return \WP_Query
	 */
	protected function get_query() {
		return new \WP_Query( $this->get_query_args() );
	}

	/**
	 * Return an array of items from the query
	 *
	 * @return array
	 */
	protected function get_items() {
		return ! empty( $this->query->posts ) ? $this->query->posts : [];
	}

	/**
	 * Determine whether the Query should execute. If it's determined that the query should
	 * not be run based on context such as, but not limited to, who the user is, where in the
	 * ResolveTree the Query is, the relation to the node the Query is connected to, etc
	 *
	 * Return false to prevent the query from executing.
	 *
	 * @return bool
	 */
	protected function should_execute() {

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
	 * Here, we map the args from the input, then we make sure that we're only querying
	 * for IDs. The IDs are then passed down the resolve tree, and deferred resolvers
	 * handle batch resolution of the posts.
	 *
	 * @return array
	 */
	protected function get_query_args() {
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
							'taxonomy' => $this->source->taxonomyName,
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
		 * NOTE: Only IDs should be queried here as the Deferred resolution will handle
		 * fetching the full objects, either from cache of from a follow-up query to the DB
		 */
		$query_args['fields'] = 'ids';

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

		/**
		 * Return the $query_args
		 */
		return $query_args;
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

}
