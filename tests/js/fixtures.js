/**
 * Connection data as `GET /showfm/v1/admin/connection` returns it.
 *
 * @param {Object} overrides Fields to replace.
 * @return {Object} Connection data.
 */
export function view( overrides = {} ) {
	return {
		state: 'connected',
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
