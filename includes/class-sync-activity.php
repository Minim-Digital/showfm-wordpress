<?php
/**
 * The last 20 things the sync did to posts, for the Publishing tab.
 *
 * @package ShowFM
 */

namespace ShowFM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One option (autoload off) holding the newest events: when, which episode and show, what
 * happened, and the post. It keeps the episode title as the sync wrote it, and plugin-owned
 * event codes, never remote error text. Re-applying a row that changes nothing records
 * nothing, and "not posted" is recorded once per episode until something else happens to it.
 */
final class Sync_Activity {

	/** Option holding the events, oldest first. */
	const OPTION = 'showfm_sync_activity';

	/** Events kept. */
	const MAX = 20;

	/** Longest title kept. */
	const MAX_TITLE = 200;

	/**
	 * Episodes already recorded as not posted, as episode id => time (autoload off). Kept
	 * apart from the 20 events, so an entry pushed out of them is never recorded again.
	 */
	const SKIPPED_OPTION = 'showfm_sync_skipped';

	/** Most episodes remembered as not posted; the oldest go first. */
	const MAX_SKIPPED = 1000;

	/** A new post was published. */
	const POSTED = 'posted';

	/** A new or existing post was scheduled, or its date moved. */
	const SCHEDULED = 'scheduled';

	/** The title, description or date of a post changed. */
	const UPDATED = 'updated';

	/** The post was edited in WordPress, so only its date and status changed. */
	const UPDATED_EDITED = 'updated_edited';

	/** The episode was unpublished, so the post went back to draft. */
	const DRAFTED = 'drafted';

	/** The episode was taken off this site in show.fm, so the post went back to draft. */
	const REMOVED = 'removed';

	/** The episode was deleted, so the post went to the bin. */
	const TRASHED = 'trashed';

	/** The site lost access to the show, so the post is no longer synced. */
	const DETACHED = 'detached';

	/** The show's plan paused auto-posting. */
	const PAUSED = 'paused';

	/** Auto-posting is off here, so no post was created. */
	const SKIPPED = 'skipped';

	/** Every event code. */
	const EVENTS = array( self::POSTED, self::SCHEDULED, self::UPDATED, self::UPDATED_EDITED, self::DRAFTED, self::REMOVED, self::TRASHED, self::DETACHED, self::PAUSED, self::SKIPPED );

	/** What may have changed in an update. */
	const CHANGES = array( 'title', 'description', 'date' );

	/**
	 * Records an event. Called by the sync, under its lock.
	 *
	 * @param string              $event   One of the event constants.
	 * @param array<string,mixed> $row     The feed row.
	 * @param int                 $post_id The post, or 0.
	 * @param array<string,mixed> $extra   `date` (Unix time) for scheduled posts, `changes` for updates.
	 */
	public static function record( string $event, array $row, int $post_id, array $extra = array() ): void {
		if ( ! in_array( $event, self::EVENTS, true ) ) {
			return;
		}
		$title = is_array( $row['episode'] ?? null ) && is_string( $row['episode']['title'] ?? null ) ? $row['episode']['title'] : '';
		if ( '' === $title && $post_id > 0 ) {
			$title = (string) get_post_field( 'post_title', $post_id, 'raw' );
		}
		$entry = array(
			'at'      => time(),
			'event'   => $event,
			'episode' => (string) $row['episode_id'],
			'podcast' => (string) ( $row['podcast_id'] ?? '' ),
			'title'   => Text::cut( wp_strip_all_tags( $title ), self::MAX_TITLE ),
			'post'    => $post_id,
		);
		if ( isset( $extra['date'] ) ) {
			$entry['date'] = (int) $extra['date'];
		}
		if ( isset( $extra['changes'] ) && is_array( $extra['changes'] ) ) {
			$entry['changes'] = array_values( array_intersect( self::CHANGES, $extra['changes'] ) );
		}
		// Once per episode until something else happens to it, so repeated edits to episodes
		// that are not posted cannot push real events out. The marker map is updated only
		// after the entry is written, so an interrupted write never loses an entry.
		$skipped = self::skipped();
		if ( self::SKIPPED === $event && isset( $skipped[ $entry['episode'] ] ) ) {
			return;
		}
		$events   = self::entries();
		$events[] = $entry;
		Sync::guard();
		update_option( self::OPTION, array_slice( $events, -self::MAX ), false );
		if ( self::SKIPPED === $event ) {
			$skipped[ $entry['episode'] ] = $entry['at'];
		} elseif ( isset( $skipped[ $entry['episode'] ] ) ) {
			unset( $skipped[ $entry['episode'] ] );
		} else {
			return;
		}
		Sync::guard();
		update_option( self::SKIPPED_OPTION, array_slice( $skipped, -self::MAX_SKIPPED, null, true ), false );
	}

	/**
	 * The episodes remembered as not posted, oldest first.
	 *
	 * @return array<string,int>
	 */
	private static function skipped(): array {
		$skipped = get_option( self::SKIPPED_OPTION, array() );
		return is_array( $skipped ) ? $skipped : array();
	}

	/**
	 * The stored events, oldest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function entries(): array {
		$events = get_option( self::OPTION, array() );
		if ( ! is_array( $events ) ) {
			return array();
		}
		return array_values(
			array_filter(
				$events,
				static function ( $event ): bool {
					return is_array( $event ) && in_array( $event['event'] ?? null, self::EVENTS, true ) && is_int( $event['at'] ?? null );
				}
			)
		);
	}

	/**
	 * The events as the Publishing tab shows them, newest first.
	 *
	 * @param array<string,string> $shows Show titles by podcast id.
	 * @return array<int,array{id:string,time:string,episode:string,show:string,what:string,muted:bool,link:array{label:string,url:string}|null}>
	 */
	public static function view( array $shows ): array {
		$rows = array();
		foreach ( array_reverse( self::entries() ) as $index => $event ) {
			$rows[] = array(
				'id'      => $event['at'] . '-' . $index,
				'time'    => self::when( $event['at'] ),
				'episode' => (string) ( $event['title'] ?? '' ),
				'show'    => $shows[ $event['podcast'] ?? '' ] ?? '',
				'what'    => self::describe( $event ),
				'muted'   => in_array( $event['event'], array( self::PAUSED, self::SKIPPED ), true ),
				'link'    => self::link( $event ),
			);
		}
		return $rows;
	}

	/**
	 * What happened, in one sentence.
	 *
	 * @param array<string,mixed> $event Stored event.
	 */
	public static function describe( array $event ): string {
		switch ( $event['event'] ) {
			case self::POSTED:
				return __( 'Posted', 'showfm' );
			case self::SCHEDULED:
				return isset( $event['date'] )
					/* translators: %s: date and time the post goes live, for example "14 October at 09:00". */
					? sprintf( __( 'Scheduled for %s', 'showfm' ), self::moment( (int) $event['date'] ) )
					: __( 'Scheduled', 'showfm' );
			case self::UPDATED_EDITED:
				return __( 'Updated the date and status only. The post was edited here.', 'showfm' );
			case self::DRAFTED:
				return __( 'Moved to draft. The episode was unpublished on show.fm.', 'showfm' );
			case self::REMOVED:
				return __( 'Moved to draft. The episode was taken off this site in show.fm.', 'showfm' );
			case self::TRASHED:
				return __( 'Moved to the bin. The episode was deleted on show.fm.', 'showfm' );
			case self::DETACHED:
				return __( 'No longer synced. This site lost access to the show on show.fm.', 'showfm' );
			case self::PAUSED:
				return __( 'Skipped. Auto-posting was paused by the show’s plan.', 'showfm' );
			case self::SKIPPED:
				return __( 'Not posted. Auto-posting is turned off.', 'showfm' );
		}
		$changes = is_array( $event['changes'] ?? null ) ? $event['changes'] : array();
		$title   = in_array( 'title', $changes, true );
		$text    = in_array( 'description', $changes, true );
		if ( $title && $text ) {
			return __( 'Updated the title and description', 'showfm' );
		}
		if ( $title ) {
			return __( 'Updated the title', 'showfm' );
		}
		if ( $text ) {
			return __( 'Updated the description', 'showfm' );
		}
		if ( in_array( 'date', $changes, true ) ) {
			return __( 'Updated the date', 'showfm' );
		}
		return __( 'Updated', 'showfm' );
	}

	/**
	 * The event's post link: Edit post (or View post for someone who can't edit it), View bin
	 * for a post in the bin, or none.
	 *
	 * @param array<string,mixed> $event Stored event.
	 * @return array{label:string,url:string}|null
	 */
	private static function link( array $event ): ?array {
		$post = get_post( (int) ( $event['post'] ?? 0 ) );
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}
		if ( 'trash' === $post->post_status ) {
			return current_user_can( 'edit_post', $post->ID ) ? array(
				'label' => __( 'View bin', 'showfm' ),
				'url'   => admin_url( 'edit.php?post_status=trash&post_type=' . $post->post_type ),
			) : null;
		}
		if ( current_user_can( 'edit_post', $post->ID ) ) {
			$url = get_edit_post_link( $post->ID, 'raw' );
			if ( is_string( $url ) && '' !== $url ) {
				return array(
					'label' => __( 'Edit post', 'showfm' ),
					'url'   => $url,
				);
			}
		}
		return 'publish' === $post->post_status ? array(
			'label' => __( 'View post', 'showfm' ),
			'url'   => (string) get_permalink( $post ),
		) : null;
	}

	/**
	 * When an event happened, in the site's time zone: "Today, 09:00", "Yesterday, 17:12", or
	 * "5 Oct, 16:20".
	 *
	 * @param int $timestamp Unix time.
	 */
	public static function when( int $timestamp ): string {
		$zone  = wp_timezone();
		$day   = ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $zone )->format( 'Y-m-d' );
		$today = new \DateTimeImmutable( 'now', $zone );
		$time  = (string) wp_date( (string) get_option( 'time_format' ), $timestamp );
		if ( $today->format( 'Y-m-d' ) === $day ) {
			/* translators: %s: time of day. */
			return sprintf( __( 'Today, %s', 'showfm' ), $time );
		}
		if ( $today->modify( '-1 day' )->format( 'Y-m-d' ) === $day ) {
			/* translators: %s: time of day. */
			return sprintf( __( 'Yesterday, %s', 'showfm' ), $time );
		}
		/* translators: 1: date, for example "5 Oct", 2: time of day. */
		return sprintf( _x( '%1$s, %2$s', 'recent activity: date, then time', 'showfm' ), (string) wp_date( _x( 'j M', 'recent activity date', 'showfm' ), $timestamp ), $time );
	}

	/**
	 * A date and time a post goes live: "14 October at 09:00".
	 *
	 * @param int $timestamp Unix time.
	 */
	private static function moment( int $timestamp ): string {
		/* translators: 1: date, for example "14 October", 2: time of day. */
		return sprintf( __( '%1$s at %2$s', 'showfm' ), (string) wp_date( _x( 'j F', 'scheduled post date', 'showfm' ), $timestamp ), (string) wp_date( (string) get_option( 'time_format' ), $timestamp ) );
	}
}
