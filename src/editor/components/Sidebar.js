/**
 * Sidebar Component
 *
 * Main chat sidebar for the block editor, registered as a PluginSidebar.
 * Integrates with the ABW-AI backend and manages chat state, block context,
 * and block action execution.
 *
 * @package ABW_AI
 */

import { useState, useCallback, useEffect, useRef } from '@wordpress/element';
import { ChatMessages } from './ChatMessages';
import { ChatInput } from './ChatInput';
import { BlockActionsFeedback } from './BlockActionsFeedback';
import { useEditorContext } from '../hooks/useEditorContext';
import { useBlockActions } from '../hooks/useBlockActions';

const AGENT_POLL_INTERVAL_MS = 2000;
const AGENT_POLL_MAX_MS = 60000;

/**
 * ABW AI Sidebar content component.
 *
 * @return {import('@wordpress/element').WPElement} Sidebar content.
 */
export function Sidebar() {
	const [ messages, setMessages ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ isConfirming, setIsConfirming ] = useState( false );
	const [ showFeedback, setShowFeedback ] = useState( false );
	const [ agentSteps, setAgentSteps ] = useState( [] );
	const [ pendingConfirmation, setPendingConfirmation ] = useState( null );
	const [ agentMode, setAgentMode ] = useState( window.abwEditorChat?.defaultAgentMode || 'general' );

	const pollRef = useRef( { interval: null, timeout: null, inFlight: false, completed: false } );
	const { editorContext, isReady } = useEditorContext();
	const { executeActions, isExecuting, actionStatus, lastResults } = useBlockActions();

	// Load chat history on mount.
	useEffect( () => {
		loadHistory();
	}, [] );

	useEffect( () => {
		try {
			const savedMode = window.localStorage.getItem( 'abw_agent_mode' );
			if ( savedMode ) {
				setAgentMode( savedMode );
			}
		} catch {
			// localStorage not available
		}
	}, [] );

	// Stop polling on unmount.
	useEffect( () => () => stopAgentPolling(), [ stopAgentPolling ] );

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
				history_scope: getHistoryScope(),
			},
			success( response ) {
				if ( response.success && response.data.history && response.data.history.length > 0 ) {
					setMessages( response.data.history.map( ( msg ) => ( {
						role: msg.role,
						content: msg.content,
					} ) ) );
				}
				setPendingConfirmation( response.data?.confirmation || null );
			},
		} );
	}, [] );

	/**
	 * Stop agentic polling.
	 */
	const stopAgentPolling = useCallback( () => {
		if ( pollRef.current.interval ) {
			clearInterval( pollRef.current.interval );
			pollRef.current.interval = null;
		}
		if ( pollRef.current.timeout ) {
			clearTimeout( pollRef.current.timeout );
			pollRef.current.timeout = null;
		}
	}, [] );

	/**
	 * Start agentic polling for session updates.
	 *
	 * @param {string} sessionId Session ID from backend.
	 */
	const startAgentPolling = useCallback( ( sessionId ) => {
		stopAgentPolling();
		pollRef.current.completed = false;
		pollRef.current.timeout = setTimeout( () => {
			stopAgentPolling();
			setIsLoading( false );
			setAgentSteps( [] );
			setMessages( ( prev ) => [
				...prev,
				{ role: 'assistant', content: 'Request timed out. Please try again.' },
			] );
		}, AGENT_POLL_MAX_MS );

		const poll = async () => {
			if ( pollRef.current.inFlight ) return;
			pollRef.current.inFlight = true;

			try {
				const res = await sendAjax( {
					action: 'abw_agent_poll',
					nonce: window.abwEditorChat.nonce,
					session_id: sessionId,
				} );

				pollRef.current.inFlight = false;

				if ( ! res.success ) {
					stopAgentPolling();
					setIsLoading( false );
					setAgentSteps( [] );
					setPendingConfirmation( res.data?.confirmation || null );
					setMessages( ( prev ) => [
						...prev,
						{ role: 'assistant', content: res.data?.message || 'An error occurred.' },
					] );
					return;
				}

				setAgentSteps( res.data.steps || [] );

				if ( res.data.status === 'done' ) {
					if ( pollRef.current.completed ) return;
					pollRef.current.completed = true;
					stopAgentPolling();
					setIsLoading( false );
					setAgentSteps( [] );

					if ( res.data.block_actions && res.data.block_actions.length > 0 ) {
						await executeActions( res.data.block_actions );
					}

					let content = res.data.response || '';
					if ( ! content || ! String( content ).trim() ) {
						content = 'Task completed.';
					}
					setMessages( ( prev ) => [
						...prev,
						{ role: 'assistant', content },
					] );
					setPendingConfirmation( res.data.confirmation || null );
					return;
				}

				if ( res.data.status === 'error' ) {
					stopAgentPolling();
					setIsLoading( false );
					setAgentSteps( [] );
					setPendingConfirmation( res.data.confirmation || null );
					setMessages( ( prev ) => [
						...prev,
						{ role: 'assistant', content: res.data.response || 'An error occurred.' },
					] );
				}
			} catch ( error ) {
				pollRef.current.inFlight = false;
				stopAgentPolling();
				setIsLoading( false );
				setAgentSteps( [] );
				setPendingConfirmation( getAjaxErrorConfirmation( error ) );
				setMessages( ( prev ) => [
					...prev,
					{ role: 'assistant', content: getAjaxErrorMessage( error, 'An error occurred. Please try again.' ) },
				] );
			}
		};

		poll();
		pollRef.current.interval = setInterval( poll, AGENT_POLL_INTERVAL_MS );
	}, [ stopAgentPolling, executeActions ] );

	/**
	 * Send a message to the AI backend.
	 *
	 * @param {string} text User message text.
	 */
	const handleSend = useCallback( async ( text ) => {
		if ( ! text.trim() || isLoading || ! window.abwEditorChat ) {
			return;
		}

		const newMessages = [ ...messages, { role: 'user', content: text } ];
		setMessages( newMessages );
		setIsLoading( true );
		setAgentSteps( [] );

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
				agent_mode: agentMode,
				history_scope: getHistoryScope(),
			} );

			if ( response.success ) {
				const data = response.data;

				if ( data.status === 'thinking' && data.session_id ) {
					setAgentSteps( data.steps || [] );
					startAgentPolling( data.session_id );
					return;
				}

				setIsLoading( false );
				stopAgentPolling();

				if ( data.block_actions && data.block_actions.length > 0 ) {
					await executeActions( data.block_actions );
				}

				let content = data.response || '';
				if ( ! content || ! String( content ).trim() ) {
					content = 'Task completed.';
				}
				setPendingConfirmation( data.confirmation || null );
				setMessages( ( prev ) => [
					...prev,
					{ role: 'assistant', content },
				] );
			} else {
				setIsLoading( false );
				stopAgentPolling();
				setPendingConfirmation( response.data?.confirmation || null );
				setMessages( ( prev ) => [
					...prev,
					{ role: 'assistant', content: response.data?.message || 'An error occurred. Please try again.' },
				] );
			}
		} catch ( error ) {
			setIsLoading( false );
			stopAgentPolling();
			setAgentSteps( [] );
			setPendingConfirmation( getAjaxErrorConfirmation( error ) );
			setMessages( ( prev ) => [
				...prev,
				{ role: 'assistant', content: getAjaxErrorMessage( error, 'An error occurred. Please try again.' ) },
			] );
		}
	}, [ messages, isLoading, editorContext, executeActions, startAgentPolling, stopAgentPolling, agentMode ] );

	/**
	 * Confirm or cancel a pending sensitive action.
	 *
	 * @param {string} action Action type.
	 */
	const handleConfirmationAction = useCallback( async ( action ) => {
		if ( ! pendingConfirmation || isConfirming ) {
			return;
		}

		setIsConfirming( true );

		try {
			const response = await sendAjax( {
				action: 'abw_confirmation_action',
				nonce: window.abwEditorChat.nonce,
				confirmation_action: action,
				history_scope: getHistoryScope(),
			} );

			if ( ! response.success ) {
				setPendingConfirmation( response.data?.confirmation || pendingConfirmation );
				setMessages( ( prev ) => [
					...prev,
					{ role: 'assistant', content: response.data?.message || 'Unable to finish this action.' },
				] );
				return;
			}

			setPendingConfirmation( null );

			if ( response.data.block_actions && response.data.block_actions.length > 0 ) {
				await executeActions( response.data.block_actions );
			}

			setMessages( ( prev ) => [
				...prev,
				{ role: 'assistant', content: response.data.response || ( action === 'cancel' ? 'Action cancelled.' : 'Action completed.' ) },
			] );
		} catch ( error ) {
			setPendingConfirmation( getAjaxErrorConfirmation( error ) || pendingConfirmation );
			setMessages( ( prev ) => [
				...prev,
				{ role: 'assistant', content: getAjaxErrorMessage( error, 'Unable to finish this action.' ) },
			] );
		} finally {
			setIsConfirming( false );
		}
	}, [ pendingConfirmation, isConfirming, executeActions ] );

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
					history_scope: getHistoryScope(),
				},
			} );
		}

		setMessages( [] );
		setPendingConfirmation( null );
	}, [] );

	const userName = window.abwEditorChat?.userName || 'User';
	const agentPackages = window.abwEditorChat?.agentPackages || {};

	const handleAgentModeChange = useCallback( ( event ) => {
		const nextMode = event.target.value;
		setAgentMode( nextMode );
		try {
			window.localStorage.setItem( 'abw_agent_mode', nextMode );
		} catch {
			// localStorage not available
		}
	}, [] );

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
						agentSteps={ agentSteps }
						userName={ userName }
						onSuggestion={ handleSuggestion }
						actionStatus={ actionStatus }
						pendingConfirmation={ pendingConfirmation }
						onConfirmationAction={ handleConfirmationAction }
						isConfirming={ isConfirming }
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
						<select
							className="abw-editor-sidebar-mode"
							value={ agentMode }
							onChange={ handleAgentModeChange }
						>
							{ Object.entries( agentPackages ).map( ( [ key, value ] ) => (
								<option key={ key } value={ key }>
									{ value.label }
								</option>
							) ) }
						</select>
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

/**
 * Extract a readable error message from a jQuery AJAX failure.
 *
 * @param {Object} error    AJAX error object.
 * @param {string} fallback Fallback message.
 * @return {string} User-facing message.
 */
function getAjaxErrorMessage( error, fallback ) {
	const message = error?.responseJSON?.data?.message;
	return message && String( message ).trim() ? message : fallback;
}

/**
 * Extract a pending confirmation payload from a jQuery AJAX failure.
 *
 * @param {Object} error AJAX error object.
 * @return {Object|null} Confirmation payload.
 */
function getAjaxErrorConfirmation( error ) {
	return error?.responseJSON?.data?.confirmation || null;
}

/**
 * Build a scoped history key for the current editor surface.
 *
 * @return {string} History scope identifier.
 */
function getHistoryScope() {
	return `editor_${ window.abwEditorChat?.postId || 0 }`;
}
