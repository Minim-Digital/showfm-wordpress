/**
 * Dismisses a show.fm admin notice for the current user. WordPress adds the dismiss
 * button to `.is-dismissible` notices; this records the click so the notice stays hidden
 * until its next stage.
 */
import apiFetch from '@wordpress/api-fetch';

document.addEventListener( 'click', ( event ) => {
	const button = event.target.closest?.( '.showfm-notice .notice-dismiss' );
	const notice = button?.closest( '[data-showfm-notice]' );
	if ( ! notice ) {
		return;
	}
	apiFetch( {
		path: '/showfm/v1/admin/notices/dismiss',
		method: 'POST',
		data: { key: notice.dataset.showfmNotice },
	} ).catch( () => {} );
} );
