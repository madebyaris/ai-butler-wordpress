/**
 * Block Serializer
 *
 * Converts the WordPress block editor's block tree into a human-readable
 * text representation that AI models (including those without vision) can
 * understand and reason about.
 *
 * @package ABW_AI
 */

/**
 * Maximum character length for a single block's text content before truncation.
 */
const MAX_CONTENT_LENGTH = 300;

/**
 * Maximum number of top-level blocks to fully serialize before summarizing.
 */
const MAX_DETAILED_BLOCKS = 80;

/**
 * Extract text content from a block's attributes.
 *
 * @param {Object} block WordPress block object.
 * @return {string} Human-readable content string.
 */
function extractBlockContent( block ) {
	const { name, attributes = {} } = block;

	switch ( name ) {
		case 'core/paragraph':
		case 'core/verse':
		case 'core/preformatted':
			return stripHtml( attributes.content || '' );

		case 'core/heading':
			return stripHtml( attributes.content || '' );

		case 'core/image':
			return attributes.url
				? `[image: ${ getFilename( attributes.url ) }${ attributes.alt ? ', alt="' + attributes.alt + '"' : '' }]`
				: '[image]';

		case 'core/gallery':
			return `[gallery: ${ ( attributes.images || [] ).length } images]`;

		case 'core/list': {
			const items = extractListItems( block );
			return items.length > 0 ? items.map( ( item ) => `- ${ item }` ).join( '\n' ) : '';
		}

		case 'core/quote':
			return stripHtml( attributes.value || attributes.citation || '' );

		case 'core/code':
			return `\`\`\`\n${ attributes.content || '' }\n\`\`\``;

		case 'core/html':
			return `[custom HTML: ${ ( attributes.content || '' ).length } chars]`;

		case 'core/table': {
			return serializeTable( attributes );
		}

		case 'core/embed':
			return `[embed: ${ attributes.url || 'unknown' }]`;

		case 'core/video':
			return `[video: ${ attributes.src ? getFilename( attributes.src ) : 'unknown' }]`;

		case 'core/audio':
			return `[audio: ${ attributes.src ? getFilename( attributes.src ) : 'unknown' }]`;

		case 'core/file':
			return `[file: ${ attributes.href ? getFilename( attributes.href ) : 'unknown' }]`;

		case 'core/cover':
			return attributes.url ? `[cover image: ${ getFilename( attributes.url ) }]` : '[cover]';

		case 'core/button':
		case 'core/buttons':
			return stripHtml( attributes.text || '' );

		case 'core/separator':
			return '---';

		case 'core/spacer':
			return `[spacer: ${ attributes.height || '40px' }]`;

		case 'core/shortcode':
			return `[shortcode: ${ attributes.text || '' }]`;

		case 'core/freeform':
			return stripHtml( attributes.content || '' );

		case 'core/pullquote':
			return stripHtml( attributes.value || attributes.citation || '' );

		default:
			// For unknown blocks, try common attribute patterns
			if ( attributes.content ) {
				return stripHtml( attributes.content );
			}
			return '';
	}
}

/**
 * Extract list items from a core/list block (which uses innerBlocks for items).
 *
 * @param {Object} block The list block.
 * @return {Array<string>} Array of item strings.
 */
function extractListItems( block ) {
	if ( block.innerBlocks && block.innerBlocks.length > 0 ) {
		return block.innerBlocks.map( ( item ) => {
			return stripHtml( item.attributes?.content || '' );
		} );
	}
	// Fallback: parse from values attribute (older format)
	if ( block.attributes?.values ) {
		const matches = block.attributes.values.match( /<li[^>]*>(.*?)<\/li>/gi );
		if ( matches ) {
			return matches.map( ( m ) => stripHtml( m ) );
		}
	}
	return [];
}

/**
 * Serialize a table block's attributes into readable text.
 *
 * @param {Object} attributes Table block attributes.
 * @return {string} Text representation.
 */
function serializeTable( attributes ) {
	const { head = [], body = [], foot = [] } = attributes;
	const rows = [ ...head, ...body, ...foot ];
	if ( rows.length === 0 ) {
		return '[empty table]';
	}

	return rows.map( ( row ) => {
		const cells = ( row.cells || [] ).map( ( cell ) => stripHtml( cell.content || '' ) );
		return '| ' + cells.join( ' | ' ) + ' |';
	} ).join( '\n' );
}

/**
 * Get relevant attributes string for a block type.
 *
 * @param {Object} block Block object.
 * @return {string} Attribute summary like "(level=2, align=center)".
 */
function getBlockAttributesSummary( block ) {
	const { name, attributes = {} } = block;
	const parts = [];

	switch ( name ) {
		case 'core/heading':
			if ( attributes.level && attributes.level !== 2 ) {
				parts.push( `level=${ attributes.level }` );
			} else {
				parts.push( `level=${ attributes.level || 2 }` );
			}
			break;

		case 'core/image':
			if ( attributes.id ) {
				parts.push( `id=${ attributes.id }` );
			}
			if ( attributes.sizeSlug ) {
				parts.push( `size=${ attributes.sizeSlug }` );
			}
			break;

		case 'core/list':
			parts.push( `ordered=${ attributes.ordered ? 'true' : 'false' }` );
			break;

		case 'core/columns':
			parts.push( `columns=${ ( block.innerBlocks || [] ).length }` );
			break;

		case 'core/cover':
			if ( attributes.dimRatio !== undefined ) {
				parts.push( `overlay=${ attributes.dimRatio }%` );
			}
			break;

		case 'core/embed':
			if ( attributes.providerNameSlug ) {
				parts.push( `provider=${ attributes.providerNameSlug }` );
			}
			break;

		case 'core/button':
			if ( attributes.url ) {
				parts.push( `url=${ attributes.url }` );
			}
			break;

		default:
			break;
	}

	// Common attributes
	if ( attributes.align ) {
		parts.push( `align=${ attributes.align }` );
	}
	if ( attributes.className ) {
		parts.push( `class=${ attributes.className }` );
	}

	return parts.length > 0 ? ` (${ parts.join( ', ' ) })` : '';
}

/**
 * Serialize a single block (and its children) to text.
 *
 * @param {Object} block  Block object.
 * @param {string} prefix Index prefix for nesting (e.g., "4.0.1").
 * @param {number} depth  Current nesting depth.
 * @return {string} Human-readable text.
 */
function serializeBlock( block, prefix = '0', depth = 0 ) {
	const indent = '  '.repeat( depth );
	const attrSummary = getBlockAttributesSummary( block );
	const content = extractBlockContent( block );

	// Truncate long content
	const truncatedContent = content.length > MAX_CONTENT_LENGTH
		? content.substring( 0, MAX_CONTENT_LENGTH ) + '...'
		: content;

	// Build the block line
	let line = `${ indent }[${ prefix }] ${ block.name }${ attrSummary }`;

	if ( truncatedContent ) {
		// For multi-line content (lists, code, tables), put on next line
		if ( truncatedContent.includes( '\n' ) ) {
			line += ':\n' + truncatedContent.split( '\n' ).map( ( l ) => indent + '  ' + l ).join( '\n' );
		} else {
			line += ': "' + truncatedContent + '"';
		}
	}

	// Serialize inner blocks
	const childLines = [];
	if ( block.innerBlocks && block.innerBlocks.length > 0 ) {
		// Skip list-item inner blocks (already handled in extractBlockContent)
		if ( block.name !== 'core/list' ) {
			block.innerBlocks.forEach( ( child, i ) => {
				childLines.push( serializeBlock( child, `${ prefix }.${ i }`, depth + 1 ) );
			} );
		}
	}

	if ( childLines.length > 0 ) {
		return line + ':\n' + childLines.join( '\n' );
	}

	return line;
}

/**
 * Serialize the full editor context to a text representation.
 *
 * @param {Array}  blocks   Array of top-level block objects.
 * @param {Object} postInfo Post metadata { postTitle, postId, postType, postStatus }.
 * @return {string} Full text representation.
 */
export function serializeEditorContext( blocks, postInfo = {} ) {
	const {
		postTitle = '',
		postId = 0,
		postType = 'post',
		postStatus = 'draft',
	} = postInfo;

	let text = 'Editor Context:\n';
	text += `- Post ID: ${ postId }\n`;
	text += `- Post Title: "${ postTitle }"\n`;
	text += `- Post Type: ${ postType }\n`;
	text += `- Post Status: ${ postStatus }\n`;
	text += `- Block Count: ${ blocks.length }\n`;

	if ( blocks.length === 0 ) {
		text += '\nBlocks: (empty - no content yet)\n';
		return text;
	}

	text += '\nBlocks:\n';

	const detailedCount = Math.min( blocks.length, MAX_DETAILED_BLOCKS );

	for ( let i = 0; i < detailedCount; i++ ) {
		text += serializeBlock( blocks[ i ], String( i ), 0 ) + '\n';
	}

	if ( blocks.length > MAX_DETAILED_BLOCKS ) {
		const remaining = blocks.length - MAX_DETAILED_BLOCKS;
		text += `\n... and ${ remaining } more blocks (truncated for brevity)\n`;
	}

	return text;
}

/**
 * Strip HTML tags from a string.
 *
 * @param {string} html HTML string.
 * @return {string} Plain text.
 */
function stripHtml( html ) {
	if ( ! html ) {
		return '';
	}
	// Use a temporary element to decode entities and strip tags
	if ( typeof document !== 'undefined' ) {
		const tmp = document.createElement( 'div' );
		tmp.innerHTML = html;
		return tmp.textContent || tmp.innerText || '';
	}
	// Fallback: regex strip
	return html.replace( /<[^>]*>/g, '' ).replace( /&amp;/g, '&' ).replace( /&lt;/g, '<' ).replace( /&gt;/g, '>' ).replace( /&quot;/g, '"' );
}

/**
 * Get filename from a URL.
 *
 * @param {string} url Full URL.
 * @return {string} Filename portion.
 */
function getFilename( url ) {
	if ( ! url ) {
		return 'unknown';
	}
	try {
		const parts = new URL( url ).pathname.split( '/' );
		return parts[ parts.length - 1 ] || 'unknown';
	} catch {
		return url.split( '/' ).pop() || 'unknown';
	}
}
