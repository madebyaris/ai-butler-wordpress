/**
 * ChatMessages Component
 *
 * Renders chat message history with markdown formatting, tool results,
 * and block action feedback.
 *
 * @package ABW_AI
 */

import { useRef, useEffect } from '@wordpress/element';
import { formatMessage } from '../utils/format-message';

const AssistantIcon = () => (
	<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
);

/**
 * Single message component.
 *
 * @param {Object} props           Component props.
 * @param {string} props.role      'user' or 'assistant'.
 * @param {string} props.content   Message content.
 * @param {string} props.userName  Current user's display name.
 * @return {import('@wordpress/element').WPElement} Message element.
 */
function Message( { role, content, userName } ) {
	const avatarContent = role === 'user'
		? ( userName || 'U' ).charAt( 0 ).toUpperCase()
		: <AssistantIcon />;

	const hasContent = content != null && String( content ).trim() !== '';
	const displayContent = hasContent ? content : ( role === 'assistant' ? 'Task completed.' : '' );
	const formattedContent = formatMessage( displayContent );

	return (
		<div className={ `abw-message abw-message-${ role }` }>
			<div className="abw-message-avatar">{ avatarContent }</div>
			<div
				className="abw-message-content"
				dangerouslySetInnerHTML={ { __html: formattedContent } }
			/>
		</div>
	);
}

/**
 * Typing indicator component.
 *
 * @return {import('@wordpress/element').WPElement} Typing indicator.
 */
function TypingIndicator() {
	return (
		<div className="abw-message abw-message-assistant abw-typing-message">
			<div className="abw-message-avatar"><AssistantIcon /></div>
			<div className="abw-message-content">
				<div className="abw-typing">
					<span className="abw-typing-dot" />
					<span className="abw-typing-dot" />
					<span className="abw-typing-dot" />
				</div>
			</div>
		</div>
	);
}

/**
 * Agentic steps indicator (thinking, tool calls, results).
 *
 * @param {Object}   props       Component props.
 * @param {Array}    props.steps Array of step objects.
 * @return {import('@wordpress/element').WPElement} Steps element.
 */
function AgentStepsIndicator( { steps } ) {
	if ( ! steps || steps.length === 0 ) {
		return (
			<div className="abw-message abw-message-assistant abw-typing-message">
				<div className="abw-message-avatar"><AssistantIcon /></div>
				<div className="abw-message-content">
					<div className="abw-agent-step abw-agent-step-progress">Thinking...</div>
				</div>
			</div>
		);
	}

	return (
		<div className="abw-message abw-message-assistant abw-typing-message">
			<div className="abw-message-avatar"><AssistantIcon /></div>
			<div className="abw-message-content">
				{ steps.map( ( step, i ) => {
					if ( step.type === 'thinking' && step.content ) {
						const text = step.content.length > 200 ? step.content.substring( 0, 200 ) + '...' : step.content;
						return (
							<div key={ i } className="abw-agent-step abw-agent-step-thinking">
								{ text }
							</div>
						);
					}
					if ( step.type === 'tool_call' ) {
						return (
							<div key={ i } className="abw-agent-step abw-agent-step-tool">
								{ step.name }…
							</div>
						);
					}
					if ( step.type === 'tool_result' ) {
						return (
							<div key={ i } className="abw-agent-step abw-agent-step-result">
								{ step.name } done
							</div>
						);
					}
					return null;
				} ) }
			</div>
		</div>
	);
}

/**
 * Welcome message component.
 *
 * @param {Object}   props               Component props.
 * @param {Function} props.onSuggestion  Callback when a suggestion is clicked.
 * @return {import('@wordpress/element').WPElement} Welcome element.
 */
function WelcomeMessage( { onSuggestion } ) {
	const suggestions = [
		{ label: 'Add a heading & paragraph', prompt: 'Add a heading and introductory paragraph to this post' },
		{ label: 'Generate full article', prompt: 'Generate a full article for this post based on the title' },
		{ label: 'Improve content', prompt: 'Improve the existing content in this post' },
		{ label: 'Add a CTA section', prompt: 'Add a call-to-action section at the end of this post' },
	];

	return (
		<div className="abw-chat-welcome">
			<p>Hi! I can help you edit this post. I can see all the blocks in your editor and can insert, replace, or remove content.</p>
			<div className="abw-chat-suggestions">
				{ suggestions.map( ( suggestion ) => (
					<button
						key={ suggestion.prompt }
						className="abw-suggestion"
						onClick={ () => onSuggestion( suggestion.prompt ) }
					>
						{ suggestion.label }
					</button>
				) ) }
			</div>
		</div>
	);
}

/**
 * ChatMessages component.
 *
 * @param {Object}   props               Component props.
 * @param {Array}    props.messages       Array of { role, content } objects.
 * @param {boolean}  props.isLoading      Whether the AI is thinking.
 * @param {Array}    props.agentSteps     Agentic steps for step-by-step display.
 * @param {string}   props.userName       Current user name.
 * @param {Function} props.onSuggestion   Callback when a suggestion is clicked.
 * @param {Object}   props.actionStatus   Current block action status.
 * @return {import('@wordpress/element').WPElement} Messages container.
 */
export function ChatMessages( { messages, isLoading, agentSteps = [], userName, onSuggestion, actionStatus } ) {
	const containerRef = useRef( null );

	// Auto-scroll to bottom on new messages.
	useEffect( () => {
		if ( containerRef.current ) {
			containerRef.current.scrollTop = containerRef.current.scrollHeight;
		}
	}, [ messages, isLoading, actionStatus ] );

	return (
		<div className="abw-chat-messages" ref={ containerRef }>
			{ messages.length === 0 && ! isLoading && (
				<WelcomeMessage onSuggestion={ onSuggestion } />
			) }

			{ messages.map( ( msg, index ) => (
				<Message
					key={ index }
					role={ msg.role }
					content={ msg.content }
					userName={ userName }
				/>
			) ) }

			{ actionStatus && (
				<div className="abw-block-action-status">
					<div className={ `abw-action-indicator abw-action-${ actionStatus.status }` }>
						{ actionStatus.status === 'executing' && (
							<span className="abw-action-spinner" />
						) }
						<span className="abw-action-text">{ actionStatus.message }</span>
					</div>
				</div>
			) }

			{ isLoading && <AgentStepsIndicator steps={ agentSteps } /> }
		</div>
	);
}
