<?php
/**
 * AI Tools and Features
 *
 * Extended AI capabilities for content generation, design, images, and code.
 *
 * @package ABW_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ABW_AI_Tools
 *
 * Provides specialized AI tools for content, design, and code generation.
 */
class ABW_AI_Tools {

	/**
	 * Generate blog post content
	 *
	 * @param array $input Input parameters
	 * @return array|WP_Error
	 */
	public static function generate_post_content( array $input ) {
		$topic = $input['topic'] ?? '';
		$style = $input['style'] ?? 'informative';
		$length = $input['length'] ?? 'medium';
		$keywords = $input['keywords'] ?? [];

		if ( empty( $topic ) ) {
			return new WP_Error( 'missing_topic', __( 'Please provide a topic for the post.', 'abw-ai' ) );
		}

		$length_guide = [
			'short'  => '300-500 words',
			'medium' => '800-1200 words',
			'long'   => '1500-2500 words',
		];

		$prompt = sprintf(
			"Write a %s blog post about: %s\n\nLength: %s\nStyle: %s\n%s\n\nProvide the content in HTML format suitable for WordPress. Include:\n- An engaging introduction\n- Clear section headers (use h2, h3 tags)\n- Bullet points where appropriate\n- A conclusion with call-to-action",
			$style,
			$topic,
			$length_guide[ $length ] ?? $length_guide['medium'],
			$style,
			! empty( $keywords ) ? 'Keywords to include: ' . implode( ', ', $keywords ) : ''
		);

		$messages = [
			[
				'role'    => 'system',
				'content' => 'You are an expert content writer. Generate high-quality, SEO-friendly blog content. Output HTML only, no markdown.',
			],
			[
				'role'    => 'user',
				'content' => $prompt,
			],
		];

		$response = ABW_AI_Router::chat( $messages );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return [
			'content' => $response['content'],
			'title'   => self::extract_title( $response['content'] ),
			'excerpt' => self::generate_excerpt( $response['content'] ),
		];
	}

	/**
	 * Rewrite/improve existing content
	 *
	 * @param array $input Input parameters
	 * @return array|WP_Error
	 */
	public static function improve_content( array $input ) {
		$content = $input['content'] ?? '';
		$goal = $input['goal'] ?? 'improve';
		$tone = $input['tone'] ?? 'professional';

		if ( empty( $content ) ) {
			return new WP_Error( 'missing_content', __( 'Please provide content to improve.', 'abw-ai' ) );
		}

		$goals = [
			'improve'    => 'Improve clarity, readability, and engagement',
			'simplify'   => 'Simplify the language for a general audience',
			'expand'     => 'Expand and add more detail',
			'shorten'    => 'Make it more concise while keeping key points',
			'seo'        => 'Optimize for SEO while maintaining quality',
			'formal'     => 'Make it more formal and professional',
			'casual'     => 'Make it more conversational and friendly',
		];

		$prompt = sprintf(
			"Rewrite the following content. Goal: %s\nTone: %s\n\nOriginal content:\n%s\n\nProvide the rewritten content in HTML format.",
			$goals[ $goal ] ?? $goals['improve'],
			$tone,
			$content
		);

		$messages = [
			[
				'role'    => 'system',
				'content' => 'You are an expert editor. Rewrite content to meet the specified goal while preserving the original meaning.',
			],
			[
				'role'    => 'user',
				'content' => $prompt,
			],
		];

		$response = ABW_AI_Router::chat( $messages );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return [
			'improved_content' => $response['content'],
			'original_length'  => str_word_count( strip_tags( $content ) ),
			'new_length'       => str_word_count( strip_tags( $response['content'] ) ),
		];
	}

	/**
	 * Generate SEO meta description and title
	 *
	 * @param array $input Input parameters
	 * @return array|WP_Error
	 */
	public static function generate_seo_meta( array $input ) {
		$content = $input['content'] ?? '';
		$title = $input['current_title'] ?? '';
		$focus_keyword = $input['focus_keyword'] ?? '';

		if ( empty( $content ) && empty( $title ) ) {
			return new WP_Error( 'missing_input', __( 'Please provide content or title.', 'abw-ai' ) );
		}

		$prompt = sprintf(
			"Generate SEO-optimized metadata for the following content:\n\nTitle: %s\nContent: %s\n%s\n\nProvide:\n1. Optimized SEO title (max 60 characters)\n2. Meta description (max 155 characters)\n3. 5 relevant focus keywords\n\nFormat as JSON.",
			$title,
			substr( strip_tags( $content ), 0, 1000 ),
			$focus_keyword ? "Focus keyword: $focus_keyword" : ''
		);

		$messages = [
			[
				'role'    => 'system',
				'content' => 'You are an SEO expert. Generate metadata that maximizes click-through rates while accurately representing content. Output valid JSON only.',
			],
			[
				'role'    => 'user',
				'content' => $prompt,
			],
		];

		$response = ABW_AI_Router::chat( $messages );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Parse JSON response
		$meta = json_decode( $response['content'], true );
		
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			// Try to extract from text
			return [
				'seo_title'        => '',
				'meta_description' => '',
				'keywords'         => [],
				'raw_response'     => $response['content'],
			];
		}

		return [
			'seo_title'        => $meta['seo_title'] ?? $meta['title'] ?? '',
			'meta_description' => $meta['meta_description'] ?? $meta['description'] ?? '',
			'keywords'         => $meta['keywords'] ?? $meta['focus_keywords'] ?? [],
		];
	}

	/**
	 * Translate content
	 *
	 * @param array $input Input parameters
	 * @return array|WP_Error
	 */
	public static function translate_content( array $input ) {
		$content = $input['content'] ?? '';
		$target_language = $input['target_language'] ?? 'Spanish';
		$preserve_formatting = $input['preserve_formatting'] ?? true;

		if ( empty( $content ) ) {
			return new WP_Error( 'missing_content', __( 'Please provide content to translate.', 'abw-ai' ) );
		}

		$prompt = sprintf(
			"Translate the following content to %s. %s\n\nContent:\n%s",
			$target_language,
			$preserve_formatting ? 'Preserve all HTML formatting and structure.' : '',
			$content
		);

		$messages = [
			[
				'role'    => 'system',
				'content' => 'You are a professional translator. Provide accurate, natural-sounding translations that maintain the original tone and meaning.',
			],
			[
				'role'    => 'user',
				'content' => $prompt,
			],
		];

		$response = ABW_AI_Router::chat( $messages );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return [
			'translated_content' => $response['content'],
			'target_language'    => $target_language,
		];
	}

	/**
	 * Generate Elementor layout from description
	 *
	 * @param array $input Input parameters
	 * @return array|WP_Error
	 */
	public static function generate_elementor_layout( array $input ) {
		$description = $input['description'] ?? '';
		$page_type = $input['page_type'] ?? 'landing';
		$style = $input['style'] ?? 'modern';

		if ( empty( $description ) ) {
			return new WP_Error( 'missing_description', __( 'Please describe the layout you want.', 'abw-ai' ) );
		}

		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return new WP_Error( 'elementor_inactive', __( 'Elementor is not active.', 'abw-ai' ) );
		}

		$prompt = sprintf(
			"Generate an Elementor page layout for: %s\n\nPage type: %s\nStyle: %s\n\nProvide the layout as a JSON array of Elementor elements. Include:\n- Container sections with proper widths\n- Heading, text-editor, image, button widgets\n- Responsive settings for tablet/mobile\n- Use modern flexbox layouts\n\nOutput valid JSON only.",
			$description,
			$page_type,
			$style
		);

		$messages = [
			[
				'role'    => 'system',
				'content' => 'You are an expert web designer specializing in Elementor. Generate valid Elementor JSON data structures. Each element needs id, elType, widgetType (for widgets), settings, and elements (for containers).',
			],
			[
				'role'    => 'user',
				'content' => $prompt,
			],
		];

		$response = ABW_AI_Router::chat( $messages );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Parse JSON response
		$elements = json_decode( $response['content'], true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error( 'invalid_json', __( 'Failed to generate valid Elementor layout.', 'abw-ai' ) );
		}

		return [
			'elements'    => $elements,
			'description' => $description,
		];
	}

	/**
	 * Generate CSS from description
	 *
	 * @param array $input Input parameters
	 * @return array|WP_Error
	 */
	public static function generate_css( array $input ) {
		$description = $input['description'] ?? '';
		$selector = $input['selector'] ?? '';
		$existing_css = $input['existing_css'] ?? '';

		if ( empty( $description ) ) {
			return new WP_Error( 'missing_description', __( 'Please describe the CSS you need.', 'abw-ai' ) );
		}

		$prompt = sprintf(
			"Generate CSS for: %s\n%s%s\n\nProvide clean, well-commented CSS. Use modern CSS features. Include vendor prefixes only where necessary.",
			$description,
			$selector ? "Target selector: $selector\n" : '',
			$existing_css ? "Existing CSS to modify/extend:\n$existing_css\n" : ''
		);

		$messages = [
			[
				'role'    => 'system',
				'content' => 'You are a CSS expert. Generate clean, efficient, and well-structured CSS. Output CSS code only, no explanations.',
			],
			[
				'role'    => 'user',
				'content' => $prompt,
			],
		];

		$response = ABW_AI_Router::chat( $messages );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Clean up response - remove markdown code blocks if present
		$css = $response['content'];
		$css = preg_replace( '/^```css\s*/', '', $css );
		$css = preg_replace( '/\s*```$/', '', $css );

		return [
			'css'         => trim( $css ),
			'description' => $description,
		];
	}

	/**
	 * Generate shortcode or HTML snippet
	 *
	 * @param array $input Input parameters
	 * @return array|WP_Error
	 */
	public static function generate_html_snippet( array $input ) {
		$description = $input['description'] ?? '';
		$type = $input['type'] ?? 'html';

		if ( empty( $description ) ) {
			return new WP_Error( 'missing_description', __( 'Please describe the snippet you need.', 'abw-ai' ) );
		}

		$prompt = sprintf(
			"Generate a %s snippet for: %s\n\nRequirements:\n- Clean, semantic HTML\n- Accessible (ARIA labels where needed)\n- Mobile-responsive\n- Use classes for styling\n\nProvide the code only.",
			$type,
			$description
		);

		$messages = [
			[
				'role'    => 'system',
				'content' => 'You are a front-end developer. Generate clean, semantic, accessible HTML/shortcode snippets. Output code only.',
			],
			[
				'role'    => 'user',
				'content' => $prompt,
			],
		];

		$response = ABW_AI_Router::chat( $messages );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Clean up response
		$snippet = $response['content'];
		$snippet = preg_replace( '/^```(html|php)?\s*/', '', $snippet );
		$snippet = preg_replace( '/\s*```$/', '', $snippet );

		return [
			'snippet'     => trim( $snippet ),
			'type'        => $type,
			'description' => $description,
		];
	}

	/**
	 * Suggest color scheme
	 *
	 * @param array $input Input parameters
	 * @return array|WP_Error
	 */
	public static function suggest_color_scheme( array $input ) {
		$industry = $input['industry'] ?? 'general';
		$mood = $input['mood'] ?? 'professional';
		$base_color = $input['base_color'] ?? '';

		$prompt = sprintf(
			"Generate a color scheme for a %s website.\nMood: %s\n%s\n\nProvide 5-6 colors with:\n- Primary color\n- Secondary color\n- Accent color\n- Background colors (light/dark)\n- Text colors\n\nFormat as JSON with hex values and color names.",
			$industry,
			$mood,
			$base_color ? "Base/brand color: $base_color" : ''
		);

		$messages = [
			[
				'role'    => 'system',
				'content' => 'You are a color theory and branding expert. Generate cohesive, accessible color palettes. Ensure sufficient contrast ratios. Output valid JSON.',
			],
			[
				'role'    => 'user',
				'content' => $prompt,
			],
		];

		$response = ABW_AI_Router::chat( $messages );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$colors = json_decode( $response['content'], true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return [
				'raw_response' => $response['content'],
				'colors'       => [],
			];
		}

		return [
			'colors'   => $colors,
			'industry' => $industry,
			'mood'     => $mood,
		];
	}

	/**
	 * Extract title from HTML content
	 *
	 * @param string $content HTML content
	 * @return string
	 */
	private static function extract_title( string $content ): string {
		if ( preg_match( '/<h1[^>]*>([^<]+)<\/h1>/i', $content, $matches ) ) {
			return $matches[1];
		}
		if ( preg_match( '/<h2[^>]*>([^<]+)<\/h2>/i', $content, $matches ) ) {
			return $matches[1];
		}
		return '';
	}

	/**
	 * Generate excerpt from content
	 *
	 * @param string $content HTML content
	 * @param int    $length  Number of words
	 * @return string
	 */
	private static function generate_excerpt( string $content, int $length = 55 ): string {
		$text = strip_tags( $content );
		$words = explode( ' ', $text );
		
		if ( count( $words ) <= $length ) {
			return $text;
		}
		
		return implode( ' ', array_slice( $words, 0, $length ) ) . '...';
	}

	/**
	 * Get available AI tools list
	 *
	 * @return array
	 */
	public static function get_tools_list(): array {
		return [
			[
				'name'        => 'generate_post_content',
				'description' => 'Generate blog post content from a topic',
				'parameters'  => [
					'type'       => 'object',
					'required'   => [ 'topic' ],
					'properties' => [
						'topic'    => [ 'type' => 'string', 'description' => 'Post topic' ],
						'style'    => [ 'type' => 'string', 'enum' => [ 'informative', 'persuasive', 'entertaining', 'technical' ] ],
						'length'   => [ 'type' => 'string', 'enum' => [ 'short', 'medium', 'long' ] ],
						'keywords' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
					],
				],
			],
			[
				'name'        => 'improve_content',
				'description' => 'Rewrite and improve existing content',
				'parameters'  => [
					'type'       => 'object',
					'required'   => [ 'content' ],
					'properties' => [
						'content' => [ 'type' => 'string', 'description' => 'Content to improve' ],
						'goal'    => [ 'type' => 'string', 'enum' => [ 'improve', 'simplify', 'expand', 'shorten', 'seo', 'formal', 'casual' ] ],
						'tone'    => [ 'type' => 'string' ],
					],
				],
			],
			[
				'name'        => 'generate_seo_meta',
				'description' => 'Generate SEO title and meta description',
				'parameters'  => [
					'type'       => 'object',
					'properties' => [
						'content'       => [ 'type' => 'string' ],
						'current_title' => [ 'type' => 'string' ],
						'focus_keyword' => [ 'type' => 'string' ],
					],
				],
			],
			[
				'name'        => 'translate_content',
				'description' => 'Translate content to another language',
				'parameters'  => [
					'type'       => 'object',
					'required'   => [ 'content', 'target_language' ],
					'properties' => [
						'content'         => [ 'type' => 'string' ],
						'target_language' => [ 'type' => 'string' ],
					],
				],
			],
			[
				'name'        => 'generate_css',
				'description' => 'Generate CSS from a description',
				'parameters'  => [
					'type'       => 'object',
					'required'   => [ 'description' ],
					'properties' => [
						'description'  => [ 'type' => 'string' ],
						'selector'     => [ 'type' => 'string' ],
						'existing_css' => [ 'type' => 'string' ],
					],
				],
			],
			[
				'name'        => 'suggest_color_scheme',
				'description' => 'Suggest a color scheme for a website',
				'parameters'  => [
					'type'       => 'object',
					'properties' => [
						'industry'   => [ 'type' => 'string' ],
						'mood'       => [ 'type' => 'string' ],
						'base_color' => [ 'type' => 'string', 'description' => 'Hex color to build from' ],
					],
				],
			],
		];
	}
}
