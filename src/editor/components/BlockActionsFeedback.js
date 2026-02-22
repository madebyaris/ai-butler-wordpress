/**
 * BlockActionsFeedback Component
 *
 * Shows visual feedback when block actions are being executed,
 * displaying a summary of what was done.
 *
 * @package ABW_AI
 */

/**
 * BlockActionsFeedback component.
 *
 * @param {Object}  props            Component props.
 * @param {Array}   props.results    Array of action results from executeBlockActions.
 * @param {boolean} props.isVisible  Whether to show the feedback.
 * @return {import('@wordpress/element').WPElement|null} Feedback element or null.
 */
export function BlockActionsFeedback( { results, isVisible } ) {
	if ( ! isVisible || ! results || results.length === 0 ) {
		return null;
	}

	const successCount = results.filter( ( r ) => r.success ).length;
	const failCount = results.filter( ( r ) => ! r.success ).length;

	return (
		<div className="abw-block-feedback">
			<div className="abw-block-feedback-header">
				<span className="abw-block-feedback-icon">
					{ failCount === 0 ? '\u2705' : '\u26A0\uFE0F' }
				</span>
				<span className="abw-block-feedback-summary">
					{ failCount === 0
						? `${ successCount } action${ successCount !== 1 ? 's' : '' } completed`
						: `${ successCount } succeeded, ${ failCount } failed`
					}
				</span>
			</div>
			<ul className="abw-block-feedback-list">
				{ results.map( ( result, index ) => (
					<li
						key={ index }
						className={ `abw-block-feedback-item ${ result.success ? 'success' : 'error' }` }
					>
						<span className="abw-feedback-status">
							{ result.success ? '\u2713' : '\u2717' }
						</span>
						<span className="abw-feedback-action">
							{ formatActionName( result.action ) }
						</span>
						{ ! result.success && result.error && (
							<span className="abw-feedback-error">{ result.error }</span>
						) }
					</li>
				) ) }
			</ul>
		</div>
	);
}

/**
 * Format an action name for display.
 *
 * @param {string} action Action type string.
 * @return {string} Human-readable name.
 */
function formatActionName( action ) {
	const names = {
		insert_blocks: 'Insert blocks',
		replace_block: 'Replace block',
		replace_all: 'Replace all content',
		update_block: 'Update block',
		remove_blocks: 'Remove blocks',
		save_post: 'Save post',
		update_post_meta: 'Update post details',
	};
	return names[ action ] || action;
}
