/**
 * ChatInput Component
 *
 * Text input area for the block editor sidebar chat.
 *
 * @package ABW_AI
 */

import { useState, useRef, useCallback } from '@wordpress/element';

/**
 * ChatInput component.
 *
 * @param {Object}   props            Component props.
 * @param {Function} props.onSend     Callback when message is sent.
 * @param {boolean}  props.disabled   Whether input is disabled.
 * @param {string}   props.value      Controlled value (optional, for suggestion fill).
 * @param {Function} props.onChange    Controlled onChange (optional).
 * @return {import('@wordpress/element').WPElement} Input area.
 */
export function ChatInput( { onSend, disabled } ) {
	const [ message, setMessage ] = useState( '' );
	const textareaRef = useRef( null );

	const handleSend = useCallback( () => {
		const trimmed = message.trim();
		if ( ! trimmed || disabled ) {
			return;
		}
		onSend( trimmed );
		setMessage( '' );
		// Reset height.
		if ( textareaRef.current ) {
			textareaRef.current.style.height = 'auto';
		}
	}, [ message, disabled, onSend ] );

	const handleKeyDown = useCallback( ( e ) => {
		if ( e.key === 'Enter' && ! e.shiftKey ) {
			e.preventDefault();
			handleSend();
		}
	}, [ handleSend ] );

	const handleInput = useCallback( () => {
		const textarea = textareaRef.current;
		if ( textarea ) {
			textarea.style.height = 'auto';
			textarea.style.height = Math.min( textarea.scrollHeight, 120 ) + 'px';
		}
	}, [] );

	/**
	 * Fill the input with a suggestion.
	 *
	 * @param {string} text Suggestion text.
	 */
	const fillSuggestion = useCallback( ( text ) => {
		setMessage( text );
		if ( textareaRef.current ) {
			textareaRef.current.focus();
		}
	}, [] );

	// Expose fillSuggestion via a ref callback pattern.
	// Parent can call chatInputRef.current.fillSuggestion(text).
	ChatInput.fillRef = fillSuggestion;

	return (
		<div className="abw-chat-input-area">
			<textarea
				ref={ textareaRef }
				className="abw-chat-input"
				placeholder="Ask me to edit this post..."
				rows={ 1 }
				value={ message }
				onChange={ ( e ) => setMessage( e.target.value ) }
				onKeyDown={ handleKeyDown }
				onInput={ handleInput }
				disabled={ disabled }
			/>
			<button
				className="abw-chat-send"
				onClick={ handleSend }
				disabled={ disabled || ! message.trim() }
			>
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
					<line x1="22" y1="2" x2="11" y2="13" />
					<polygon points="22 2 15 22 11 13 2 9 22 2" />
				</svg>
			</button>
		</div>
	);
}
