/**
 * Connection data as `GET /showfm/v1/admin/connection` returns it.
 *
 * @param {Object} overrides Fields to replace.
 * @return {Object} Connection data.
 */
export function view( overrides = {} ) {
	return {
		state: 'connected',
		stateId: '3f2b8c1e-9a4d-4e6f-8b7c-1d2e3f4a5b6c',
		daysLeft: 300,
		site: 'https://thelongtable.co',
		account: 'Maya Lindgren',
		shows: [
			{
				id: '7c9e6679-7425-40de-944b-e07fc1f90ae7',
				title: 'The Long Table',
				address: 'the-long-table.show.fm',
				artwork: '',
			},
			{
				id: '9b2c4e1a-5f3d-4a8b-9c7e-1d2f3a4b5c6d',
				title: 'Second Helpings',
				address: 'second-helpings.show.fm',
				artwork: 'https://m.cdn.media/art.jpg',
			},
		],
		key: {
			masked: '••••7f3a',
			expiresOn: '4 October 2027',
			refusedOn: '',
		},
		lastChecked: '3 minutes ago',
		nextCheck: 0,
		result: null,
		connect: {
			url: 'https://thelongtable.co/wp-admin/admin-post.php',
			action: 'showfm_connect',
			nonce: 'abc123',
		},
		planUrl: 'https://my.show.fm/pricing',
		notice: null,
		...overrides,
	};
}

/**
 * Publishing data as `GET /showfm/v1/admin/publishing` returns it.
 *
 * @param {Object} overrides Fields to replace.
 * @return {Object} Publishing data.
 */
export function publishing( overrides = {} ) {
	return {
		settings: {
			autoPost: true,
			postType: 'post',
			category: 3,
			author: 1,
			template: '',
			transcript: true,
			featuredImage: true,
		},
		postTypes: [
			{ value: 'post', label: 'Posts', categories: true },
			{ value: 'page', label: 'Pages', categories: false },
		],
		categories: [
			{ value: 1, label: 'Uncategorised' },
			{ value: 3, label: 'Podcast' },
		],
		authors: {
			post: [
				{ value: 1, label: 'Maya Lindgren' },
				{ value: 4, label: 'Sam Author' },
			],
			page: [ { value: 1, label: 'Maya Lindgren' } ],
		},
		templates: {
			post: [
				{ value: '', label: 'Default' },
				{ value: 'single-episode.php', label: 'Single episode' },
			],
			page: [ { value: '', label: 'Default' } ],
		},
		shows: [ 'The Long Table', 'Second Helpings' ],
		problem: null,
		activity: [],
		...overrides,
	};
}

/**
 * Recent activity rows, newest first.
 *
 * @return {Object[]} Events.
 */
export function activity() {
	return [
		{
			id: '3',
			time: 'Today, 09:00',
			episode:
				'Sourdough, salt and the slow return of the village bakery',
			show: 'The Long Table',
			what: 'Posted',
			muted: false,
			link: {
				label: 'Edit post',
				url: 'https://thelongtable.co/wp-admin/post.php?post=12&action=edit',
			},
		},
		{
			id: '2',
			time: '3 Oct, 14:55',
			episode: 'A test episode',
			show: 'Second Helpings',
			what: 'Moved to the bin. The episode was deleted on show.fm.',
			muted: false,
			link: {
				label: 'View bin',
				url: 'https://thelongtable.co/wp-admin/edit.php?post_status=trash&post_type=post',
			},
		},
		{
			id: '1',
			time: '29 Sept, 10:30',
			episode: 'The spice drawer',
			show: 'Second Helpings',
			what: 'Skipped. Auto-posting was paused by the show’s plan.',
			muted: true,
			link: null,
		},
	];
}
