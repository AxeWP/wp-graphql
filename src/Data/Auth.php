<?php
namespace WPGraphQL\Data;

use GraphQL\Error\UserError;
use GraphQL\Executor\Executor;
use GraphQL\Language\AST\Field;
use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use WPGraphQL\AppContext;
use WPGraphQL\Type\WPObjectType;

class Auth {

	public static function instrument_schema( \WPGraphQL\WPSchema $schema ) {

		$new_types = [];
		$types = $schema->getTypeMap();

		if ( ! empty( $types ) && is_array( $types ) ) {
			foreach ( $types as $type_name => $type_object ) {
				if ( $type_object instanceof ObjectType || $type_object instanceof WPObjectType ) {
					$fields = $type_object->getFields();
					$new_fields = self::wrap_field_resolvers( $fields, $type_name );
					$new_type_object = $type_object;
					$new_type_object->config['fields'] = $new_fields;
					$new_types[ $type_name ] = $new_type_object;
				}
			}
		}

		if ( ! empty( $new_types ) && is_array( $new_types ) ) {
			$schema->config['types'] = $new_types;
		}

		return $schema;

	}

	protected static function wrap_field_resolvers( $fields, $type_name ) {

		if ( ! empty( $fields ) && is_array( $fields ) ) {

			foreach ( $fields as $field_key => $field ) {

				if ( $field instanceof FieldDefinition ) {

					/**
					 * Get the fields resolve function
					 * @since 0.0.1
					 */
					$field_resolver = ! empty( $field->resolveFn ) ? $field->resolveFn : null;

					/**
					 * Replace the existing field resolve method with a new function that captures data about
					 * the resolver to be stored in the resolver_report
					 * @since 0.0.1
					 *
					 * @param $source
					 * @param array $args
					 * @param AppContext $context
					 * @param ResolveInfo $info
					 *
					 * @use function|null $field_resolve_function
					 * @use string $type_name
					 * @use string $field_key
					 * @use object $field
					 *
					 * @return mixed
					 * @throws \Exception
					 */
					$field->resolveFn = function( $source, array $args, AppContext $context, ResolveInfo $info ) use ( $field_resolver, $type_name, $field_key, $field ) {

						do_action( 'graphql_before_resolve_field', $source, $args, $context, $info, $field_resolver, $type_name, $field_key, $field );

						/**
						 * If the current field doesn't have a resolve function, use the defaultFieldResolver,
						 * otherwise use the $field_resolver
						 */
						if ( null === $field_resolver || ! is_callable( $field_resolver ) ) {
							$result = Executor::defaultFieldResolver( $source, $args, $context, $info );
						} else {
							$result = call_user_func( $field_resolver, $source, $args, $context, $info );
						}

						$result = apply_filters( 'graphql_resolve_field', $result, $source, $args, $context, $info, $field_resolver, $type_name, $field_key, $field );

						do_action( 'graphql_after_resolve_field', $source, $args, $context, $info, $field_resolver, $type_name, $field_key, $field );

						return $result;

					};

				}
			}
		}

		/**
		 * Return the fields
		 */
		return $fields;

	}

	public static function check_field_permissions( $source, $args, $context, $info, $field_resolver, $type_name, $field_key, $field ) {

		/**
		 * Set the default auth error message
		 */
		$default_auth_error_message = __( 'You do not have permission to view this', 'wp-graphql' );

		$auth_error = apply_filters( 'graphql_field_resolver_auth_error_message', $default_auth_error_message, $field );

		/**
		 * Check to see if
		 */
		if ( $field instanceof FieldDefinition && (
			isset ( $field->config['isPrivate'] ) ||
			( ! empty( $field->config['auth'] ) && is_array( $field->config['auth'] ) ) )
		) {

			if ( empty( wp_get_current_user()->ID ) ) {
				throw new UserError( $auth_error );
			}

			if ( ! empty( $field->config['auth']['callback'] ) && is_callable( $field->config['auth']['callback'] ) ) {
				return call_user_func( $field->config['auth']['callback'], $field, $field_key,  $source, $args, $context, $info, $field_resolver );
			}

			if ( ! empty( $field->config['auth']['allowedCaps'] ) && is_callable( $field->config['auth']['allowedCaps'] ) ) {
				if ( empty( array_intersect( array_keys( wp_get_current_user()->allcaps ), array_values( $field['auth']['allowedCaps'] ) ) ) ) {
					throw new UserError( $auth_error );
				}
			}

			if ( ! empty( $field->config['auth']['allowedRoles'] ) && is_callable( $field->config['auth']['allowedRoles'] ) ) {
				if ( empty( array_intersect( array_values( wp_get_current_user()->roles ), array_values( $field['auth']['allowedRoles'] ) ) ) ) {
					throw new UserError( $auth_error );
				}
			}

		}



//		/**
//		 * If the auth config is a callback,
//		 */
//		if ( ! empty( $field['auth']['callback'] ) && is_callable( $field['auth']['callback'] ) ) {
//			return call_user_func( $field['auth']['callback'], $field, $type_name );
//		} else if ( ! empty( $field['auth']['allowedCaps'] ) && is_array( $field['auth']['allowedCaps'] ) ) {
//			return function() use ( $auth_error, $field ) {
//				if ( empty( array_intersect( array_keys( wp_get_current_user()->allcaps ), array_values( $field['auth']['allowedCaps'] ) ) ) ) {
//					throw new UserError( $auth_error );
//				}
//			};
//		} else if ( ! empty( $field['auth']['allowedRoles'] ) && is_array( $field['auth']['allowedRoles'] ) ) {
//
//			/**
//			 * If the user DOESN'T have any of the allowedCaps throw the error
//			 */
//			return function() use ( $auth_error, $field ) {
//				if ( empty( array_intersect( array_values( wp_get_current_user()->roles ), array_values( $field['auth']['allowedRoles'] ) ) ) ) {
//					throw new UserError( $auth_error );
//				}
//			};
//
//			/**
//			 * If the field is marked as "isPrivate" make sure the request is authenticated, else throw a UserError
//			 */
//		} else if ( true === $field['isPrivate'] ) {
//
//			/**
//			 * If the field is marked as private, but no specific auth check was configured,
//			 * make sure a user is authenticated, or throw an error
//			 */
//
//			return function() use ( $auth_error ) {
//				if ( 0 === wp_get_current_user()->ID ) {
//					throw new UserError( $auth_error );
//				}
//			};
//		}


	}

}