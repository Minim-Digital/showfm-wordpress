<?php
/**
 * The Publishing tab's settings: what a new episode post looks like.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads, checks and stores the publishing settings (one option, autoload off). The sync reads
 * them when it creates a post. They shape new posts only: a post keeps the post type,
 * author, category and template it was created with, and the transcript and featured image
 * choices it was created with are stored on the post, so changing a setting never rewrites
 * an existing post.
 */
final class Publishing {

	/** Option holding the settings (autoload off). */
	const OPTION = 'showfm_publishing';

	/** Most authors offered per post type. */
	const MAX_AUTHORS = 100;

	/** Most categories offered. */
	const MAX_CATEGORIES = 500;

	/** Setting defaults. Author 0 means the first user who can publish the post type. */
	const DEFAULTS = array(
		'auto_post'      => true,
		'post_type'      => 'post',
		'category'       => 0,
		'author'         => 0,
		'template'       => '',
		'transcript'     => true,
		'featured_image' => true,
	);

	/**
	 * The stored settings over the defaults, each of the right type.
	 *
	 * @return array{auto_post:bool,post_type:string,category:int,author:int,template:string,transcript:bool,featured_image:bool}
	 */
	public static function settings(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		// Development builds before the Publishing tab stored a list of categories.
		if ( ! isset( $stored['category'] ) && is_array( $stored['categories'] ?? null ) ) {
			$stored['category'] = reset( $stored['categories'] );
		}
		return array(
			'auto_post'      => (bool) ( $stored['auto_post'] ?? self::DEFAULTS['auto_post'] ),
			'post_type'      => is_string( $stored['post_type'] ?? null ) ? $stored['post_type'] : self::DEFAULTS['post_type'],
			'category'       => absint( $stored['category'] ?? 0 ),
			'author'         => absint( $stored['author'] ?? 0 ),
			'template'       => is_string( $stored['template'] ?? null ) ? $stored['template'] : '',
			'transcript'     => (bool) ( $stored['transcript'] ?? self::DEFAULTS['transcript'] ),
			'featured_image' => (bool) ( $stored['featured_image'] ?? self::DEFAULTS['featured_image'] ),
		);
	}

	/**
	 * Checks and stores new settings. Fields left out keep their stored value.
	 *
	 * @param array<string,mixed> $input Sanitised fields.
	 * @return array<string,mixed>|\WP_Error The stored settings, or why they were refused.
	 */
	public static function save( array $input ) {
		$settings = self::validate( array_merge( self::settings(), array_intersect_key( $input, self::DEFAULTS ) ) );
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}
		if ( ! update_option( self::OPTION, $settings, false ) && get_option( self::OPTION ) !== $settings ) {
			return new \WP_Error( 'showfm_not_saved', __( 'The settings couldn’t be saved. Try again.', 'showfm' ), array( 'status' => 500 ) );
		}
		return $settings;
	}

	/**
	 * Checks a full set of settings. The post type must be public and use the block editor,
	 * the author must be able to publish it, the category must exist (and is dropped for a
	 * post type without categories) and the template must be one the theme offers for it.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function validate( array $settings ) {
		$type = sanitize_key( (string) $settings['post_type'] );
		if ( ! self::is_eligible_type( $type ) ) {
			return self::invalid( 'postType', __( 'Choose a public post type that uses the block editor.', 'showfm' ) );
		}

		$author = absint( $settings['author'] );
		if ( 0 === $author ) {
			$author = self::default_author( $type );
		}
		if ( ! self::can_author( $author, $type ) ) {
			return self::invalid( 'author', __( 'Choose someone who can publish this post type.', 'showfm' ) );
		}

		$category = absint( $settings['category'] );
		if ( ! self::has_categories( $type ) ) {
			$category = 0;
		} elseif ( $category > 0 && ! term_exists( $category, 'category' ) ) {
			return self::invalid( 'category', __( 'That category doesn’t exist any more. Choose another one.', 'showfm' ) );
		}

		$template = sanitize_text_field( (string) $settings['template'] );
		if ( '' !== $template && ! isset( self::theme_templates( $type )[ $template ] ) ) {
			return self::invalid( 'template', __( 'That template isn’t available for this post type. Choose another one.', 'showfm' ) );
		}

		return array(
			'auto_post'      => (bool) $settings['auto_post'],
			'post_type'      => $type,
			'category'       => $category,
			'author'         => $author,
			'template'       => $template,
			'transcript'     => (bool) $settings['transcript'],
			'featured_image' => (bool) $settings['featured_image'],
		);
	}

	/**
	 * A refusal naming the field it is about.
	 *
	 * @param string $field   The field, as the Publishing tab names it.
	 * @param string $message What to do.
	 */
	private static function invalid( string $field, string $message ): \WP_Error {
		return new \WP_Error(
			'showfm_invalid_setting',
			$message,
			array(
				'status' => 400,
				'field'  => $field,
			)
		);
	}

	/**
	 * Whether episodes may be posted as this post type: registered, public, shown in the REST
	 * API (so the block editor can edit it) and with the editor. Media items never qualify.
	 *
	 * @param string $type Post type.
	 */
	public static function is_eligible_type( string $type ): bool {
		$object = get_post_type_object( $type );
		return null !== $object
			&& 'attachment' !== $type
			&& $object->public
			&& $object->show_in_rest
			&& is_post_type_viewable( $object )
			&& post_type_supports( $type, 'editor' );
	}

	/**
	 * The post types episodes may be posted as, for the Post type field.
	 *
	 * @return array<int,array{value:string,label:string,categories:bool}>
	 */
	public static function post_types(): array {
		$types = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $name => $object ) {
			if ( self::is_eligible_type( $name ) ) {
				$types[] = array(
					'value'      => $name,
					'label'      => $object->labels->name,
					'categories' => self::has_categories( $name ),
				);
			}
		}
		return $types;
	}

	/**
	 * Whether posts of this type can have categories.
	 *
	 * @param string $type Post type.
	 */
	public static function has_categories( string $type ): bool {
		return is_object_in_taxonomy( $type, 'category' );
	}

	/**
	 * The categories, for the Category field.
	 *
	 * @return array<int,array{value:int,label:string}>
	 */
	public static function categories(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
				'number'     => self::MAX_CATEGORIES,
				'orderby'    => 'name',
			)
		);
		if ( ! is_array( $terms ) ) {
			return array();
		}
		$categories = array();
		foreach ( $terms as $term ) {
			$categories[] = array(
				'value' => $term->term_id,
				'label' => $term->name,
			);
		}
		return $categories;
	}

	/**
	 * Whether a user may be a post's author: they exist, belong to this site and can publish
	 * the post type.
	 *
	 * @param int    $user_id User.
	 * @param string $type    Post type.
	 */
	public static function can_author( int $user_id, string $type ): bool {
		$object = get_post_type_object( $type );
		return null !== $object
			&& $user_id > 0
			&& false !== get_userdata( $user_id )
			&& user_can( $user_id, $object->cap->publish_posts )
			&& ( ! is_multisite() || is_user_member_of_blog( $user_id ) );
	}

	/**
	 * The users who can publish this post type, for the Author field. Users are found by role
	 * capability and then checked one by one, so a filtered capability is respected.
	 *
	 * @param string $type Post type.
	 * @return array<int,array{value:int,label:string}>
	 */
	public static function authors( string $type ): array {
		$object = get_post_type_object( $type );
		if ( null === $object ) {
			return array();
		}
		$users   = get_users(
			array(
				'capability' => $object->cap->publish_posts,
				'number'     => self::MAX_AUTHORS,
				'orderby'    => 'display_name',
				'fields'     => array( 'ID', 'display_name' ),
			)
		);
		$authors = array();
		foreach ( $users as $user ) {
			if ( self::can_author( (int) $user->ID, $type ) ) {
				$authors[] = array(
					'value' => (int) $user->ID,
					'label' => $user->display_name,
				);
			}
		}
		return $authors;
	}

	/**
	 * The author when none was chosen: the user with the lowest id who can publish the post
	 * type, or 0 when nobody can.
	 *
	 * @param string $type Post type.
	 */
	public static function default_author( string $type ): int {
		$object = get_post_type_object( $type );
		if ( null === $object ) {
			return 0;
		}
		$ids = get_users(
			array(
				'capability' => $object->cap->publish_posts,
				'number'     => 1,
				'fields'     => 'ID',
				'orderby'    => 'ID',
			)
		);
		return isset( $ids[0] ) && self::can_author( (int) $ids[0], $type ) ? (int) $ids[0] : 0;
	}

	/**
	 * The theme's templates for a post type, as file => name. A block theme's custom
	 * templates are included by WordPress.
	 *
	 * @param string $type Post type.
	 * @return array<string,string>
	 */
	public static function theme_templates( string $type ): array {
		return wp_get_theme()->get_page_templates( null, $type );
	}

	/**
	 * The Post template field's choices, with Default first.
	 *
	 * @param string $type Post type.
	 * @return array<int,array{value:string,label:string}>
	 */
	public static function templates( string $type ): array {
		$choices = array(
			array(
				'value' => '',
				'label' => __( 'Default', 'showfm' ),
			),
		);
		foreach ( self::theme_templates( $type ) as $file => $name ) {
			$choices[] = array(
				'value' => (string) $file,
				'label' => (string) $name,
			);
		}
		return $choices;
	}

	/**
	 * The transcript and featured image choices a new post is created with. They are stored on
	 * the post, so later updates keep them whatever the settings say then.
	 *
	 * @param array{transcript:bool,featured_image:bool} $settings Settings.
	 * @return array{transcript:bool,featured_image:bool}
	 */
	public static function post_options( array $settings ): array {
		return array(
			'transcript'     => $settings['transcript'],
			'featured_image' => $settings['featured_image'],
		);
	}

	/**
	 * The choices a synced post was created with. A post created before they were stored
	 * has neither.
	 *
	 * @param int $post_id Post.
	 * @return array{transcript:bool,featured_image:bool}
	 */
	public static function options_of( int $post_id ): array {
		$stored = get_post_meta( $post_id, '_showfm_post_options', true );
		$stored = is_array( $stored ) ? $stored : array();
		return array(
			'transcript'     => ! empty( $stored['transcript'] ),
			'featured_image' => ! empty( $stored['featured_image'] ),
		);
	}
}
