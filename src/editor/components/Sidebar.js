/**
 * Sidebar Component
 *
 * Main chat sidebar for the block editor, registered as a PluginSidebar.
 * Integrates with the ABW-AI backend and manages chat state, block context,
 * and block action execution.
 *
 * @package ABW_AI
 */

import { useState, useCallback, useEffect } from '@wordpress/element';
import { ChatMessages } from './ChatMessages';
import { ChatInput } from './ChatInput';
import { BlockActionsFeedback } from './BlockActionsFeedback';
import { useEditorContext } from '../hooks/useEditorContext';
import { useBlockActions } from '../hooks/useBlockActions';

/**
 * ABW AI Sidebar content component.
 *
 * @return {import('@wordpress/element').WPElement} Sidebar content.
 */
export function Sidebar() {
	const [ messages, setMessages ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ showFeedback, setShowFeedback ] = useState( false );

	const { editorContext, isReady } = useEditorContext();
	const { executeActions, isExecuting, actionStatus, lastResults } = useBlockActions();

	// Load chat history on mount.
	useEffect( () => {
		loadHistory();
	}, [] );

	// Show feedback briefly after actions complete.
	useEffect( () => {
		if ( lastResults && lastResults.length > 0 ) {
			setShowFeedback( true );
			const timer = setTimeout( () => setShowFeedback( false ), 5000 );
			return () => clearTimeout( timer );
		}
	}, [ lastResults ] );

	/**
	 * Load existing chat history from the server.
	 */
	const loadHistory = useCallback( () => {
		if ( typeof window.jQuery === 'undefined' || ! window.abwEditorChat ) {
			return;
		}

		window.jQuery.ajax( {
			url: window.abwEditorChat.ajaxUrl,
			method: 'POST',
			data: {
				action: 'abw_chat_history',
				nonce: window.abwEditorChat.nonce,
			},
			success( response ) {
				if ( response.success && response.data.history && response.data.history.length > 0 ) {
					setMessages( response.data.history.map( ( msg ) => ( {
						role: msg.role,
						content: msg.content,
					} ) ) );
				}
			},
		} );
	}, [] );

	/**
	 * Send a message to the AI backend.
	 *
	 * @param {string} text User message text.
	 */
	const handleSend = useCallback( async ( text ) => {
		if ( ! text.trim() || isLoading || ! window.abwEditorChat ) {
			return;
		}

		// Add user message to UI.
		const newMessages = [ ...messages, { role: 'user', content: text } ];
		setMessages( newMessages );
		setIsLoading( true );

		try {
			const response = await sendAjax( {
				action: 'abw_chat_message',
				nonce: window.abwEditorChat.nonce,
				message: text,
				context: JSON.stringify( {
					screen: 'block-editor',
					post_id: window.abwEditorChat.postId || 0,
					post_type: window.abwEditorChat.postType || 'post',
				} ),
				editor_context: editorContext,
			} );

			setIsLoading( false );

			if ( response.success ) {
				const data = response.data;

				// Execute block actions if present.
				if ( data.block_actions && data.block_actions.length > 0 ) {
					await executeActions( data.block_actions );
				}

				// Add assistant response.
				if ( data.response ) {
					setMessages( ( prev ) => [
						...prev,
						{ role: 'assistant', content: data.response },
					] );
				}
			} else {
				setMessages( ( prev ) => [
					...prev,
					{ role: 'assistant', content: response.data?.message || 'An error occurred. Please try again.' },
				] );
			}
		} catch {
			setIsLoading( false );
			setMessages( ( prev ) => [
				...prev,
				{ role: 'assistant', content: 'An error occurred. Please try again.' },
			] );
		}
	}, [ messages, isLoading, editorContext, executeActions ] );

	/**
	 * Handle suggestion click.
	 *
	 * @param {string} prompt Suggestion prompt text.
	 */
	const handleSuggestion = useCallback( ( prompt ) => {
		handleSend( prompt );
	}, [ handleSend ] );

	/**
	 * Clear chat history.
	 */
	const handleClear = useCallback( () => {
		if ( ! window.confirm( 'Are you sure you want to clear the chat history?' ) ) {
			return;
		}

		if ( window.abwEditorChat ) {
			window.jQuery.ajax( {
				url: window.abwEditorChat.ajaxUrl,
				method: 'POST',
				data: {
					action: 'abw_clear_chat',
					nonce: window.abwEditorChat.nonce,
				},
			} );
		}

		setMessages( [] );
	}, [] );

	const userName = window.abwEditorChat?.userName || 'User';

	return (
		<div className="abw-editor-sidebar">
			<div className="abw-editor-sidebar-header">
				<span className="abw-editor-sidebar-title">ABW-AI Butler</span>
				<button
					className="abw-editor-sidebar-clear"
					onClick={ handleClear }
					title="Clear chat"
				>
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="16" height="16">
						<polyline points="3 6 5 6 21 6" />
						<path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
					</svg>
				</button>
			</div>

			{ ! isReady ? (
				<div className="abw-editor-sidebar-loading">
					<p>Loading editor context...</p>
				</div>
			) : (
				<>
					<ChatMessages
						messages={ messages }
						isLoading={ isLoading || isExecuting }
						userName={ userName }
						onSuggestion={ handleSuggestion }
						actionStatus={ actionStatus }
					/>

					<BlockActionsFeedback
						results={ lastResults }
						isVisible={ showFeedback }
					/>

					<ChatInput
						onSend={ handleSend }
						disabled={ isLoading || isExecuting }
					/>

					<div className="abw-editor-sidebar-footer">
						<span className="abw-editor-sidebar-provider">
							{ window.abwEditorChat?.provider || 'AI' }
						</span>
					</div>
				</>
			) }
		</div>
	);
}

/**
 * Send an AJAX request via jQuery and return a promise.
 *
 * @param {Object} data AJAX data object.
 * @return {Promise<Object>} Response data.
 */
function sendAjax( data ) {
	return new Promise( ( resolve, reject ) => {
		window.jQuery.ajax( {
			url: window.abwEditorChat.ajaxUrl,
			method: 'POST',
			data,
			success: resolve,
			error: reject,
		} );
	} );
}
