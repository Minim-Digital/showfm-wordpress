/**
 * The inspector's first panel: what the block shows, with a Change button.
 */
import { Button } from '@wordpress/components';
import { Artwork } from './show-placeholder';

/**
 * @param {Object}     props
 * @param {string}     props.artwork     Artwork URL.
 * @param {string}     props.title       Episode or show title.
 * @param {string}     props.meta        One line under the title.
 * @param {string}     props.buttonLabel Change button text.
 * @param {() => void} props.onClick     Change.
 */
export default function EpisodeSummary( {
	artwork,
	title,
	meta,
	buttonLabel,
	onClick,
} ) {
	return (
		<div className="showfm-summary">
			<div className="showfm-summary__row">
				<Artwork src={ artwork } />
				<span className="showfm-summary__text">
					<span className="showfm-summary__title">{ title }</span>
					{ meta && <span className="showfm-muted">{ meta }</span> }
				</span>
			</div>
			<Button variant="secondary" size="compact" onClick={ onClick }>
				{ buttonLabel }
			</Button>
		</div>
	);
}
