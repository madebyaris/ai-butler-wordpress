<?php

/**
 * AI Tools and Features
 *
 * Extended AI capabilities for content generation, design, images, and code.
 *
 * @package ABW_AI
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Class ABW_AI_Tools
 *
 * Provides specialized AI tools for content, design, and code generation.
 */
class ABW_AI_Tools
{
    /**
     * Generate blog post content
     *
     * @param array $input Input parameters
     * @return array|WP_Error
     */
    public static function generate_post_content(array $input)
    {
        $topic = $input['topic'] ?? '';
        $style = $input['style'] ?? 'informative';
        $length = $input['length'] ?? 'medium';
        $keywords = $input['keywords'] ?? [];

        if (empty($topic)) {
            return new WP_Error('missing_topic', __('Please provide a topic for the post.', 'abw-ai'));
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
            $length_guide[$length] ?? $length_guide['medium'],
            $style,
            ! empty($keywords) ? 'Keywords to include: ' . implode(', ', $keywords) : ''
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

        $response = ABW_AI_Router::chat($messages);

        if (is_wp_error($response)) {
            return $response;
        }

        return [
            'content' => $response['content'],
            'title'   => self::extract_title($response['content']),
            'excerpt' => self::generate_excerpt($response['content']),
        ];
    }

    /**
     * Rewrite/improve existing content
     *
     * @param array $input Input parameters
     * @return array|WP_Error
     */
    public static function improve_content(array $input)
    {
        $content = $input['content'] ?? '';
        $goal = $input['goal'] ?? 'improve';
        $tone = $input['tone'] ?? 'professional';

        if (empty($content)) {
            return new WP_Error('missing_content', __('Please provide content to improve.', 'abw-ai'));
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
            $goals[$goal] ?? $goals['improve'],
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

        $response = ABW_AI_Router::chat($messages);

        if (is_wp_error($response)) {
            return $response;
        }

        return [
            'improved_content' => $response['content'],
            'original_length'  => str_word_count(strip_tags($content)),
            'new_length'       => str_word_count(strip_tags($response['content'])),
        ];
    }

    /**
     * Generate SEO meta description and title
     *
     * @param array $input Input parameters
     * @return array|WP_Error
     */
    public static function generate_seo_meta(array $input)
    {
        $content = $input['content'] ?? '';
        $title = $input['current_title'] ?? '';
        $focus_keyword = $input['focus_keyword'] ?? '';

        if (empty($content) && empty($title)) {
            return new WP_Error('missing_input', __('Please provide content or title.', 'abw-ai'));
        }

        $prompt = sprintf(
            "Generate SEO-optimized metadata for the following content:\n\nTitle: %s\nContent: %s\n%s\n\nProvide:\n1. Optimized SEO title (max 60 characters)\n2. Meta description (max 155 characters)\n3. 5 relevant focus keywords\n\nFormat as JSON.",
            $title,
            substr(strip_tags($content), 0, 1000),
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

        $response = ABW_AI_Router::chat($messages);

        if (is_wp_error($response)) {
            return $response;
        }

        // Parse JSON response
        $meta = json_decode($response['content'], true);

        if (json_last_error() !== JSON_ERROR_NONE) {
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
    public static function translate_content(array $input)
    {
        $content = $input['content'] ?? '';
        $target_language = $input['target_language'] ?? 'Spanish';
        $preserve_formatting = $input['preserve_formatting'] ?? true;

        if (empty($content)) {
            return new WP_Error('missing_content', __('Please provide content to translate.', 'abw-ai'));
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

        $response = ABW_AI_Router::chat($messages);

        if (is_wp_error($response)) {
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
    public static function generate_elementor_layout(array $input)
    {
        $description = $input['description'] ?? '';
        $page_type = $input['page_type'] ?? 'landing';
        $style = $input['style'] ?? 'modern';

        if (empty($description)) {
            return new WP_Error('missing_description', __('Please describe the layout you want.', 'abw-ai'));
        }

        if (! class_exists('\Elementor\Plugin')) {
            return new WP_Error('elementor_inactive', __('Elementor is not active.', 'abw-ai'));
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

        $response = ABW_AI_Router::chat($messages);

        if (is_wp_error($response)) {
            return $response;
        }

        // Parse JSON response
        $elements = json_decode($response['content'], true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('invalid_json', __('Failed to generate valid Elementor layout.', 'abw-ai'));
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
    public static function generate_css(array $input)
    {
        $description = $input['description'] ?? '';
        $selector = $input['selector'] ?? '';
        $existing_css = $input['existing_css'] ?? '';

        if (empty($description)) {
            return new WP_Error('missing_description', __('Please describe the CSS you need.', 'abw-ai'));
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

        $response = ABW_AI_Router::chat($messages);

        if (is_wp_error($response)) {
            return $response;
        }

        // Clean up response - remove markdown code blocks if present
        $css = $response['content'];
        $css = preg_replace('/^```css\s*/', '', $css);
        $css = preg_replace('/\s*```$/', '', $css);

        return [
            'css'         => trim($css),
            'description' => $description,
        ];
    }

    /**
     * Generate shortcode or HTML snippet
     *
     * @param array $input Input parameters
     * @return array|WP_Error
     */
    public static function generate_html_snippet(array $input)
    {
        $description = $input['description'] ?? '';
        $type = $input['type'] ?? 'html';

        if (empty($description)) {
            return new WP_Error('missing_description', __('Please describe the snippet you need.', 'abw-ai'));
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

        $response = ABW_AI_Router::chat($messages);

        if (is_wp_error($response)) {
            return $response;
        }

        // Clean up response
        $snippet = $response['content'];
        $snippet = preg_replace('/^```(html|php)?\s*/', '', $snippet);
        $snippet = preg_replace('/\s*```$/', '', $snippet);

        return [
            'snippet'     => trim($snippet),
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
    public static function suggest_color_scheme(array $input)
    {
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

        $response = ABW_AI_Router::chat($messages);

        if (is_wp_error($response)) {
            return $response;
        }

        $colors = json_decode($response['content'], true);

        if (json_last_error() !== JSON_ERROR_NONE) {
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
     * Generate image alt text using AI
     *
     * @param array $input Input parameters
     * @return array|WP_Error
     */
    public static function generate_image_alt(array $input)
    {
        $image_url = $input['image_url'] ?? '';
        $image_description = $input['image_description'] ?? '';

        if (empty($image_url) && empty($image_description)) {
            return new WP_Error('missing_input', __('Please provide image URL or description.', 'abw-ai'));
        }

        $prompt = sprintf(
            "Generate a concise, descriptive alt text for an image.%s\n\nGuidelines:\n- Be specific and descriptive\n- Keep it under 125 characters\n- Focus on what's important for accessibility\n- Don't include phrases like 'image of' or 'picture of'\n\nProvide only the alt text, no explanations.",
            $image_description ? "\n\nImage description: $image_description" : "\n\nImage URL: $image_url"
        );

        $messages = [
            [
                'role'    => 'system',
                'content' => 'You are an accessibility expert. Generate clear, concise alt text for images that helps visually impaired users understand the content.',
            ],
            [
                'role'    => 'user',
                'content' => $prompt,
            ],
        ];

        $response = ABW_AI_Router::chat($messages);

        if (is_wp_error($response)) {
            return $response;
        }

        return [
            'alt_text' => trim($response['content']),
        ];
    }

    /**
     * Generate FAQ from content
     *
     * @param array $input Input parameters
     * @return array|WP_Error
     */
    public static function generate_faq(array $input)
    {
        $content = $input['content'] ?? '';
        $count = $input['count'] ?? 5;

        if (empty($content)) {
            return new WP_Error('missing_content', __('Please provide content to generate FAQ from.', 'abw-ai'));
        }

        $prompt = sprintf(
            "Generate %d frequently asked questions (FAQs) based on the following content. Make the questions natural and the answers concise.\n\nContent:\n%s\n\nFormat as JSON array with 'question' and 'answer' fields.",
            $count,
            substr(strip_tags($content), 0, 2000)
        );

        $messages = [
            [
                'role'    => 'system',
                'content' => 'You are a content expert. Generate relevant FAQs that users would actually ask. Output valid JSON only.',
            ],
            [
                'role'    => 'user',
                'content' => $prompt,
            ],
        ];

        $response = ABW_AI_Router::chat($messages);

        if (is_wp_error($response)) {
            return $response;
        }

        $faq = json_decode($response['content'], true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'faq'          => [],
                'raw_response' => $response['content'],
            ];
        }

        return [
            'faq' => is_array($faq) ? $faq : [],
        ];
    }

    /**
     * Generate JSON-LD schema markup
     *
     * @param array $input Input parameters
     * @return array|WP_Error
     */
    public static function generate_schema_markup(array $input)
    {
        $type = $input['type'] ?? 'Article';
        $title = $input['title'] ?? '';
        $content = $input['content'] ?? '';
        $url = $input['url'] ?? '';

        if (empty($title)) {
            return new WP_Error('missing_title', __('Title is required.', 'abw-ai'));
        }

        $prompt = sprintf(
            "Generate JSON-LD schema.org markup for a %s.\n\nTitle: %s\n%s%s\n\nProvide valid JSON-LD following schema.org specifications. Include @context, @type, and relevant properties.",
            $type,
            $title,
            $content ? "Content excerpt: " . substr(strip_tags($content), 0, 500) . "\n" : '',
            $url ? "URL: $url\n" : ''
        );

        $messages = [
            [
                'role'    => 'system',
                'content' => 'You are a structured data expert. Generate valid JSON-LD schema markup following schema.org standards. Output valid JSON only.',
            ],
            [
                'role'    => 'user',
                'content' => $prompt,
            ],
        ];

        $response = ABW_AI_Router::chat($messages);

        if (is_wp_error($response)) {
            return $response;
        }

        $schema = json_decode($response['content'], true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // Try to extract JSON from markdown code blocks
            $content = $response['content'];
            if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $matches)) {
                $schema = json_decode($matches[1], true);
            }
        }

        return [
            'schema' => $schema ?? [],
            'json'   => wp_json_encode($schema ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * Summarize content
     *
     * @param array $input Input parameters
     * @return array|WP_Error
     */
    public static function summarize_content(array $input)
    {
        $content = $input['content'] ?? '';
        $length = $input['length'] ?? 'medium';

        if (empty($content)) {
            return new WP_Error('missing_content', __('Please provide content to summarize.', 'abw-ai'));
        }

        $length_guide = [
            'short'  => '2-3 sentences',
            'medium' => '1 paragraph (4-6 sentences)',
            'long'   => '2-3 paragraphs',
        ];

        $prompt = sprintf(
            "Summarize the following content in %s. Focus on the main points and key information.\n\nContent:\n%s",
            $length_guide[$length] ?? $length_guide['medium'],
            substr(strip_tags($content), 0, 5000)
        );

        $messages = [
            [
                'role'    => 'system',
                'content' => 'You are an expert at summarizing content. Create clear, concise summaries that capture the essence of the original content.',
            ],
            [
                'role'    => 'user',
                'content' => $prompt,
            ],
        ];

        $response = ABW_AI_Router::chat($messages);

        if (is_wp_error($response)) {
            return $response;
        }

        return [
            'summary'        => $response['content'],
            'original_length' => str_word_count(strip_tags($content)),
            'summary_length'  => str_word_count(strip_tags($response['content'])),
        ];
    }

    /**
     * Generate social media posts
     *
     * @param array $input Input parameters
     * @return array|WP_Error
     */
    public static function generate_social_posts(array $input)
    {
        $content = $input['content'] ?? '';
        $platforms = $input['platforms'] ?? ['twitter', 'facebook', 'linkedin'];

        if (empty($content)) {
            return new WP_Error('missing_content', __('Please provide content to create social posts from.', 'abw-ai'));
        }

        $platform_guides = [
            'twitter'  => '280 characters, engaging, use hashtags',
            'facebook' => 'Engaging, conversational, can be longer',
            'linkedin' => 'Professional, informative, business-focused',
            'instagram' => 'Visual, engaging, use emojis and hashtags',
        ];

        $platform_list = implode(', ', $platforms);
        $guides = [];
        foreach ($platforms as $platform) {
            if (isset($platform_guides[$platform])) {
                $guides[] = "$platform: " . $platform_guides[$platform];
            }
        }

        $prompt = sprintf(
            "Create social media posts for: %s\n\nPlatform guidelines:\n%s\n\nContent:\n%s\n\nGenerate one post per platform. Format as JSON array with 'platform' and 'post' fields.",
            $platform_list,
            implode("\n", $guides),
            substr(strip_tags($content), 0, 2000)
        );

        $messages = [
            [
                'role'    => 'system',
                'content' => 'You are a social media expert. Create engaging, platform-appropriate posts that drive engagement. Output valid JSON only.',
            ],
            [
                'role'    => 'user',
                'content' => $prompt,
            ],
        ];

        $response = ABW_AI_Router::chat($messages);

        if (is_wp_error($response)) {
            return $response;
        }

        $posts = json_decode($response['content'], true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'posts'         => [],
                'raw_response' => $response['content'],
            ];
        }

        return [
            'posts' => is_array($posts) ? $posts : [],
        ];
    }

    /**
     * Analyze content sentiment
     *
     * @param array $input Input parameters
     * @return array|WP_Error
     */
    public static function analyze_content_sentiment(array $input)
    {
        $content = $input['content'] ?? '';

        if (empty($content)) {
            return new WP_Error('missing_content', __('Please provide content to analyze.', 'abw-ai'));
        }

        $prompt = sprintf(
            "Analyze the sentiment of the following content. Provide:\n1. Overall sentiment (positive, negative, neutral)\n2. Sentiment score (0-100)\n3. Key emotional indicators\n4. Brief explanation\n\nContent:\n%s\n\nFormat as JSON.",
            substr(strip_tags($content), 0, 2000)
        );

        $messages = [
            [
                'role'    => 'system',
                'content' => 'You are a sentiment analysis expert. Analyze text sentiment accurately and provide detailed insights. Output valid JSON only.',
            ],
            [
                'role'    => 'user',
                'content' => $prompt,
            ],
        ];

        $response = ABW_AI_Router::chat($messages);

        if (is_wp_error($response)) {
            return $response;
        }

        $sentiment = json_decode($response['content'], true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'sentiment'     => 'neutral',
                'score'         => 50,
                'raw_response' => $response['content'],
            ];
        }

        return [
            'sentiment' => $sentiment['sentiment'] ?? 'neutral',
            'score'     => $sentiment['score'] ?? 50,
            'indicators' => $sentiment['indicators'] ?? [],
            'explanation' => $sentiment['explanation'] ?? '',
        ];
    }

    /**
     * Detect content language
     *
     * @param array $input Input parameters
     * @return array|WP_Error
     */
    public static function detect_content_language(array $input)
    {
        $content = $input['content'] ?? '';

        if (empty($content)) {
            return new WP_Error('missing_content', __('Please provide content to analyze.', 'abw-ai'));
        }

        $prompt = sprintf(
            "Detect the language of the following content. Provide:\n1. Language name\n2. Language code (ISO 639-1)\n3. Confidence level (0-100)\n\nContent:\n%s\n\nFormat as JSON.",
            substr(strip_tags($content), 0, 1000)
        );

        $messages = [
            [
                'role'    => 'system',
                'content' => 'You are a language detection expert. Identify languages accurately. Output valid JSON only.',
            ],
            [
                'role'    => 'user',
                'content' => $prompt,
            ],
        ];

        $response = ABW_AI_Router::chat($messages);

        if (is_wp_error($response)) {
            return $response;
        }

        $detection = json_decode($response['content'], true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'language'      => 'unknown',
                'code'         => 'unknown',
                'confidence'   => 0,
                'raw_response' => $response['content'],
            ];
        }

        return [
            'language'   => $detection['language'] ?? 'unknown',
            'code'       => $detection['code'] ?? 'unknown',
            'confidence' => $detection['confidence'] ?? 0,
        ];
    }

    /**
     * Check content accessibility
     *
     * @param array $input Input parameters
     * @return array|WP_Error
     */
    public static function check_content_accessibility(array $input)
    {
        $content = $input['content'] ?? '';

        if (empty($content)) {
            return new WP_Error('missing_content', __('Please provide content to check.', 'abw-ai'));
        }

        $prompt = sprintf(
            "Review the following content for accessibility issues. Check for:\n1. Missing alt text on images\n2. Poor heading structure\n3. Color contrast issues\n4. Link accessibility\n5. Readability\n\nProvide suggestions for improvement.\n\nContent:\n%s\n\nFormat as JSON with 'issues' array and 'suggestions' array.",
            substr($content, 0, 3000)
        );

        $messages = [
            [
                'role'    => 'system',
                'content' => 'You are an accessibility expert. Review content for WCAG compliance and provide actionable suggestions. Output valid JSON only.',
            ],
            [
                'role'    => 'user',
                'content' => $prompt,
            ],
        ];

        $response = ABW_AI_Router::chat($messages);

        if (is_wp_error($response)) {
            return $response;
        }

        $audit = json_decode($response['content'], true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'issues'        => [],
                'suggestions'  => [],
                'raw_response' => $response['content'],
            ];
        }

        return [
            'issues'      => $audit['issues'] ?? [],
            'suggestions' => $audit['suggestions'] ?? [],
        ];
    }

    /**
     * Extract title from HTML content
     *
     * @param string $content HTML content
     * @return string
     */
    private static function extract_title(string $content): string
    {
        if (preg_match('/<h1[^>]*>([^<]+)<\/h1>/i', $content, $matches)) {
            return $matches[1];
        }
        if (preg_match('/<h2[^>]*>([^<]+)<\/h2>/i', $content, $matches)) {
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
    private static function generate_excerpt(string $content, int $length = 55): string
    {
        $text = strip_tags($content);
        $words = explode(' ', $text);

        if (count($words) <= $length) {
            return $text;
        }

        return implode(' ', array_slice($words, 0, $length)) . '...';
    }

    /**
     * Get available AI tools list
     *
     * @return array
     */
    public static function get_tools_list(): array
    {
        return [
            [
                'name'        => 'generate_post_content',
                'description' => 'Generate blog post content from a topic',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['topic'],
                    'properties' => [
                        'topic'    => ['type' => 'string', 'description' => 'Post topic'],
                        'style'    => ['type' => 'string', 'enum' => ['informative', 'persuasive', 'entertaining', 'technical']],
                        'length'   => ['type' => 'string', 'enum' => ['short', 'medium', 'long']],
                        'keywords' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ],
            ],
            [
                'name'        => 'improve_content',
                'description' => 'Rewrite and improve existing content',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['content'],
                    'properties' => [
                        'content' => ['type' => 'string', 'description' => 'Content to improve'],
                        'goal'    => ['type' => 'string', 'enum' => ['improve', 'simplify', 'expand', 'shorten', 'seo', 'formal', 'casual']],
                        'tone'    => ['type' => 'string'],
                    ],
                ],
            ],
            [
                'name'        => 'generate_seo_meta',
                'description' => 'Generate SEO title and meta description',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'content'       => ['type' => 'string'],
                        'current_title' => ['type' => 'string'],
                        'focus_keyword' => ['type' => 'string'],
                    ],
                ],
            ],
            [
                'name'        => 'translate_content',
                'description' => 'Translate content to another language',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['content', 'target_language'],
                    'properties' => [
                        'content'         => ['type' => 'string'],
                        'target_language' => ['type' => 'string'],
                    ],
                ],
            ],
            [
                'name'        => 'generate_css',
                'description' => 'Generate CSS from a description',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['description'],
                    'properties' => [
                        'description'  => ['type' => 'string'],
                        'selector'     => ['type' => 'string'],
                        'existing_css' => ['type' => 'string'],
                    ],
                ],
            ],
            [
                'name'        => 'suggest_color_scheme',
                'description' => 'Suggest a color scheme for a website',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'industry'   => ['type' => 'string'],
                        'mood'       => ['type' => 'string'],
                        'base_color' => ['type' => 'string', 'description' => 'Hex color to build from'],
                    ],
                ],
            ],
            [
                'name'        => 'generate_image_alt',
                'description' => 'Generate alt text for images using AI',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'image_url' => ['type' => 'string', 'description' => 'Image URL'],
                        'image_description' => ['type' => 'string', 'description' => 'Image description'],
                    ],
                ],
            ],
            [
                'name'        => 'generate_faq',
                'description' => 'Generate FAQ questions and answers from content',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['content'],
                    'properties' => [
                        'content' => ['type' => 'string', 'description' => 'Content to generate FAQ from'],
                        'count'   => ['type' => 'integer', 'description' => 'Number of FAQs to generate', 'default' => 5],
                    ],
                ],
            ],
            [
                'name'        => 'generate_schema_markup',
                'description' => 'Generate JSON-LD schema markup for SEO',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['title'],
                    'properties' => [
                        'type'    => ['type' => 'string', 'description' => 'Schema type (Article, Product, etc.)', 'default' => 'Article'],
                        'title'   => ['type' => 'string', 'description' => 'Content title'],
                        'content' => ['type' => 'string', 'description' => 'Content excerpt'],
                        'url'     => ['type' => 'string', 'description' => 'Content URL'],
                    ],
                ],
            ],
            [
                'name'        => 'summarize_content',
                'description' => 'Create a summary of content',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['content'],
                    'properties' => [
                        'content' => ['type' => 'string', 'description' => 'Content to summarize'],
                        'length'  => ['type' => 'string', 'enum' => ['short', 'medium', 'long'], 'default' => 'medium'],
                    ],
                ],
            ],
            [
                'name'        => 'generate_social_posts',
                'description' => 'Generate social media posts from content',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['content'],
                    'properties' => [
                        'content'   => ['type' => 'string', 'description' => 'Content to create posts from'],
                        'platforms' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Platforms (twitter, facebook, linkedin, instagram)', 'default' => ['twitter', 'facebook', 'linkedin']],
                    ],
                ],
            ],
            [
                'name'        => 'analyze_content_sentiment',
                'description' => 'Analyze sentiment of content',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['content'],
                    'properties' => [
                        'content' => ['type' => 'string', 'description' => 'Content to analyze'],
                    ],
                ],
            ],
            [
                'name'        => 'detect_content_language',
                'description' => 'Detect the language of content',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['content'],
                    'properties' => [
                        'content' => ['type' => 'string', 'description' => 'Content to analyze'],
                    ],
                ],
            ],
            [
                'name'        => 'check_content_accessibility',
                'description' => 'Check content for accessibility issues',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['content'],
                    'properties' => [
                        'content' => ['type' => 'string', 'description' => 'Content to check'],
                    ],
                ],
            ],
        ];
    }
}
