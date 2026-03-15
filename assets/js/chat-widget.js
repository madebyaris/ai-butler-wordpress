/**
 * ABW-AI Chat Widget JavaScript
 *
 * Handles the sidebar toggle, chat interface interactions and API communication.
 */

(function($) {
	'use strict';

	// State
	const state = {
		isOpen: false,
		isLoading: false,
		isConfirming: false,
		history: [],
		pendingConfirmation: null,
		activeJobs: {},     // job_token => { interval, $element }
		agentPollInterval: null,
		agentPollTimeout: null,
		agentPollInFlight: false,
		agentCompleted: false,
		AGENT_POLL_INTERVAL_MS: 2000,
		AGENT_POLL_MAX_MS: 60000
	};

	const ABW_ICON = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';

	// DOM Elements
	let $sidebar, $messages, $input, $sendBtn;

	/**
	 * Initialize the chat widget
	 */
	function init() {
		$sidebar = $('#abw-sidebar-container');
		$messages = $('#abw-chat-messages');
		$input = $('#abw-chat-input');
		$sendBtn = $('#abw-chat-send');

		if (!$sidebar.length) return;

		// Check initial state from localStorage
		const savedState = localStorage.getItem('abw_sidebar_state');
		const defaultState = $sidebar.attr('aria-hidden') === 'false' ? 'open' : 'closed';
		const shouldBeOpen = (savedState || defaultState) === 'open';

		if (shouldBeOpen) {
			toggleSidebar(true, false); // Open without animation on load
		}

		restoreAgentMode();

		bindEvents();
		loadHistory();
	}

	/**
	 * Bind event handlers
	 */
	function bindEvents() {
		// Admin bar toggle (works for both standard admin bar and Elementor fallback)
		$(document).on('click', '#wp-admin-bar-abw-sidebar-toggle a, .abw-sidebar-toggle-item a', function(e) {
			e.preventDefault();
			toggleSidebar();
		});

		// Send message
		$sendBtn.on('click', sendMessage);
		$input.on('keydown', function(e) {
			if (e.key === 'Enter' && !e.shiftKey) {
				e.preventDefault();
				sendMessage();
			}
		});

		// Auto-resize textarea
		$input.on('input', autoResize);

		// Clear history
		$('#abw-clear-chat').on('click', clearHistory);

		// Suggestions
		$(document).on('click', '.abw-suggestion', function() {
			const prompt = $(this).data('prompt');
			$input.val(prompt).focus();
			autoResize.call($input[0]);
		});

		// Follow-up suggestion buttons
		$(document).on('click', '.abw-follow-up-btn', function() {
			var prompt = $(this).data('prompt');
			$('.abw-follow-ups').remove();
			$input.val(prompt);
			sendMessage();
		});

		$('#abw-agent-mode').on('change', function() {
			try {
				localStorage.setItem('abw_agent_mode', $(this).val());
			} catch (e) {
				// localStorage not available
			}
		});

		// Sensitive action confirmation buttons
		$(document).on('click', '.abw-confirmation-btn', function() {
			handleConfirmationAction($(this).data('action'));
		});


		// Export chat
		$('#abw-export-chat').on('click', exportChat);

		// Close sidebar on escape key
		$(document).on('keydown', function(e) {
			if (e.key === 'Escape' && state.isOpen) {
				toggleSidebar(false);
			}
		});

		// Reasoning toggle
		$(document).on('click', '.abw-reasoning-toggle', function() {
			const $toggle = $(this);
			const $content = $toggle.siblings('.abw-reasoning-content');
			const $icon = $toggle.find('.abw-reasoning-icon');
			const isExpanded = $toggle.attr('aria-expanded') === 'true';
			
			$content.slideToggle(200);
			$toggle.attr('aria-expanded', !isExpanded);
			$icon.text(isExpanded ? '▼' : '▲');
		});
	}

	/**
	 * Initialize reasoning toggles
	 */
	function initReasoningToggles() {
		$('.abw-reasoning-toggle').each(function() {
			const $toggle = $(this);
			if (!$toggle.data('initialized')) {
				$toggle.data('initialized', true);
				// Toggle is already bound via document.on('click')
			}
		});
	}

	/**
	 * Toggle sidebar visibility (Angie-style push layout)
	 */
	function toggleSidebar(forceOpen, animate = true) {
		const html = document.documentElement;
		const body = document.body;

		if (forceOpen !== undefined) {
			state.isOpen = forceOpen;
		} else {
			state.isOpen = !state.isOpen;
		}

		// Add transition class for smooth animation
		if (animate) {
			body.classList.add('abw-sidebar-transitioning');
			setTimeout(function() {
				body.classList.remove('abw-sidebar-transitioning');
			}, 300);
		}

		// Toggle classes
		if (state.isOpen) {
			html.classList.add('abw-sidebar-active');
			body.classList.add('abw-sidebar-active');
			$sidebar.attr('aria-hidden', 'false');
			
			// Focus input when opened
			setTimeout(function() {
				$input.focus();
				scrollToBottom();
			}, 100);
		} else {
			html.classList.remove('abw-sidebar-active');
			body.classList.remove('abw-sidebar-active');
			$sidebar.attr('aria-hidden', 'true');
		}

		// Save state to localStorage
		try {
			localStorage.setItem('abw_sidebar_state', state.isOpen ? 'open' : 'closed');
		} catch (e) {
			// localStorage not available
		}
	}

	/**
	 * Send a message
	 */
	function sendMessage() {
		$('.abw-follow-ups').remove();

		const message = $input.val().trim();
		
		if (!message || state.isLoading) return;

		// Add user message to UI
		addMessage('user', message);
		$input.val('').css('height', 'auto');

		// Show typing indicator
		showTypingIndicator();
		state.isLoading = true;

		// Send to server
		$.ajax({
			url: abwChat.ajaxUrl,
			method: 'POST',
			data: {
				action: 'abw_chat_message',
				nonce: abwChat.nonce,
				message: message,
				context: JSON.stringify(abwChat.currentPage),
				agent_mode: getSelectedAgentMode(),
				history_scope: getHistoryScope()
			},
			success: function(response) {
				if (response.success) {
					if (response.data.status === 'thinking' && response.data.session_id) {
						updateAgentSteps(response.data.steps || []);
						startAgentPolling(response.data.session_id);
						return;
					}

					hideTypingIndicator();
					state.isLoading = false;
					stopAgentPolling();

					if (response.data.background_job) {
						addMessage('assistant', response.data.response);
						startJobPolling(response.data.background_job);
						return;
					}

					if (
						abwChat &&
						abwChat.debugToolResults &&
						response.data.tool_results &&
						response.data.tool_results.length > 0
					) {
						response.data.tool_results.forEach(function(result) {
							addToolResult(result.tool, result.result);
						});
					}

					var content = response.data.response || '';
					if (!content || !String(content).trim()) {
						content = 'Task completed.';
					}
					addMessage('assistant', content);
					renderPendingConfirmation(response.data.confirmation || null);
				} else {
					hideTypingIndicator();
					state.isLoading = false;
					stopAgentPolling();
					renderPendingConfirmation(response.data && response.data.confirmation ? response.data.confirmation : null);
					addErrorMessage(response.data && response.data.message ? response.data.message : abwChat.i18n.error);
				}
			},
			error: function(xhr) {
				hideTypingIndicator();
				state.isLoading = false;
				stopAgentPolling();
				renderPendingConfirmation(getAjaxErrorConfirmation(xhr));
				addErrorMessage(getAjaxErrorMessage(xhr, abwChat.i18n.error));
			}
		});
	}

	/**
	 * Add a message to the chat
	 */
	function addMessage(role, content) {
		// Remove welcome message if exists
		$('.abw-chat-welcome').remove();

		var displayContent = content;
		if (role === 'assistant' && (!displayContent || !String(displayContent).trim())) {
			displayContent = 'Task completed.';
		}

		const avatarContent = role === 'user' 
			? abwChat.userName.charAt(0).toUpperCase() 
			: ABW_ICON;

		const $message = $(`
			<div class="abw-message abw-message-${role}">
				<div class="abw-message-avatar">${avatarContent}</div>
				<div class="abw-message-content">${formatMessage(displayContent)}</div>
			</div>
		`);

		$messages.append($message);
		scrollToBottom();

		if (role === 'assistant' && !state.isLoading) {
			addFollowUpSuggestions(content);
		}
	}

	/**
	 * Add tool result to chat
	 */
	function addToolResult(tool, result) {
		const formattedResult = typeof result === 'object' 
			? JSON.stringify(result, null, 2) 
			: result;

		const $result = $(`
			<div class="abw-tool-result">
				<div class="abw-tool-result-label">Tool: ${escapeHtml(tool)}</div>
				<pre>${escapeHtml(formattedResult)}</pre>
			</div>
		`);

		$messages.append($result);
	}

	/**
	 * Add error message
	 */
	function addErrorMessage(message) {
		const $error = $(`
			<div class="abw-error-message">${escapeHtml(message)}</div>
		`);

		$messages.append($error);
		scrollToBottom();
	}

	/**
	 * Render or clear the pending confirmation card.
	 */
	function renderPendingConfirmation(confirmation) {
		state.pendingConfirmation = confirmation || null;
		$('.abw-confirmation-message').remove();

		if (!state.pendingConfirmation) {
			return;
		}

		const details = Array.isArray(state.pendingConfirmation.details)
			? state.pendingConfirmation.details.map(function(detail) {
				return `<li>${escapeHtml(detail)}</li>`;
			}).join('')
			: '';

		const $message = $(`
			<div class="abw-message abw-message-assistant abw-confirmation-message">
				<div class="abw-message-avatar">${ABW_ICON}</div>
				<div class="abw-message-content">
					<div class="abw-confirmation-card">
						<strong>${escapeHtml(state.pendingConfirmation.title || 'Confirmation required')}</strong>
						<p>${escapeHtml(state.pendingConfirmation.message || 'Please confirm this action.')}</p>
						${details ? `<ul class="abw-confirmation-details">${details}</ul>` : ''}
						<div class="abw-confirmation-actions">
							<button class="abw-confirmation-btn button button-primary" data-action="confirm">${escapeHtml(state.pendingConfirmation.confirm_label || 'Confirm')}</button>
							<button class="abw-confirmation-btn button" data-action="cancel">${escapeHtml(state.pendingConfirmation.cancel_label || 'Cancel')}</button>
						</div>
					</div>
				</div>
			</div>
		`);

		$messages.append($message);
		scrollToBottom();
	}

	/**
	 * Confirm or cancel a pending sensitive action.
	 */
	function handleConfirmationAction(action) {
		if (!state.pendingConfirmation || state.isConfirming) {
			return;
		}

		state.isConfirming = true;
		$('.abw-confirmation-btn').prop('disabled', true);

		$.ajax({
			url: abwChat.ajaxUrl,
			method: 'POST',
			data: {
				action: 'abw_confirmation_action',
				nonce: abwChat.nonce,
				confirmation_action: action,
				history_scope: getHistoryScope()
			},
			success: function(response) {
				state.isConfirming = false;
				if (!response.success) {
					$('.abw-confirmation-btn').prop('disabled', false);
					renderPendingConfirmation(response.data && response.data.confirmation ? response.data.confirmation : state.pendingConfirmation);
					addErrorMessage(response.data && response.data.message ? response.data.message : abwChat.i18n.error);
					return;
				}

				renderPendingConfirmation(null);

				if (
					abwChat &&
					abwChat.debugToolResults &&
					response.data.tool_results &&
					response.data.tool_results.length > 0
				) {
					response.data.tool_results.forEach(function(result) {
						addToolResult(result.tool, result.result);
					});
				}

				addMessage('assistant', response.data.response || (action === 'cancel' ? 'Action cancelled.' : 'Action completed.'));
			},
			error: function(xhr) {
				state.isConfirming = false;
				$('.abw-confirmation-btn').prop('disabled', false);
				renderPendingConfirmation(getAjaxErrorConfirmation(xhr) || state.pendingConfirmation);
				addErrorMessage(getAjaxErrorMessage(xhr, abwChat.i18n.error));
			}
		});
	}

	/**
	 * Extract the best available AJAX error message.
	 */
	function getAjaxErrorMessage(xhr, fallback) {
		const message = xhr && xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data.message : '';
		return message && String(message).trim() ? message : fallback;
	}

	/**
	 * Extract a pending confirmation payload from a failed AJAX response.
	 */
	function getAjaxErrorConfirmation(xhr) {
		return xhr && xhr.responseJSON && xhr.responseJSON.data
			? xhr.responseJSON.data.confirmation || null
			: null;
	}

	/**
	 * Restore the selected agent mode from local storage.
	 */
	function restoreAgentMode() {
		const $select = $('#abw-agent-mode');
		if (!$select.length) {
			return;
		}

		let savedMode = abwChat.defaultAgentMode || 'general';
		try {
			savedMode = localStorage.getItem('abw_agent_mode') || savedMode;
		} catch (e) {
			// localStorage not available
		}

		$select.val(savedMode);
	}

	/**
	 * Get the currently selected agent mode.
	 */
	function getSelectedAgentMode() {
		const $select = $('#abw-agent-mode');
		return $select.length ? $select.val() : (abwChat.defaultAgentMode || 'general');
	}

	/**
	 * Build a scoped history key for the current surface.
	 */
	function getHistoryScope() {
		const page = abwChat.currentPage || {};
		if (page.post_id) {
			return `admin_${page.screen || page.page || 'page'}_${page.post_id}`;
		}
		return `admin_${page.screen || page.page || 'global'}`;
	}

	/**
	 * Show typing indicator
	 */
	function showTypingIndicator() {
		const $typing = $(`
			<div class="abw-message abw-message-assistant abw-typing-message">
				<div class="abw-message-avatar">${ABW_ICON}</div>
				<div class="abw-message-content">
					<div class="abw-typing">
						<span class="abw-typing-dot"></span>
						<span class="abw-typing-dot"></span>
						<span class="abw-typing-dot"></span>
					</div>
				</div>
			</div>
		`);

		$messages.append($typing);
		scrollToBottom();
	}

	/**
	 * Hide typing indicator
	 */
	function hideTypingIndicator() {
		$('.abw-typing-message').remove();
	}

	/**
	 * Update agentic step display in the typing area
	 */
	function updateAgentSteps(steps) {
		let $typing = $('.abw-typing-message');
		if (!$typing.length) {
			$typing = $(`
				<div class="abw-message abw-message-assistant abw-typing-message">
				<div class="abw-message-avatar">${ABW_ICON}</div>
				<div class="abw-message-content"></div>
				</div>
			`);
			$messages.append($typing);
		}
		const $container = $typing.find('.abw-message-content');
		if (!steps || steps.length === 0) {
			$container.html('<div class="abw-agent-step abw-agent-step-progress">Thinking...</div>');
		} else {
			let html = '';
			steps.forEach(function(step) {
				if (step.type === 'thinking' && step.content) {
					html += '<div class="abw-agent-step abw-agent-step-thinking">' + escapeHtml(step.content.substring(0, 200)) + (step.content.length > 200 ? '...' : '') + '</div>';
				} else if (step.type === 'tool_call') {
					html += '<div class="abw-agent-step abw-agent-step-tool">' + escapeHtml(step.name) + '…</div>';
				} else if (step.type === 'tool_result') {
					html += '<div class="abw-agent-step abw-agent-step-result">' + escapeHtml(step.name) + ' done</div>';
				}
			});
			$container.html(html || '<div class="abw-agent-step abw-agent-step-progress">Thinking...</div>');
		}
		scrollToBottom();
	}

	/**
	 * Start polling for agentic session updates
	 */
	function startAgentPolling(sessionId) {
		stopAgentPolling();
		state.agentPollTimeout = setTimeout(function() {
			stopAgentPolling();
			hideTypingIndicator();
			state.isLoading = false;
			addErrorMessage('Request timed out. Please try again.');
		}, state.AGENT_POLL_MAX_MS);

		state.agentCompleted = false;

		function poll() {
			if (state.agentPollInFlight) return;
			state.agentPollInFlight = true;

			$.ajax({
				url: abwChat.ajaxUrl,
				method: 'POST',
				data: {
					action: 'abw_agent_poll',
					nonce: abwChat.nonce,
					session_id: sessionId
				},
				success: function(response) {
					state.agentPollInFlight = false;
					if (!response.success) {
						stopAgentPolling();
						hideTypingIndicator();
						state.isLoading = false;
						addErrorMessage(response.data.message || abwChat.i18n.error);
						return;
					}
					updateAgentSteps(response.data.steps || []);

					if (response.data.status === 'done') {
						if (state.agentCompleted) return;
						state.agentCompleted = true;
						stopAgentPolling();
						hideTypingIndicator();
						state.isLoading = false;
						var content = response.data.response || '';
						if (!content || !String(content).trim()) {
							content = 'Task completed.';
						}
						if (response.data.background_job) {
							addMessage('assistant', content);
							startJobPolling(response.data.background_job);
						} else {
							addMessage('assistant', content);
						}
						renderPendingConfirmation(response.data.confirmation || null);
						return;
					}
					if (response.data.status === 'error') {
						stopAgentPolling();
						hideTypingIndicator();
						state.isLoading = false;
						renderPendingConfirmation(response.data.confirmation || null);
						addErrorMessage(response.data.response || abwChat.i18n.error);
						return;
					}
				},
				error: function(xhr) {
					state.agentPollInFlight = false;
					stopAgentPolling();
					hideTypingIndicator();
					state.isLoading = false;
					renderPendingConfirmation(getAjaxErrorConfirmation(xhr));
					addErrorMessage(getAjaxErrorMessage(xhr, abwChat.i18n.error));
				}
			});
		}

		poll();
		state.agentPollInterval = setInterval(poll, state.AGENT_POLL_INTERVAL_MS);
	}

	/**
	 * Stop agentic polling
	 */
	function stopAgentPolling() {
		if (state.agentPollTimeout) {
			clearTimeout(state.agentPollTimeout);
			state.agentPollTimeout = null;
		}
		if (state.agentPollInterval) {
			clearInterval(state.agentPollInterval);
			state.agentPollInterval = null;
		}
	}

	/**
	 * Format message content (basic markdown support)
	 */
	function formatMessage(content) {
		if (!content) return '';

		// Normalize newlines
		content = String(content).replace(/\r\n?/g, '\n');

		// Remove reasoning tags completely (defense in depth, server should also strip)
		content = content.replace(/<think>([\s\S]*?)<\/think>/gi, '');
		content = content.replace(/<think>([\s\S]*?)<\/redacted_reasoning>/gi, '');

		// Extract fenced code blocks first, then escape everything else (prevents HTML leakage)
		const codeBlocks = [];
		content = content.replace(/```(\w+)?\n([\s\S]*?)```/g, function(_match, lang, code) {
			const id = codeBlocks.length;
			codeBlocks.push({
				lang: (lang || 'text').trim() || 'text',
				code: (code || '').replace(/\n$/, '')
			});
			return `%%ABW_CODEBLOCK_${id}%%`;
		});

		// Escape all remaining content up front
		const escaped = escapeHtml(content);

		// Inline markdown (runs on escaped text, so inserted HTML is safe)
		function safeHref(rawHref) {
			const href = String(rawHref || '').trim();
			if (!href) return '#';
			// allow http(s), mailto, tel, and simple relative links
			if (/^(https?:\/\/|mailto:|tel:)/i.test(href)) return href;
			if (/^(\/|#)/.test(href)) return href;
			return '#';
		}

		function inlineFormat(text) {
			let out = text;

			// Links: [text](url)
			out = out.replace(/\[([^\]\n]+)\]\(([^)\s]+)\)/g, function(_m, label, href) {
				const safe = safeHref(href);
				return `<a href="${safe}" target="_blank" rel="noopener noreferrer">${label}</a>`;
			});

			// Inline code: `code`
			out = out.replace(/`([^`\n]+)`/g, '<code>$1</code>');

			// Bold: **text**
			out = out.replace(/\*\*([^*\n]+?)\*\*/g, '<strong>$1</strong>');

			// Italic: *text* (avoid matching **bold** by requiring non-* prefix)
			out = out.replace(/(^|[^*])\*([^*\n]+?)\*(?!\*)/g, '$1<em>$2</em>');

			return out;
		}

		// Block-level markdown: headers, lists, paragraphs
		const lines = escaped.split('\n');
		let html = '';
		let listType = null; // 'ul' | 'ol' | null
		let paragraph = [];

		function splitTableRow(rowLine) {
			// Strip leading/trailing pipes to avoid empty first/last cells
			const row = rowLine.trim().replace(/^\|/, '').replace(/\|$/, '');
			return row.split('|').map((c) => c.trim());
		}

		function isTableSeparatorRow(rowLine) {
			if (!rowLine) return false;
			const trimmed = rowLine.trim();
			if (!trimmed.includes('|')) return false;
			const cells = splitTableRow(trimmed);
			if (cells.length < 2) return false;
			return cells.every((c) => /^:?-{3,}:?$/.test(c));
		}

		function flushParagraph() {
			if (paragraph.length === 0) return;
			const text = inlineFormat(paragraph.join('<br>'));
			html += `<p>${text}</p>`;
			paragraph = [];
		}

		function closeList() {
			if (!listType) return;
			html += `</${listType}>`;
			listType = null;
		}

		function openList(nextType) {
			if (listType === nextType) return;
			closeList();
			listType = nextType;
			html += `<${listType}>`;
		}

		for (let i = 0; i < lines.length; i++) {
			const line = lines[i];
			const trimmed = line.trim();

			// Blank line: end paragraph / lists
			if (trimmed === '') {
				flushParagraph();
				closeList();
				continue;
			}

			// Markdown table (pipe table): header row + separator row, followed by body rows
			if (
				trimmed.includes('|') &&
				i + 1 < lines.length &&
				isTableSeparatorRow(lines[i + 1].trim())
			) {
				flushParagraph();
				closeList();

				const headerCells = splitTableRow(trimmed);
				// Consume separator row (i + 1)
				i += 1;

				let tableHtml = '<table class="abw-md-table"><thead><tr>';
				headerCells.forEach((cell) => {
					tableHtml += `<th>${inlineFormat(cell)}</th>`;
				});
				tableHtml += '</tr></thead><tbody>';

				// Consume body rows until blank line or non-table-ish line
				while (i + 1 < lines.length) {
					const nextLine = lines[i + 1];
					const nextTrimmed = nextLine.trim();
					if (nextTrimmed === '') break;
					// Stop if we hit a codeblock placeholder or a header/list start; keeps parsing predictable
					if (/^%%ABW_CODEBLOCK_\d+%%$/.test(nextTrimmed)) break;
					if (/^#{1,3}\s+/.test(nextTrimmed)) break;
					if (/^\s*[-*]\s+/.test(nextTrimmed)) break;
					if (/^\s*\d+\.\s+/.test(nextTrimmed)) break;
					if (!nextTrimmed.includes('|')) break;

					i += 1;
					const rowCells = splitTableRow(nextTrimmed);
					tableHtml += '<tr>';
					headerCells.forEach((_, idx) => {
						tableHtml += `<td>${inlineFormat(rowCells[idx] || '')}</td>`;
					});
					tableHtml += '</tr>';
				}

				tableHtml += '</tbody></table>';
				html += tableHtml;
				continue;
			}

			// Codeblock placeholder (block element)
			const cbMatch = trimmed.match(/^%%ABW_CODEBLOCK_(\d+)%%$/);
			if (cbMatch) {
				flushParagraph();
				closeList();
				html += trimmed; // replace later
				continue;
			}

			// Headers
			const h3 = line.match(/^###\s+(.+)$/);
			const h2 = line.match(/^##\s+(.+)$/);
			const h1 = line.match(/^#\s+(.+)$/);
			if (h3 || h2 || h1) {
				flushParagraph();
				closeList();
				if (h3) html += `<h3>${inlineFormat(h3[1])}</h3>`;
				else if (h2) html += `<h2>${inlineFormat(h2[1])}</h2>`;
				else html += `<h1>${inlineFormat(h1[1])}</h1>`;
				continue;
			}

			// Unordered list item
			const ulItem = line.match(/^\s*[-*]\s+(.+)$/);
			if (ulItem) {
				flushParagraph();
				openList('ul');
				html += `<li>${inlineFormat(ulItem[1])}</li>`;
				continue;
			}

			// Ordered list item
			const olItem = line.match(/^\s*\d+\.\s+(.+)$/);
			if (olItem) {
				flushParagraph();
				openList('ol');
				html += `<li>${inlineFormat(olItem[1])}</li>`;
				continue;
			}

			// Default: accumulate paragraph lines
			closeList();
			paragraph.push(line);
		}

		flushParagraph();
		closeList();

		// Restore code blocks (safe: code is escaped when injected)
		html = html.replace(/%%ABW_CODEBLOCK_(\d+)%%/g, function(_m, idxStr) {
			const idx = Number(idxStr);
			const block = codeBlocks[idx];
			if (!block) return '';
			const lang = escapeHtml(block.lang);
			const code = escapeHtml(block.code);
			return `<pre><code class="language-${lang}">${code}</code></pre>`;
		});

		return html;
	}

	/**
	 * Escape HTML entities
	 */
	function escapeHtml(text) {
		const div = document.createElement('div');
		div.textContent = text;
		return div.innerHTML;
	}

	/**
	 * Scroll messages to bottom
	 */
	function scrollToBottom() {
		if ($messages.length && $messages[0]) {
			$messages.scrollTop($messages[0].scrollHeight);
		}
	}

	/**
	 * Auto-resize textarea
	 */
	function autoResize() {
		this.style.height = 'auto';
		this.style.height = Math.min(this.scrollHeight, 120) + 'px';
	}

	/**
	 * Load chat history
	 */
	function loadHistory() {
		$.ajax({
			url: abwChat.ajaxUrl,
			method: 'POST',
			data: {
				action: 'abw_chat_history',
				nonce: abwChat.nonce,
				history_scope: getHistoryScope()
			},
			success: function(response) {
				if (response.success && response.data.history && response.data.history.length > 0) {
					// Remove welcome message
					$('.abw-chat-welcome').remove();

					// Add history messages
					response.data.history.forEach(function(msg) {
						addMessage(msg.role, msg.content);
					});
				}
				renderPendingConfirmation(response.data && response.data.confirmation ? response.data.confirmation : null);
			}
		});
	}

	/**
	 * Clear chat history
	 */
	function clearHistory() {
		if (!confirm('Are you sure you want to clear the chat history?')) {
			return;
		}

		$.ajax({
			url: abwChat.ajaxUrl,
			method: 'POST',
			data: {
				action: 'abw_clear_chat',
				nonce: abwChat.nonce,
				history_scope: getHistoryScope()
			},
			success: function(response) {
				if (response.success) {
					renderPendingConfirmation(null);
					// Clear messages and show welcome
				$messages.html(`
					<div class="abw-chat-welcome">
						<p>Hi! I'm your Advanced Butler for WordPress. How can I help you today?</p>
						<div class="abw-chat-suggestions">
							<button class="abw-suggestion" data-prompt="List all my posts"><span class="dashicons dashicons-admin-post"></span> Posts</button>
							<button class="abw-suggestion" data-prompt="List installed plugins"><span class="dashicons dashicons-admin-plugins"></span> Plugins</button>
							<button class="abw-suggestion" data-prompt="Show me site health info"><span class="dashicons dashicons-heart"></span> Site Health</button>
							<button class="abw-suggestion" data-prompt="Get database stats"><span class="dashicons dashicons-database"></span> Database</button>
							<button class="abw-suggestion" data-prompt="Check for plugin updates"><span class="dashicons dashicons-update"></span> Updates</button>
							<button class="abw-suggestion" data-prompt="Create a new blog post about"><span class="dashicons dashicons-edit"></span> Create Post</button>
						</div>
					</div>
				`);
				}
			}
		});
	}

	// -------------------------------------------------------------------------
	// Background Job Polling
	// -------------------------------------------------------------------------

	/**
	 * Start polling for a background job status
	 */
	function startJobPolling(jobInfo) {
		const token = jobInfo.job_token;
		const jobType = jobInfo.job_type || 'background_task';

		// Create a progress indicator in the chat.
		const $progress = $(`
			<div class="abw-message abw-message-assistant abw-job-progress" data-job-token="${escapeHtml(token)}">
				<div class="abw-message-avatar">${ABW_ICON}</div>
				<div class="abw-message-content">
					<div class="abw-job-status-indicator">
						<div class="abw-job-spinner"></div>
						<span class="abw-job-status-text">Processing <strong>${escapeHtml(jobType.replace(/_/g, ' '))}</strong>...</span>
					</div>
					<div class="abw-job-meta">
						<a href="${escapeHtml(abwChat.adminJobsUrl || '#')}" target="_blank" class="abw-job-link">View all jobs</a>
					</div>
				</div>
			</div>
		`);

		$messages.append($progress);
		scrollToBottom();

		// Poll every 3 seconds.
		const interval = setInterval(function() {
			pollJobStatus(token, interval, $progress);
		}, 3000);

		state.activeJobs[token] = { interval: interval, $element: $progress };
	}

	/**
	 * Poll a single job's status
	 */
	function pollJobStatus(token, interval, $progress) {
		$.ajax({
			url: abwChat.ajaxUrl,
			method: 'POST',
			data: {
				action: 'abw_check_job_status',
				nonce: abwChat.nonce,
				job_token: token
			},
			success: function(response) {
				if (!response.success) {
					return;
				}

				const data = response.data;

				if (data.status === 'completed') {
					// Stop polling.
					clearInterval(interval);
					delete state.activeJobs[token];

					// Remove progress indicator.
					$progress.remove();

					// Show the result in chat.
					if (data.result && data.result.response) {
						addMessage('assistant', data.result.response);

						// Show tool results if in debug mode.
						if (
							abwChat &&
							abwChat.debugToolResults &&
							data.result.tool_results &&
							data.result.tool_results.length > 0
						) {
							data.result.tool_results.forEach(function(result) {
								addToolResult(result.tool, result.result);
							});
						}
					} else {
						addMessage('assistant', 'Background task completed successfully.');
					}
				} else if (data.status === 'failed') {
					// Stop polling.
					clearInterval(interval);
					delete state.activeJobs[token];

					// Remove progress indicator and show error.
					$progress.remove();
					addErrorMessage(data.error || 'Background task failed. You can retry from the Background Jobs page.');
				} else if (data.status === 'processing') {
					// Update the status text.
					$progress.find('.abw-job-status-text').html(
						'Processing <strong>' + escapeHtml(data.job_type.replace(/_/g, ' ')) + '</strong>... (attempt ' + data.attempts + ')'
					);
				}
				// 'pending' status: keep polling, Layer 3 will attempt inline processing.
			},
			error: function() {
				// Network error, keep polling - will retry next interval.
			}
		});
	}

	/**
	 * Show suggested follow-up buttons after an assistant message
	 */
	function addFollowUpSuggestions(responseText) {
		if (!responseText || responseText.length < 50) return;

		var suggestions = [];
		var lower = responseText.toLowerCase();

		if (lower.includes('post') || lower.includes('article')) {
			suggestions.push({label: 'Show more details', prompt: 'Show me more details about this'});
			suggestions.push({label: 'Edit this post', prompt: 'Edit this post'});
		}
		if (lower.includes('plugin') || lower.includes('theme')) {
			suggestions.push({label: 'Check for updates', prompt: 'Check for plugin and theme updates'});
		}
		if (lower.includes('created') || lower.includes('updated') || lower.includes('deleted')) {
			suggestions.push({label: 'Undo this action', prompt: 'Can you undo the last action?'});
		}
		if (lower.includes('error') || lower.includes('issue') || lower.includes('problem')) {
			suggestions.push({label: 'How to fix this?', prompt: 'How can I fix this issue?'});
		}

		if (suggestions.length === 0) {
			suggestions = [
				{label: 'Tell me more', prompt: 'Tell me more about this'},
				{label: 'What else can you do?', prompt: 'What else can you help me with?'},
			];
		}

		suggestions = suggestions.slice(0, 3);

		var html = '<div class="abw-follow-ups">';
		suggestions.forEach(function(s) {
			html += '<button class="abw-follow-up-btn" data-prompt="' + escapeHtml(s.prompt) + '">' + escapeHtml(s.label) + '</button>';
		});
		html += '</div>';

		$messages.append(html);
		scrollToBottom();
	}

	/**
	 * Show a confirmation dialog in the chat
	 */
	function addConfirmation(message, onConfirm) {
		var $confirm = $('<div class="abw-confirmation">' +
			'<p>' + escapeHtml(message) + '</p>' +
			'<div class="abw-confirmation-actions">' +
			'<button class="abw-confirm-yes">Yes, proceed</button>' +
			'<button class="abw-confirm-no">Cancel</button>' +
			'</div></div>');

		$confirm.find('.abw-confirm-yes').on('click', function() {
			$confirm.remove();
			if (onConfirm) onConfirm();
		});
		$confirm.find('.abw-confirm-no').on('click', function() {
			$confirm.remove();
			addMessage('assistant', 'Action cancelled.');
		});

		$messages.append($confirm);
		scrollToBottom();
	}

	/**
	 * Export chat history as a text file
	 */
	function exportChat() {
		var text = '';
		$('.abw-message').each(function() {
			var role = $(this).hasClass('abw-message-user') ? 'You' : 'AI';
			var content = $(this).find('.abw-message-content').text().trim();
			if (content) {
				text += role + ': ' + content + '\n\n';
			}
		});

		if (!text) {
			alert('No messages to export.');
			return;
		}

		var blob = new Blob([text], {type: 'text/plain'});
		var url = URL.createObjectURL(blob);
		var a = document.createElement('a');
		a.href = url;
		a.download = 'abw-ai-chat-' + new Date().toISOString().slice(0, 10) + '.txt';
		a.click();
		URL.revokeObjectURL(url);
	}

	// Initialize when DOM is ready
	$(document).ready(init);

})(jQuery);
