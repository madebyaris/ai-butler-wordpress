/**
 * Message Formatter
 *
 * Markdown-to-HTML formatter for chat messages, ported from the vanilla
 * chat-widget.js to work in React context.
 *
 * @package ABW_AI
 */

/**
 * Escape HTML entities.
 *
 * @param {string} text Raw text.
 * @return {string} Escaped text.
 */
function escapeHtml( text ) {
	const div = document.createElement( 'div' );
	div.textContent = text;
	return div.innerHTML;
}

/**
 * Format a chat message (markdown-like) into safe HTML.
 *
 * @param {string} content Raw message content.
 * @return {string} Formatted HTML string.
 */
export function formatMessage( content ) {
	if ( ! content ) {
		return '';
	}

	// Normalize newlines.
	content = String( content ).replace( /\r\n?/g, '\n' );

	// Remove reasoning tags.
	content = content.replace( /<think>([\s\S]*?)<\/think>/gi, '' );
	content = content.replace( /<think>([\s\S]*?)<\/redacted_reasoning>/gi, '' );

	// Extract fenced code blocks.
	const codeBlocks = [];
	content = content.replace( /```(\w+)?\n([\s\S]*?)```/g, ( _match, lang, code ) => {
		const id = codeBlocks.length;
		codeBlocks.push( {
			lang: ( lang || 'text' ).trim() || 'text',
			code: ( code || '' ).replace( /\n$/, '' ),
		} );
		return `%%ABW_CODEBLOCK_${ id }%%`;
	} );

	// Escape all remaining content.
	const escaped = escapeHtml( content );

	function safeHref( rawHref ) {
		const href = String( rawHref || '' ).trim();
		if ( ! href ) {
			return '#';
		}
		if ( /^(https?:\/\/|mailto:|tel:)/i.test( href ) ) {
			return href;
		}
		if ( /^(\/|#)/.test( href ) ) {
			return href;
		}
		return '#';
	}

	function inlineFormat( text ) {
		let out = text;
		out = out.replace( /\[([^\]\n]+)\]\(([^)\s]+)\)/g, ( _m, label, href ) => {
			const safe = safeHref( href );
			return `<a href="${ safe }" target="_blank" rel="noopener noreferrer">${ label }</a>`;
		} );
		out = out.replace( /`([^`\n]+)`/g, '<code>$1</code>' );
		out = out.replace( /\*\*([^*\n]+?)\*\*/g, '<strong>$1</strong>' );
		out = out.replace( /(^|[^*])\*([^*\n]+?)\*(?!\*)/g, '$1<em>$2</em>' );
		return out;
	}

	function splitTableRow( rowLine ) {
		const row = rowLine.trim().replace( /^\|/, '' ).replace( /\|$/, '' );
		return row.split( '|' ).map( ( c ) => c.trim() );
	}

	function isTableSeparatorRow( rowLine ) {
		if ( ! rowLine ) {
			return false;
		}
		const trimmed = rowLine.trim();
		if ( ! trimmed.includes( '|' ) ) {
			return false;
		}
		const cells = splitTableRow( trimmed );
		if ( cells.length < 2 ) {
			return false;
		}
		return cells.every( ( c ) => /^:?-{3,}:?$/.test( c ) );
	}

	const lines = escaped.split( '\n' );
	let html = '';
	let listType = null;
	let paragraph = [];

	function flushParagraph() {
		if ( paragraph.length === 0 ) {
			return;
		}
		const text = inlineFormat( paragraph.join( '<br>' ) );
		html += `<p>${ text }</p>`;
		paragraph = [];
	}

	function closeList() {
		if ( ! listType ) {
			return;
		}
		html += `</${ listType }>`;
		listType = null;
	}

	function openList( nextType ) {
		if ( listType === nextType ) {
			return;
		}
		closeList();
		listType = nextType;
		html += `<${ listType }>`;
	}

	for ( let i = 0; i < lines.length; i++ ) {
		const line = lines[ i ];
		const trimmed = line.trim();

		if ( trimmed === '' ) {
			flushParagraph();
			closeList();
			continue;
		}

		// Tables.
		if (
			trimmed.includes( '|' ) &&
			i + 1 < lines.length &&
			isTableSeparatorRow( lines[ i + 1 ].trim() )
		) {
			flushParagraph();
			closeList();

			const headerCells = splitTableRow( trimmed );
			i += 1;

			let tableHtml = '<table class="abw-md-table"><thead><tr>';
			headerCells.forEach( ( cell ) => {
				tableHtml += `<th>${ inlineFormat( cell ) }</th>`;
			} );
			tableHtml += '</tr></thead><tbody>';

			while ( i + 1 < lines.length ) {
				const nextLine = lines[ i + 1 ];
				const nextTrimmed = nextLine.trim();
				if ( nextTrimmed === '' ) {
					break;
				}
				if ( /^%%ABW_CODEBLOCK_\d+%%$/.test( nextTrimmed ) ) {
					break;
				}
				if ( /^#{1,3}\s+/.test( nextTrimmed ) ) {
					break;
				}
				if ( /^\s*[-*]\s+/.test( nextTrimmed ) ) {
					break;
				}
				if ( /^\s*\d+\.\s+/.test( nextTrimmed ) ) {
					break;
				}
				if ( ! nextTrimmed.includes( '|' ) ) {
					break;
				}

				i += 1;
				const rowCells = splitTableRow( nextTrimmed );
				tableHtml += '<tr>';
				headerCells.forEach( ( _, idx ) => {
					tableHtml += `<td>${ inlineFormat( rowCells[ idx ] || '' ) }</td>`;
				} );
				tableHtml += '</tr>';
			}

			tableHtml += '</tbody></table>';
			html += tableHtml;
			continue;
		}

		// Code block placeholder.
		const cbMatch = trimmed.match( /^%%ABW_CODEBLOCK_(\d+)%%$/ );
		if ( cbMatch ) {
			flushParagraph();
			closeList();
			html += trimmed;
			continue;
		}

		// Headers.
		const h3 = line.match( /^###\s+(.+)$/ );
		const h2 = line.match( /^##\s+(.+)$/ );
		const h1 = line.match( /^#\s+(.+)$/ );
		if ( h3 || h2 || h1 ) {
			flushParagraph();
			closeList();
			if ( h3 ) {
				html += `<h3>${ inlineFormat( h3[ 1 ] ) }</h3>`;
			} else if ( h2 ) {
				html += `<h2>${ inlineFormat( h2[ 1 ] ) }</h2>`;
			} else {
				html += `<h1>${ inlineFormat( h1[ 1 ] ) }</h1>`;
			}
			continue;
		}

		// Unordered list.
		const ulItem = line.match( /^\s*[-*]\s+(.+)$/ );
		if ( ulItem ) {
			flushParagraph();
			openList( 'ul' );
			html += `<li>${ inlineFormat( ulItem[ 1 ] ) }</li>`;
			continue;
		}

		// Ordered list.
		const olItem = line.match( /^\s*\d+\.\s+(.+)$/ );
		if ( olItem ) {
			flushParagraph();
			openList( 'ol' );
			html += `<li>${ inlineFormat( olItem[ 1 ] ) }</li>`;
			continue;
		}

		closeList();
		paragraph.push( line );
	}

	flushParagraph();
	closeList();

	// Restore code blocks.
	html = html.replace( /%%ABW_CODEBLOCK_(\d+)%%/g, ( _m, idxStr ) => {
		const idx = Number( idxStr );
		const block = codeBlocks[ idx ];
		if ( ! block ) {
			return '';
		}
		const lang = escapeHtml( block.lang );
		const code = escapeHtml( block.code );
		return `<pre><code class="language-${ lang }">${ code }</code></pre>`;
	} );

	return html;
}
