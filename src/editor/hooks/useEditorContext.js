/**
 * useEditorContext Hook
 *
 * Reads the current block editor state and serializes it into a text
 * representation for the AI model to understand.
 *
 * @package ABW_AI
 */

import { useSelect } from '@wordpress/data';
import { useMemo } from '@wordpress/element';
import { serializeEditorContext } from '../utils/block-serializer';

/**
 * Hook to get the current editor context as a serialized text string.
 *
 * @return {Object} { editorContext, postInfo, blocks, isReady }
 */
export function useEditorContext() {
	const {
		blocks,
		postTitle,
		postId,
		postType,
		postStatus,
		isReady,
	} = useSelect( ( select ) => {
		const blockEditorStore = select( 'core/block-editor' );
		const editorStore = select( 'core/editor' );

		return {
			blocks: blockEditorStore.getBlocks(),
			postTitle: editorStore.getEditedPostAttribute( 'title' ) || '',
			postId: editorStore.getCurrentPostId() || 0,
			postType: editorStore.getCurrentPostType() || 'post',
			postStatus: editorStore.getEditedPostAttribute( 'status' ) || 'draft',
			isReady: !! editorStore.getCurrentPostId(),
		};
	}, [] );

	const postInfo = useMemo( () => ( {
		postTitle,
		postId,
		postType,
		postStatus,
	} ), [ postTitle, postId, postType, postStatus ] );

	// Serialize on each render where blocks change.
	const editorContext = useMemo( () => {
		if ( ! isReady ) {
			return '';
		}
		return serializeEditorContext( blocks, postInfo );
	}, [ blocks, postInfo, isReady ] );

	return {
		editorContext,
		postInfo,
		blocks,
		isReady,
	};
}
