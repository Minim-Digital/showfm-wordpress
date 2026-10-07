<?php
/**
 * Publishing tab: the REST route, the settings in the apply step, and recent activity.
 *
 * @package ShowFM
 */

use ShowFM\Account;
use ShowFM\Connection;
use ShowFM\Ping_Endpoint;
use ShowFM\Plugin;
use ShowFM\Publishing;
use ShowFM\Sync;
use ShowFM\Sync_Activity;
use ShowFM\Sync_Posts;

/**
 * Covers `showfm/v1/admin/publishing`, `Publishing`, and `Sync_Activity` through the real
 * apply step.
 */
class Test_Publishing extends WP_UnitTestCase {

	const ROUTE   = '/showfm/v1/admin/publishing';
	const SITE    = '11111111-1111-4111-8111-111111111111';
	const EPISODE = '22222222-2222-4222-8222-222222222222';
	const PODCAST = '33333333-3333-4333-8333-333333333333';
	const KEY     = 'showfm_live_TEST_PUBLISHING_abcdefgh';

	/**
	 * Administrator.
	 *
	 * @var int
	 */
	private $admin;

	/**
	 * HTTP mock, so nothing leaves the test.
	 *
	 * @var ShowFM_Http_Mock
	 */
	private $http;

	public function set_up(): void {
		parent::set_up();
		Plugin::reset();
		$this->http = new ShowFM_Http_Mock();
		delete_option( Publishing::OPTION );
		delete_option( Sync_Activity::OPTION );
		delete_option( Sync::OPTION );
		delete_option( Connection::SYNC_STATUS_OPTION );
		$this->admin = self::factory()->user->create(
			array(
				'role'         => 'administrator',
				'display_name' => 'Maya Lindgren',
			)
		);
		if ( is_multisite() ) {
			grant_super_admin( $this->admin );
		}
		wp_set_current_user( $this->admin );
		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		$this->http->detach();
		Plugin::connect()->disconnect();
		Plugin::reset();
		unset( $_REQUEST['_wpnonce'], $GLOBALS['wp_rest_auth_cookie'] );
		parent::tear_down();
	}

	public function test_the_route_is_registered_for_get_and_post(): void {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( self::ROUTE, $routes );
		$methods = array();
		foreach ( $routes[ self::ROUTE ] as $handler ) {
			$methods = array_merge( $methods, array_keys( $handler['methods'] ) );
		}
		$this->assertEqualsCanonicalizing( array( 'GET', 'POST' ), array_unique( $methods ) );
	}

	/**
	 * @dataProvider methods
	 *
	 * @param string $method HTTP method.
	 */
	public function test_users_who_cannot_manage_options_are_refused( string $method ): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$response = $this->dispatch( $method, array( 'autoPost' => false ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertTrue( Publishing::settings()['auto_post'], 'Nothing was saved.' );
	}

	/**
	 * @dataProvider methods
	 *
	 * @param string $method HTTP method.
	 */
	public function test_logged_out_requests_are_refused( string $method ): void {
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->dispatch( $method, array( 'autoPost' => false ) )->get_status() );
		$this->assertFalse( get_option( Publishing::OPTION ) );
	}

	/**
	 * Both methods.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function methods(): array {
		return array(
			'GET'  => array( 'GET' ),
			'POST' => array( 'POST' ),
		);
	}

	public function test_a_cookie_request_without_the_rest_nonce_runs_logged_out_and_saves_nothing(): void {
		$GLOBALS['wp_rest_auth_cookie'] = true;

		$this->assertTrue( rest_cookie_check_errors( null ) );

		$this->assertSame( 0, get_current_user_id() );
		$this->assertSame( 401, $this->dispatch( 'POST', array( 'autoPost' => false ) )->get_status() );
		$this->assertFalse( get_option( Publishing::OPTION ) );
	}

	public function test_a_cookie_request_with_the_rest_nonce_is_accepted(): void {
		$GLOBALS['wp_rest_auth_cookie'] = true;
		$_REQUEST['_wpnonce']           = wp_create_nonce( 'wp_rest' );

		$this->assertTrue( rest_cookie_check_errors( null ) );

		$this->assertSame( $this->admin, get_current_user_id() );
		$this->assertSame( 200, $this->dispatch( 'POST', array( 'autoPost' => false ) )->get_status() );
		$this->assertFalse( Publishing::settings()['auto_post'] );
	}

	public function test_get_returns_the_defaults_and_the_choices(): void {
		$response = $this->dispatch( 'GET' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'no-store, private', $response->get_headers()['Cache-Control'] );
		$this->assertSame(
			array(
				'autoPost'      => true,
				'postType'      => 'post',
				'category'      => 0,
				'author'        => Publishing::default_author( 'post' ),
				'template'      => '',
				'transcript'    => true,
				'featuredImage' => true,
			),
			$data['settings']
		);

		$types = wp_list_pluck( $data['postTypes'], 'value' );
		$this->assertContains( 'post', $types );
		$this->assertContains( 'page', $types );
		$this->assertNotContains( 'attachment', $types );
		$this->assertTrue( $data['postTypes'][ array_search( 'post', $types, true ) ]['categories'] );
		$this->assertFalse( $data['postTypes'][ array_search( 'page', $types, true ) ]['categories'] );

		$this->assertContains( $this->admin, wp_list_pluck( $data['authors']['post'], 'value' ) );
		$this->assertSame(
			array(
				'value' => '',
				'label' => 'Default',
			),
			$data['templates']['post'][0]
		);
		$this->assertContains( (int) get_option( 'default_category' ), wp_list_pluck( $data['categories'], 'value' ) );
		$this->assertSame( array(), $data['activity'] );
		$this->assertNull( $data['problem'] );
	}

	public function test_post_types_without_the_editor_or_not_public_are_not_offered(): void {
		register_post_type(
			'showfm_no_editor',
			array(
				'public'       => true,
				'show_in_rest' => true,
				'supports'     => array( 'title' ),
			)
		);
		register_post_type(
			'showfm_private',
			array(
				'public'       => false,
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor' ),
			)
		);
		register_post_type(
			'showfm_classic',
			array(
				'public'   => true,
				'supports' => array( 'title', 'editor' ),
			)
		);

		$types = wp_list_pluck( $this->dispatch( 'GET' )->get_data()['postTypes'], 'value' );

		$this->assertNotContains( 'showfm_no_editor', $types );
		$this->assertNotContains( 'showfm_private', $types );
		$this->assertNotContains( 'showfm_classic', $types, 'Not in the REST API, so not in the block editor.' );

		foreach ( array( 'showfm_no_editor', 'showfm_private', 'showfm_classic', 'attachment', 'nonsense' ) as $type ) {
			$response = $this->dispatch( 'POST', array( 'postType' => $type ) );
			$this->assertSame( 400, $response->get_status(), $type );
			$this->assertSame( 'postType', $response->get_data()['data']['field'], $type );
		}
		unregister_post_type( 'showfm_no_editor' );
		unregister_post_type( 'showfm_private' );
		unregister_post_type( 'showfm_classic' );
	}

	public function test_post_saves_every_setting(): void {
		$category = self::factory()->category->create( array( 'name' => 'Podcast' ) );
		$author   = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->add_template();

		$response = $this->dispatch(
			'POST',
			array(
				'autoPost'      => false,
				'postType'      => 'post',
				'category'      => $category,
				'author'        => $author,
				'template'      => 'single-episode.php',
				'transcript'    => false,
				'featuredImage' => false,
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['saved'] );
		$this->assertSame(
			array(
				'auto_post'      => false,
				'post_type'      => 'post',
				'category'       => $category,
				'author'         => $author,
				'template'       => 'single-episode.php',
				'transcript'     => false,
				'featured_image' => false,
			),
			get_option( Publishing::OPTION )
		);
		$this->assertSame( 'single-episode.php', $response->get_data()['settings']['template'] );
	}

	public function test_post_keeps_fields_that_were_left_out(): void {
		$this->dispatch( 'POST', array( 'transcript' => false ) );
		$this->dispatch( 'POST', array( 'featuredImage' => false ) );

		$settings = Publishing::settings();
		$this->assertFalse( $settings['transcript'] );
		$this->assertFalse( $settings['featured_image'] );
		$this->assertTrue( $settings['auto_post'] );
	}

	public function test_post_refuses_an_author_who_cannot_publish_the_post_type(): void {
		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$editor      = self::factory()->user->create( array( 'role' => 'editor' ) );

		foreach ( array( $contributor, 999999 ) as $author ) {
			$response = $this->dispatch( 'POST', array( 'author' => $author ) );
			$this->assertSame( 400, $response->get_status() );
			$this->assertSame( 'author', $response->get_data()['data']['field'] );
		}
		$this->assertFalse( get_option( Publishing::OPTION ) );

		$this->assertSame( 200, $this->dispatch( 'POST', array( 'author' => $editor ) )->get_status() );
		$this->assertSame( $editor, Publishing::settings()['author'] );
	}

	public function test_an_author_must_be_able_to_publish_the_chosen_post_type(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );

		$response = $this->dispatch(
			'POST',
			array(
				'postType' => 'page',
				'author'   => $author,
			)
		);

		$this->assertSame( 400, $response->get_status(), 'Authors can publish posts but not pages.' );
		$this->assertSame( 'author', $response->get_data()['data']['field'] );
		$this->assertContains( $author, wp_list_pluck( $this->dispatch( 'GET' )->get_data()['authors']['post'], 'value' ) );
		$this->assertNotContains( $author, wp_list_pluck( $this->dispatch( 'GET' )->get_data()['authors']['page'], 'value' ) );
	}

	public function test_post_refuses_a_category_that_does_not_exist(): void {
		$tag = self::factory()->tag->create();

		foreach ( array( 999999, $tag ) as $category ) {
			$response = $this->dispatch( 'POST', array( 'category' => $category ) );
			$this->assertSame( 400, $response->get_status() );
			$this->assertSame( 'category', $response->get_data()['data']['field'] );
		}
	}

	public function test_a_post_type_without_categories_drops_the_category(): void {
		$category = self::factory()->category->create();

		$response = $this->dispatch(
			'POST',
			array(
				'postType' => 'page',
				'category' => $category,
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, Publishing::settings()['category'] );
	}

	public function test_post_refuses_a_template_the_theme_does_not_offer(): void {
		$response = $this->dispatch( 'POST', array( 'template' => 'missing.php' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'template', $response->get_data()['data']['field'] );
	}

	public function test_post_sanitises_and_type_checks_its_fields(): void {
		$response = $this->dispatch( 'POST', array( 'postType' => 'Post<script>' ) );
		$this->assertSame( 400, $response->get_status(), 'Sanitised to "postscript", which is no post type.' );

		$this->assertSame( 400, $this->dispatch( 'POST', array( 'autoPost' => 'sometimes' ) )->get_status() );
		$this->assertSame( 400, $this->dispatch( 'POST', array( 'category' => -1 ) )->get_status() );
		$this->assertSame( 400, $this->dispatch( 'POST', array( 'author' => 'admin' ) )->get_status() );

		$this->assertSame( 200, $this->dispatch( 'POST', array( 'autoPost' => 'false' ) )->get_status() );
		$this->assertFalse( Publishing::settings()['auto_post'] );

		$this->add_template();
		$this->assertSame( 200, $this->dispatch( 'POST', array( 'template' => ' <b>single-episode.php</b> ' ) )->get_status() );
		$this->assertSame( 'single-episode.php', Publishing::settings()['template'] );
	}

	public function test_get_surfaces_a_sync_configuration_problem_and_saving_retries_now(): void {
		$this->connect();
		$this->stuck( 'row_author' );

		$problem = $this->dispatch( 'GET' )->get_data()['problem'];
		$this->assertSame( 'row_author', $problem['code'] );
		$this->assertSame( 'author', $problem['field'] );
		$this->assertStringContainsString( 'New episodes aren’t being posted here', $problem['message'] );

		$this->stuck( 'row_post_type' );
		$this->assertSame( 'postType', $this->dispatch( 'GET' )->get_data()['problem']['field'] );

		$before = (int) get_option( Sync::RETRY_OPTION, 0 );
		$data   = $this->dispatch( 'POST', array( 'postType' => 'post' ) )->get_data();

		$this->assertNull( $data['problem'] );
		$this->assertSame( $before + 1, (int) get_option( Sync::RETRY_OPTION ), 'A durable retry request is recorded.' );
		$this->assertLessThanOrEqual( time(), wp_next_scheduled( Ping_Endpoint::PULL_HOOK ), 'A pull is queued now.' );
		$this->assertSame( 0, $this->http->count(), 'Saving never calls show.fm.' );
	}

	public function test_get_names_the_connected_shows_and_never_sends_the_key(): void {
		$this->connect();

		$data = $this->dispatch( 'GET' )->get_data();

		$this->assertSame( array( 'The Long Table', 'Second Helpings' ), $data['shows'] );
		$this->assertStringNotContainsString( self::KEY, wp_json_encode( $data ) );
	}

	public function test_a_new_post_uses_the_settings(): void {
		$category = self::factory()->category->create( array( 'name' => 'Podcast' ) );
		$author   = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->add_template();
		$this->save(
			array(
				'post_type'      => 'post',
				'category'       => $category,
				'author'         => $author,
				'template'       => 'single-episode.php',
				'transcript'     => true,
				'featured_image' => false,
			)
		);

		$post = get_post( $this->apply( $this->row() ) );

		$this->assertSame( 'post', $post->post_type );
		$this->assertSame( $author, (int) $post->post_author );
		$this->assertSame( array( $category ), wp_get_post_categories( $post->ID ) );
		$this->assertSame( 'single-episode.php', get_post_meta( $post->ID, '_wp_page_template', true ) );
		$this->assertStringContainsString( '<!-- wp:showfm/transcript {"episode":"' . self::EPISODE . '"} /-->', $post->post_content );
		$this->assertLessThan( strpos( $post->post_content, 'wp:showfm/transcript' ), strpos( $post->post_content, 'wp:showfm/player' ), 'The transcript goes under the player.' );
		$this->assertSame(
			array(
				'transcript'     => true,
				'featured_image' => false,
			),
			get_post_meta( $post->ID, '_showfm_post_options', true )
		);
	}

	public function test_a_new_post_can_be_a_page(): void {
		$this->save(
			array(
				'post_type'      => 'page',
				'transcript'     => false,
				'featured_image' => false,
			)
		);

		$post = get_post( $this->apply( $this->row() ) );

		$this->assertSame( 'page', $post->post_type );
		$this->assertStringNotContainsString( 'wp:showfm/transcript', $post->post_content );
	}

	public function test_a_template_the_theme_dropped_is_skipped_rather_than_failing_the_row(): void {
		$this->add_template();
		$this->save(
			array(
				'template'       => 'single-episode.php',
				'featured_image' => false,
			)
		);
		remove_all_filters( 'theme_post_templates' );

		$id = $this->apply( $this->row() );

		$this->assertSame( '', get_post_meta( $id, '_wp_page_template', true ) );
	}

	public function test_auto_posting_off_creates_no_post_but_keeps_updating_existing_ones(): void {
		$this->save( array( 'featured_image' => false ) );
		$id = $this->apply( $this->row() );

		$this->save(
			array(
				'auto_post'      => false,
				'featured_image' => false,
			)
		);
		$other                  = $this->row( 2 );
		$other['episode_id']    = '44444444-4444-4444-8444-444444444444';
		$other['episode']['id'] = $other['episode_id'];

		$this->assertSame( 0, ( new Sync_Posts() )->apply( self::SITE, $other ), 'No new post.' );
		$this->assertSame( 0, Sync_Posts::find( self::SITE, $other['episode_id'] ) );

		$update                     = $this->row( 3 );
		$update['episode']['title'] = 'A new title';
		$this->assertSame( $id, $this->apply( $update ) );
		$this->assertSame( 'A new title', get_post( $id )->post_title, 'Posts that already exist keep updating.' );

		$events = Sync_Activity::entries();
		$this->assertSame( Sync_Activity::SKIPPED, $events[1]['event'] );
		$this->assertSame( 0, $events[1]['post'] );
	}

	public function test_changing_the_settings_never_rewrites_an_existing_post(): void {
		$first  = self::factory()->category->create();
		$second = self::factory()->category->create();
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->save(
			array(
				'category'       => $first,
				'transcript'     => true,
				'featured_image' => false,
			)
		);
		$id     = $this->apply( $this->row() );
		$before = get_post( $id );

		$this->add_template();
		$this->save(
			array(
				'post_type'      => 'page',
				'category'       => $second,
				'author'         => $editor,
				'template'       => 'single-episode.php',
				'transcript'     => false,
				'featured_image' => true,
			)
		);
		$update                           = $this->row( 2 );
		$update['episode']['description'] = 'A new description';
		$this->apply( $update );
		$after = get_post( $id );

		$this->assertSame( 'post', $after->post_type );
		$this->assertSame( (int) $before->post_author, (int) $after->post_author );
		$this->assertSame( array( $first ), wp_get_post_categories( $id ) );
		$this->assertSame( '', get_post_meta( $id, '_wp_page_template', true ) );
		$this->assertStringContainsString( 'wp:showfm/transcript', $after->post_content, 'Created with the transcript, so it keeps it.' );
		$this->assertSame( 'A new description', $after->post_excerpt );
		$this->assertFalse( Publishing::options_of( $id )['featured_image'] );
		$this->assertSame( 0, $this->http->count(), 'No artwork fetched for a post created without it.' );
	}

	public function test_a_post_type_that_disappears_holds_the_row_as_a_configuration_problem(): void {
		$this->save( array( 'featured_image' => false ) );
		update_option( Publishing::OPTION, array_merge( get_option( Publishing::OPTION ), array( 'post_type' => 'gone' ) ) );

		$result = ( new Sync_Posts() )->apply( self::SITE, $this->row() );

		$this->assertWPError( $result );
		$this->assertSame( 'showfm_post_type', $result->get_error_code() );
	}

	public function test_activity_records_each_kind_of_change(): void {
		$this->save( array( 'featured_image' => false ) );

		$scheduled = $this->row( 1, 'scheduled' );
		$id        = $this->apply( $scheduled );
		$this->apply( $scheduled ); // The same row again changes nothing.
		$this->apply( $this->row( 2 ) );
		$update                     = $this->row( 3 );
		$update['episode']['title'] = 'Renamed';
		$this->apply( $update );
		$this->apply( $this->row( 4, 'published', 'unpublished' ) );
		$this->apply( $this->row( 5 ) );
		$link = Sync_Activity::view( array() )[0]['link'];
		$this->assertSame( 'Edit post', $link['label'] );
		$this->assertSame( get_edit_post_link( $id, 'raw' ), $link['url'] );
		$this->apply( $this->row( 6, 'published', 'deleted' ) );

		$events = wp_list_pluck( Sync_Activity::entries(), 'event' );
		$this->assertSame(
			array( Sync_Activity::SCHEDULED, Sync_Activity::POSTED, Sync_Activity::UPDATED, Sync_Activity::DRAFTED, Sync_Activity::POSTED, Sync_Activity::TRASHED ),
			$events
		);
		$this->assertSame( strtotime( $scheduled['episode']['scheduled_for'] ), Sync_Activity::entries()[0]['date'] );
		$this->assertSame( array( 'title' ), Sync_Activity::entries()[2]['changes'] );

		$view = Sync_Activity::view( array( self::PODCAST => 'The Long Table' ) );
		$this->assertSame( 'Moved to the bin. The episode was deleted on show.fm.', $view[0]['what'] );
		$this->assertSame( 'View bin', $view[0]['link']['label'] );
		$this->assertSame( admin_url( 'edit.php?post_status=trash&post_type=post' ), $view[0]['link']['url'] );
		$this->assertSame( 'Moved to draft. The episode was unpublished on show.fm.', $view[2]['what'] );
		$this->assertSame( 'Updated the title', $view[3]['what'] );
		$this->assertSame( 'Posted', $view[4]['what'] );
		$this->assertStringStartsWith( 'Scheduled for ', $view[5]['what'] );
		$this->assertSame( 'View bin', $view[5]['link']['label'], 'Links follow where the post is now.' );
		$this->assertSame( 'The Long Table', $view[5]['show'] );
		$this->assertSame( "Episode 'one'", $view[5]['episode'] );
		$this->assertFalse( $view[5]['muted'] );
	}

	public function test_activity_after_an_edit_here_says_only_the_date_and_status_changed(): void {
		$this->save( array( 'featured_image' => false ) );
		$id = $this->apply( $this->row() );
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'My own title',
			)
		);
		$this->apply( $this->row( 2 ) );

		$view = Sync_Activity::view( array() );
		$this->assertSame( 'Updated the date and status only. The post was edited here.', $view[0]['what'] );
	}

	public function test_activity_records_a_plan_pause_even_without_a_post(): void {
		$this->apply_tombstone( 'plan_or_policy' );

		$view = Sync_Activity::view( array() );
		$this->assertSame( 'Skipped. Auto-posting was paused by the show’s plan.', $view[0]['what'] );
		$this->assertNull( $view[0]['link'] );
		$this->assertTrue( $view[0]['muted'] );
	}

	public function test_activity_records_a_detached_post_once(): void {
		$this->save( array( 'featured_image' => false ) );
		$this->apply( $this->row() );
		$this->apply_tombstone( 'access_removed' );
		$this->apply_tombstone( 'access_removed' );

		$events = wp_list_pluck( Sync_Activity::entries(), 'event' );
		$this->assertSame( array( Sync_Activity::POSTED, Sync_Activity::DETACHED ), $events );
	}

	public function test_activity_keeps_the_last_20_events(): void {
		for ( $i = 1; $i <= 25; ++$i ) {
			Sync_Activity::record(
				Sync_Activity::POSTED,
				array(
					'episode_id' => self::EPISODE,
					'episode'    => array( 'title' => 'Episode ' . $i ),
				),
				0
			);
		}

		$entries = Sync_Activity::entries();
		$this->assertCount( 20, $entries );
		$this->assertSame( 'Episode 6', $entries[0]['title'] );
		$this->assertSame( 'Episode 25', Sync_Activity::view( array() )[0]['episode'], 'Newest first.' );
		$this->assertFalse( wp_load_alloptions()[ Sync_Activity::OPTION ] ?? false, 'Not autoloaded.' );
	}

	public function test_activity_keeps_a_plain_bounded_title(): void {
		Sync_Activity::record(
			Sync_Activity::POSTED,
			array(
				'episode_id' => self::EPISODE,
				'episode'    => array( 'title' => '<b>Bold</b>' . str_repeat( 'x', 300 ) ),
			),
			0
		);

		$title = Sync_Activity::entries()[0]['title'];
		$this->assertStringStartsWith( 'Bold', $title );
		$this->assertSame( Sync_Activity::MAX_TITLE, preg_match_all( '/./su', $title ) );
	}

	public function test_activity_titles_are_cut_by_character_without_mbstring(): void {
		ShowFM\Text::$mbstring = false;
		try {
			Sync_Activity::record(
				Sync_Activity::POSTED,
				array(
					'episode_id' => self::EPISODE,
					'episode'    => array( 'title' => str_repeat( 'é', Sync_Activity::MAX_TITLE + 20 ) ),
				),
				0
			);
		} finally {
			ShowFM\Text::$mbstring = null;
		}

		$this->assertSame( str_repeat( 'é', Sync_Activity::MAX_TITLE ), Sync_Activity::entries()[0]['title'] );
	}

	public function test_not_posted_is_recorded_once_per_episode_until_something_else_happens(): void {
		$this->save(
			array(
				'auto_post'      => false,
				'featured_image' => false,
			)
		);
		for ( $seq = 1; $seq <= 30; ++$seq ) {
			$this->assertSame( 0, ( new Sync_Posts() )->apply( self::SITE, $this->row( $seq ) ) );
		}
		$this->assertSame( array( Sync_Activity::SKIPPED ), wp_list_pluck( Sync_Activity::entries(), 'event' ), 'Thirty edits, one entry.' );

		$other                  = $this->row( 31 );
		$other['episode_id']    = '44444444-4444-4444-8444-444444444444';
		$other['episode']['id'] = $other['episode_id'];
		( new Sync_Posts() )->apply( self::SITE, $other );
		$this->assertCount( 2, Sync_Activity::entries(), 'Another episode gets its own entry.' );

		// Something else happens to the first episode, then it is skipped again.
		$this->apply_tombstone( 'plan_or_policy' );
		( new Sync_Posts() )->apply( self::SITE, $this->row( 40 ) );
		$this->assertSame(
			array( Sync_Activity::SKIPPED, Sync_Activity::SKIPPED, Sync_Activity::PAUSED, Sync_Activity::SKIPPED ),
			wp_list_pluck( Sync_Activity::entries(), 'event' )
		);
	}

	public function test_a_stored_author_who_can_no_longer_publish_shows_as_the_default(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->save( array( 'author' => $author ) );
		( new WP_User( $author ) )->set_role( 'subscriber' );

		$data = $this->dispatch( 'GET' )->get_data();

		$this->assertSame( Publishing::default_author( 'post' ), $data['settings']['author'] );
		$this->assertContains( $data['settings']['author'], wp_list_pluck( $data['authors']['post'], 'value' ), 'The select can show it.' );
		$this->assertSame( 200, $this->dispatch( 'POST', $data['settings'] )->get_status(), 'Saving what is shown works.' );
	}

	public function test_a_valid_stored_author_beyond_the_listed_names_is_offered(): void {
		for ( $i = 0; $i < Publishing::MAX_AUTHORS; ++$i ) {
			self::factory()->user->create(
				array(
					'role'         => 'editor',
					'display_name' => sprintf( 'Aaron %03d', $i ),
				)
			);
		}
		$zed = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'Zed Last',
			)
		);
		$this->save( array( 'author' => $zed ) );

		$data = $this->dispatch( 'GET' )->get_data();

		$this->assertSame( $zed, $data['settings']['author'] );
		$this->assertContains( $zed, wp_list_pluck( $data['authors']['post'], 'value' ) );
	}

	public function test_activity_times_read_today_yesterday_and_a_date(): void {
		update_option( 'time_format', 'H:i' );
		$now = time();

		$this->assertSame( 'Today, ' . wp_date( 'H:i', $now ), Sync_Activity::when( $now ) );
		$yesterday = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( '-1 day' )->setTime( 12, 0 )->getTimestamp();
		$this->assertSame( 'Yesterday, 12:00', Sync_Activity::when( $yesterday ) );
		$this->assertSame( wp_date( 'j M', $now - 10 * DAY_IN_SECONDS ) . ', ' . wp_date( 'H:i', $now - 10 * DAY_IN_SECONDS ), Sync_Activity::when( $now - 10 * DAY_IN_SECONDS ) );
	}

	public function test_an_activity_link_respects_who_is_looking(): void {
		$this->save( array( 'featured_image' => false ) );
		$this->apply( $this->row() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$link = Sync_Activity::view( array() )[0]['link'];

		$this->assertSame( 'View post', $link['label'] );
	}

	public function test_the_activity_reaches_the_tab(): void {
		$this->save( array( 'featured_image' => false ) );
		$this->apply( $this->row() );

		$activity = $this->dispatch( 'GET' )->get_data()['activity'];

		$this->assertCount( 1, $activity );
		$this->assertSame( 'Posted', $activity[0]['what'] );
	}

	/**
	 * Dispatches a request to the route.
	 *
	 * @param string              $method HTTP method.
	 * @param array<string,mixed> $body   Body for a POST.
	 */
	private function dispatch( string $method, array $body = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, self::ROUTE );
		if ( 'POST' === $method ) {
			$request->set_body_params( $body );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Stores settings directly, over the defaults.
	 *
	 * @param array<string,mixed> $settings Settings.
	 */
	private function save( array $settings ): void {
		update_option( Publishing::OPTION, array_merge( Publishing::DEFAULTS, $settings ) );
	}

	/** Offers a "Single episode" template for posts. */
	private function add_template(): void {
		add_filter(
			'theme_post_templates',
			static function ( array $templates ): array {
				return array_merge( $templates, array( 'single-episode.php' => 'Single episode' ) );
			}
		);
	}

	/** Stores a connection with two shows. */
	private function connect(): void {
		$this->assertTrue( Plugin::connection()->save( self::KEY, str_repeat( 'a', 64 ), self::SITE, 0 ) );
		update_option(
			Account::OPTION,
			array(
				'state' => Connection::state_id(),
				'site'  => self::SITE,
				'name'  => 'Maya Lindgren',
				'shows' => array(
					array(
						'id'    => self::PODCAST,
						'title' => 'The Long Table',
						'slug'  => 'the-long-table',
					),
					array(
						'id'    => '55555555-5555-4555-8555-555555555555',
						'title' => 'Second Helpings',
						'slug'  => 'second-helpings',
					),
				),
			)
		);
	}

	/**
	 * Holds a row on a configuration problem, as the sync does, with a back-off.
	 *
	 * @param string $code Problem code.
	 */
	private function stuck( string $code ): void {
		Plugin::connection()->save_sync_status(
			array(
				'site'    => self::SITE,
				'retry'   => array(
					'seq'      => 1,
					'code'     => $code,
					'attempts' => 0,
					'at'       => time(),
				),
				'skipped' => array(),
			)
		);
		update_option(
			Sync::OPTION,
			array_merge(
				Sync::state(),
				array(
					'site'     => self::SITE,
					'failures' => 4,
					'retry_at' => time() + 600,
					'error'    => $code,
				)
			)
		);
	}

	/**
	 * A feed row.
	 *
	 * @param int         $seq    Sequence.
	 * @param string      $status Episode status.
	 * @param string|null $reason Tombstone reason.
	 * @return array<string,mixed>
	 */
	private function row( int $seq = 1, string $status = 'published', ?string $reason = null ): array {
		return array(
			'seq'        => $seq,
			'episode_id' => self::EPISODE,
			'podcast_id' => self::PODCAST,
			'action'     => $reason ? 'tombstone' : 'upsert',
			'reason'     => $reason,
			'episode'    => $reason ? null : array(
				'id'            => self::EPISODE,
				'podcast_id'    => self::PODCAST,
				'title'         => "Episode 'one'",
				'description'   => 'Description',
				'status'        => $status,
				'published_at'  => '2025-01-01T12:00:00Z',
				'scheduled_for' => gmdate( 'c', time() + 86400 + $seq * 3600 ),
				'content_hash'  => 'sha256:row' . $seq,
			),
		);
	}

	/**
	 * Applies a row and returns its post.
	 *
	 * @param array<string,mixed> $row Row.
	 */
	private function apply( array $row ): int {
		$id = ( new Sync_Posts() )->apply( self::SITE, $row );
		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );
		return $id;
	}

	/**
	 * Applies a tombstone.
	 *
	 * @param string $reason Reason.
	 */
	private function apply_tombstone( string $reason ): void {
		$result = ( new Sync_Posts() )->apply( self::SITE, $this->row( 9, 'published', $reason ) );
		$this->assertIsInt( $result );
	}
}
