/**
 * Block Actions Executor
 *
 * Receives structured block_actions from AI responses and executes them
 * against the WordPress block editor via wp.data dispatch.
 *
 * @package ABW_AI
 */

import { dispatch, select } from '@wordpress/data';
import { rawHandler, createBlock, parse } from '@wordpress/blocks';

/**
 * Execute a list of block actions sequentially.
 *
 * @param {Array}    actions  Array of action objects from AI response.
 * @param {Function} onStatus Callback for status updates: ({ action, index, total, status, message }).
 * @return {Promise<Array>} Array of results per action.
 */
export async function executeBlockActions( actions, onStatus = () => {} ) {
	const results = [];

	for ( let i = 0; i < actions.length; i++ ) {
		const action = actions[ i ];
		const actionType = action.action;

		onStatus( {
			action: actionType,
			index: i,
			total: actions.length,
			status: 'executing',
			message: getActionDescription( actionType ),
		} );

		try {
			const result = await executeSingleAction( action );
			results.push( { action: actionType, success: true, result } );

			onStatus( {
				action: actionType,
				index: i,
				total: actions.length,
				status: 'completed',
				message: `${ getActionDescription( actionType ) } - done`,
			} );
		} catch ( error ) {
			results.push( { action: actionType, success: false, error: error.message } );

			onStatus( {
				action: actionType,
				index: i,
				total: actions.length,
				status: 'error',
				message: `${ getActionDescription( actionType ) } - failed: ${ error.message }`,
			} );
		}

		// Small delay between actions for visual feedback.
		if ( i < actions.length - 1 ) {
			await sleep( 200 );
		}
	}

	return results;
}

/**
 * Execute a single block action.
 *
 * @param {Object} action Action object.
 * @return {Promise<*>} Action result.
 */
async function executeSingleAction( action ) {
	const blockEditor = dispatch( 'core/block-editor' );
	const editor = dispatch( 'core/editor' );
	const blockEditorSelect = select( 'core/block-editor' );

	switch ( action.action ) {
		case 'insert_blocks': {
			const blocks = htmlToBlocks( action.html || '' );
			if ( blocks.length === 0 ) {
				throw new Error( 'No valid blocks generated from HTML' );
			}

			const position = resolvePosition( action.position, blockEditorSelect );
			blockEditor.insertBlocks( blocks, position.index, position.rootClientId, true );
			return { inserted: blocks.length };
		}

		case 'replace_block': {
			const blocks = htmlToBlocks( action.html || '' );
			if ( blocks.length === 0 ) {
				throw new Error( 'No valid blocks generated from HTML' );
			}

			const allBlocks = blockEditorSelect.getBlocks();
			const blockIndex = parseInt( action.block_index, 10 );
			if ( blockIndex < 0 || blockIndex >= allBlocks.length ) {
				throw new Error( `Block index ${ blockIndex } out of range (0-${ allBlocks.length - 1 })` );
			}

			const targetClientId = allBlocks[ blockIndex ].clientId;
			blockEditor.replaceBlock( targetClientId, blocks );
			return { replaced: blockIndex, newBlocks: blocks.length };
		}

		case 'replace_all': {
			const blocks = htmlToBlocks( action.html || '' );
			if ( blocks.length === 0 ) {
				// Insert at least an empty paragraph.
				const emptyBlock = createBlock( 'core/paragraph' );
				blockEditor.resetBlocks( [ emptyBlock ] );
				return { reset: true, blocks: 1 };
			}

			blockEditor.resetBlocks( blocks );
			return { reset: true, blocks: blocks.length };
		}

		case 'update_block': {
			const allBlocks = blockEditorSelect.getBlocks();
			const blockIndex = parseInt( action.block_index, 10 );
			if ( blockIndex < 0 || blockIndex >= allBlocks.length ) {
				throw new Error( `Block index ${ blockIndex } out of range (0-${ allBlocks.length - 1 })` );
			}

			const targetClientId = allBlocks[ blockIndex ].clientId;
			const attributes = action.attributes || {};

			blockEditor.updateBlockAttributes( targetClientId, attributes );
			return { updated: blockIndex, attributes: Object.keys( attributes ) };
		}

		case 'remove_blocks': {
			const allBlocks = blockEditorSelect.getBlocks();
			const indices = ( action.block_indices || [] )
				.map( ( i ) => parseInt( i, 10 ) )
				.filter( ( i ) => i >= 0 && i < allBlocks.length )
				.sort( ( a, b ) => b - a ); // Remove from end to preserve indices.

			if ( indices.length === 0 ) {
				throw new Error( 'No valid block indices to remove' );
			}

			const clientIds = indices.map( ( i ) => allBlocks[ i ].clientId );
			blockEditor.removeBlocks( clientIds );
			return { removed: indices };
		}

		case 'save_post': {
			await editor.savePost();
			// Wait for save to complete.
			await waitForSave();
			return { saved: true };
		}

		case 'update_post_meta': {
			const edits = {};
			if ( action.title !== undefined ) {
				edits.title = action.title;
			}
			if ( action.status !== undefined ) {
				edits.status = action.status;
			}
			if ( action.excerpt !== undefined ) {
				edits.excerpt = action.excerpt;
			}

			if ( Object.keys( edits ).length > 0 ) {
				editor.editPost( edits );
			}
			return { updated: Object.keys( edits ) };
		}

		default:
			throw new Error( `Unknown block action: ${ action.action }` );
	}
}

/**
 * Convert HTML string to WordPress blocks using rawHandler.
 *
 * @param {string} html HTML string.
 * @return {Array} Array of block objects.
 */
function htmlToBlocks( html ) {
	if ( ! html || ! html.trim() ) {
		return [];
	}

	// Check if the HTML is already in block markup format (has <!-- wp: delimiters).
	if ( html.includes( '<!-- wp:' ) ) {
		return parse( html );
	}

	// Use rawHandler for standard HTML - this is what Gutenberg uses for paste.
	return rawHandler( { HTML: html } );
}

/**
 * Resolve a position string to an insertBlocks-compatible position.
 *
 * @param {string|number} position "start", "end", "after:N", or a number.
 * @param {Object}        blockEditorSelect The block editor select store.
 * @return {Object} { index, rootClientId }.
 */
function resolvePosition( position, blockEditorSelect ) {
	const allBlocks = blockEditorSelect.getBlocks();

	if ( position === 'start' || position === 0 ) {
		return { index: 0, rootClientId: undefined };
	}

	if ( position === 'end' || position === undefined || position === null ) {
		return { index: allBlocks.length, rootClientId: undefined };
	}

	// "after:N" format - insert after block at index N.
	if ( typeof position === 'string' && position.startsWith( 'after:' ) ) {
		const afterIndex = parseInt( position.split( ':' )[ 1 ], 10 );
		return { index: Math.min( afterIndex + 1, allBlocks.length ), rootClientId: undefined };
	}

	// Direct numeric index.
	const numPos = parseInt( position, 10 );
	if ( ! isNaN( numPos ) ) {
		return { index: Math.min( Math.max( 0, numPos ), allBlocks.length ), rootClientId: undefined };
	}

	// Default to end.
	return { index: allBlocks.length, rootClientId: undefined };
}

/**
 * Wait for the post save operation to complete.
 *
 * @return {Promise<void>}
 */
function waitForSave() {
	return new Promise( ( resolve ) => {
		const editorSelect = select( 'core/editor' );

		// If not saving, resolve immediately.
		if ( ! editorSelect.isSavingPost() ) {
			resolve();
			return;
		}

		// Poll until save completes.
		const checkInterval = setInterval( () => {
			if ( ! editorSelect.isSavingPost() ) {
				clearInterval( checkInterval );
				resolve();
			}
		}, 200 );

		// Timeout after 15 seconds.
		setTimeout( () => {
			clearInterval( checkInterval );
			resolve();
		}, 15000 );
	} );
}

/**
 * Get a human-readable description for an action type.
 *
 * @param {string} actionType Action type string.
 * @return {string} Description.
 */
function getActionDescription( actionType ) {
	const descriptions = {
		insert_blocks: 'Inserting blocks',
		replace_block: 'Replacing block',
		replace_all: 'Replacing all content',
		update_block: 'Updating block',
		remove_blocks: 'Removing blocks',
		save_post: 'Saving post',
		update_post_meta: 'Updating post details',
	};
	return descriptions[ actionType ] || `Executing ${ actionType }`;
}

/**
 * Utility sleep function.
 *
 * @param {number} ms Milliseconds to sleep.
 * @return {Promise<void>}
 */
function sleep( ms ) {
	return new Promise( ( resolve ) => setTimeout( resolve, ms ) );
}
