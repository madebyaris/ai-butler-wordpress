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
     * Decode a structured JSON payload with light repair fallbacks.
     *
     * @param string $content Raw model output.
     * @return array|null
     */
    private static function maybe_decode_json_payload(string $content)
    {
        $candidates = [trim($content)];

        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $content, $matches)) {
            $candidates[] = trim($matches[1]);
        }

        if (preg_match('/(\{[\s\S]*\}|\[[\s\S]*\])/s', $content, $matches)) {
            $candidates[] = trim($matches[1]);
        }

        foreach ($candidates as $candidate) {
            if ('' === $candidate) {
                continue;
            }

            $decoded = json_decode($candidate, true);
            if (JSON_ERROR_NONE === json_last_error() && is_array($decoded)) {
                return $decoded;
            }

            $normalized = preg_replace('/,\s*([\]}])/m', '$1', $candidate);
            $decoded    = json_decode((string) $normalized, true);
            if (JSON_ERROR_NONE === json_last_error() && is_array($decoded)) {
                return $decoded;
            }
        }

        $repaired = self::repair_json_payload($content);
        if ('' !== $repaired) {
            $decoded = json_decode($repaired, true);
            if (JSON_ERROR_NONE === json_last_error() && is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Ask the model to repair malformed JSON into valid JSON.
     *
     * @param string $content Raw model output.
     * @return string
     */
    private static function repair_json_payload(string $content): string
    {
        $messages = [
            [
                'role'    => 'system',
                'content' => 'You repair malformed JSON. Return valid JSON only with no explanation.',
            ],
            [
                'role'    => 'user',
                'content' => "Repair this into valid JSON while preserving the original structure as closely as possible:\n\n" . $content,
            ],
        ];

        $response = ABW_AI_Router::chat($messages, [], [ 'max_tokens' => 1200 ]);
        if (is_wp_error($response)) {
            return '';
        }

        return trim((string) ($response['content'] ?? ''));
    }

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
            'original_length'  => str_word_count(wp_strip_all_tags($content)),
            'new_length'       => str_word_count(wp_strip_all_tags($response['content'])),
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
            substr(wp_strip_all_tags($content), 0, 1000),
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
        $meta = self::maybe_decode_json_payload($response['content']);

        if (! is_array($meta)) {
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

        $colors = self::maybe_decode_json_payload($response['content']);

        if (! is_array($colors)) {
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
            substr(wp_strip_all_tags($content), 0, 2000)
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

        $faq = self::maybe_decode_json_payload($response['content']);

        if (! is_array($faq)) {
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
            $content ? "Content excerpt: " . substr(wp_strip_all_tags($content), 0, 500) . "\n" : '',
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

        $schema = self::maybe_decode_json_payload($response['content']);

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
            substr(wp_strip_all_tags($content), 0, 5000)
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
            'original_length' => str_word_count(wp_strip_all_tags($content)),
            'summary_length'  => str_word_count(wp_strip_all_tags($response['content'])),
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
            substr(wp_strip_all_tags($content), 0, 2000)
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

        $posts = self::maybe_decode_json_payload($response['content']);

        if (! is_array($posts)) {
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
            substr(wp_strip_all_tags($content), 0, 2000)
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

        $sentiment = self::maybe_decode_json_payload($response['content']);

        if (! is_array($sentiment)) {
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
            substr(wp_strip_all_tags($content), 0, 1000)
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

        $detection = self::maybe_decode_json_payload($response['content']);

        if (! is_array($detection)) {
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

        $audit = self::maybe_decode_json_payload($response['content']);

        if (! is_array($audit)) {
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
     * Generate WooCommerce product description
     *
     * @param array $input Input parameters
     * @return array|WP_Error
     */
    public static function generate_product_description(array $input)
    {
        $product_name = $input['product_name'] ?? '';
        $category = $input['category'] ?? '';
        $attributes = $input['attributes'] ?? [];
        $price = $input['price'] ?? '';
        $tone = $input['tone'] ?? 'persuasive';

        if (empty($product_name)) {
            return new WP_Error('missing_product_name', __('Please provide a product name.', 'abw-ai'));
        }

        $attributes_text = '';
        if (! empty($attributes)) {
            $attributes_text = "Product attributes:\n";
            foreach ($attributes as $key => $value) {
                $attributes_text .= "- $key: $value\n";
            }
        }

        $prompt = sprintf(
            "Generate a product description for: %s\n\n%s%s%sTone: %s\n\nProvide:\n1. A full product description (HTML, 150-300 words)\n2. A short description (2-3 sentences, plain text)\n\nFormat as JSON with 'description' and 'short_description' fields.",
            $product_name,
            $category ? "Category: $category\n" : '',
            $attributes_text,
            $price ? "Price: $price\n" : '',
            $tone
        );

        $messages = [
            [
                'role'    => 'system',
                'content' => 'You are an expert e-commerce copywriter. Generate compelling product descriptions that drive sales. Output valid JSON only.',
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

        $result = self::maybe_decode_json_payload($response['content']);

        if (! is_array($result)) {
            return [
                'description'       => $response['content'],
                'short_description' => '',
            ];
        }

        return [
            'description'       => $result['description'] ?? '',
            'short_description' => $result['short_description'] ?? '',
        ];
    }

    /**
     * Rewrite content in a specified tone
     *
     * @param array $input Input parameters
     * @return array|WP_Error
     */
    public static function rewrite_for_tone(array $input)
    {
        $content = $input['content'] ?? '';
        $tone = $input['tone'] ?? '';

        if (empty($content)) {
            return new WP_Error('missing_content', __('Please provide content to rewrite.', 'abw-ai'));
        }

        if (empty($tone)) {
            return new WP_Error('missing_tone', __('Please specify a tone.', 'abw-ai'));
        }

        $valid_tones = ['professional', 'casual', 'persuasive', 'humorous', 'academic', 'friendly', 'authoritative'];
        if (! in_array($tone, $valid_tones, true)) {
            return new WP_Error('invalid_tone', sprintf(
                /* translators: %s: comma-separated list of supported tones */
                __('Invalid tone. Choose from: %s', 'abw-ai'),
                implode(', ', $valid_tones)
            ));
        }

        $prompt = sprintf(
            "Rewrite the following content in a %s tone. Preserve the original meaning and key information.\n\nContent:\n%s\n\nProvide the rewritten content in HTML format.",
            $tone,
            $content
        );

        $messages = [
            [
                'role'    => 'system',
                'content' => 'You are an expert writer skilled at adapting content to different tones and styles. Rewrite content while preserving its meaning. Output HTML only.',
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
            'rewritten_content' => $response['content'],
            'tone_applied'      => $tone,
        ];
    }

    /**
     * Generate a structured content outline
     *
     * @param array $input Input parameters
     * @return array|WP_Error
     */
    public static function generate_outline(array $input)
    {
        $topic = $input['topic'] ?? '';
        $depth = $input['depth'] ?? 'detailed';
        $target_length = $input['target_length'] ?? '';

        if (empty($topic)) {
            return new WP_Error('missing_topic', __('Please provide a topic for the outline.', 'abw-ai'));
        }

        $prompt = sprintf(
            "Generate a %s content outline for: %s\n%s\nProvide a structured outline with:\n- A suggested title\n- Sections with headings\n- Subpoints for each section\n- Brief notes on what to cover\n\nFormat as JSON with 'title' and 'sections' array. Each section has 'heading', 'subpoints' (array of strings), and 'notes' (string).",
            $depth,
            $topic,
            $target_length ? "Target length: $target_length\n" : ''
        );

        $messages = [
            [
                'role'    => 'system',
                'content' => 'You are an expert content strategist. Generate well-structured outlines that serve as effective blueprints for content creation. Output valid JSON only.',
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

        $outline = self::maybe_decode_json_payload($response['content']);

        if (! is_array($outline)) {
            return [
                'title'        => '',
                'sections'     => [],
                'raw_response' => $response['content'],
            ];
        }

        return [
            'title'    => $outline['title'] ?? '',
            'sections' => $outline['sections'] ?? [],
        ];
    }

    /**
     * Expand an outline into full content
     *
     * @param array $input Input parameters
     * @return array|WP_Error
     */
    public static function expand_from_outline(array $input)
    {
        $outline = $input['outline'] ?? '';
        $style = $input['style'] ?? 'informative';
        $length = $input['length'] ?? 'medium';

        if (empty($outline)) {
            return new WP_Error('missing_outline', __('Please provide an outline to expand.', 'abw-ai'));
        }

        $prompt = sprintf(
            "Expand the following outline into full, well-written content.\n\nStyle: %s\nTarget length: %s\n\nOutline:\n%s\n\nWrite each section with proper HTML formatting (h2, h3, p tags). Make the content engaging, informative, and well-structured.",
            $style,
            $length,
            $outline
        );

        $messages = [
            [
                'role'    => 'system',
                'content' => 'You are an expert content writer. Expand outlines into comprehensive, well-written content. Output HTML only, no markdown.',
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
            'content'    => $response['content'],
            'word_count' => str_word_count(wp_strip_all_tags($response['content'])),
        ];
    }

    /**
     * Generate a compelling post excerpt using AI
     *
     * @param array $input Input parameters
     * @return array|WP_Error
     */
    public static function generate_excerpt_ai(array $input)
    {
        $content = $input['content'] ?? '';
        $max_length = $input['max_length'] ?? 155;

        if (empty($content)) {
            return new WP_Error('missing_content', __('Please provide content to generate an excerpt from.', 'abw-ai'));
        }

        $prompt = sprintf(
            "Generate a compelling excerpt/summary for the following content. The excerpt must be no longer than %d characters. It should be engaging and make readers want to read more.\n\nContent:\n%s\n\nProvide only the excerpt text, no explanations.",
            $max_length,
            substr(wp_strip_all_tags($content), 0, 3000)
        );

        $messages = [
            [
                'role'    => 'system',
                'content' => 'You are an expert copywriter. Generate compelling, concise excerpts that capture the essence of content and entice readers. Output plain text only.',
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

        $excerpt = trim($response['content']);
        if (strlen($excerpt) > $max_length) {
            $excerpt = substr($excerpt, 0, $max_length - 3) . '...';
        }

        return [
            'excerpt'         => $excerpt,
            'character_count' => strlen($excerpt),
        ];
    }

    /**
     * Generate a table of contents from HTML content
     *
     * @param array $input Input parameters
     * @return array|WP_Error
     */
    public static function generate_table_of_contents(array $input)
    {
        $content = $input['content'] ?? '';

        if (empty($content)) {
            return new WP_Error('missing_content', __('Please provide content to generate a table of contents from.', 'abw-ai'));
        }

        $toc = [];
        if (preg_match_all('/<(h[2-4])[^>]*(?:id=["\']([^"\']*)["\'])?[^>]*>(.*?)<\/\1>/is', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $index => $match) {
                $tag = strtolower($match[1]);
                $level = (int) substr($tag, 1);
                $text = wp_strip_all_tags($match[3]);
                $id = ! empty($match[2]) ? $match[2] : sanitize_title($text) . '-' . $index;

                $toc[] = [
                    'level' => $level,
                    'text'  => $text,
                    'id'    => $id,
                ];
            }
        }

        $html = '<nav class="table-of-contents"><ol>';
        $current_level = 2;
        foreach ($toc as $item) {
            while ($item['level'] > $current_level) {
                $html .= '<ol>';
                $current_level++;
            }
            while ($item['level'] < $current_level) {
                $html .= '</ol>';
                $current_level--;
            }
            $html .= sprintf('<li><a href="#%s">%s</a></li>', esc_attr($item['id']), esc_html($item['text']));
        }
        while ($current_level > 2) {
            $html .= '</ol>';
            $current_level--;
        }
        $html .= '</ol></nav>';

        return [
            'toc'  => $toc,
            'html' => $html,
        ];
    }

    // =========================================================================
    // i18n / Translation Tools
    // =========================================================================

    /**
     * Detect source language and translate a post's title, content, and excerpt
     *
     * @param array $input Input parameters
     * @return array|WP_Error
     */
    public static function detect_and_translate_post(array $input)
    {
        $post_id = $input['post_id'] ?? 0;
        $target_language = $input['target_language'] ?? '';

        if (empty($post_id)) {
            return new WP_Error('missing_post_id', __('Please provide a post ID.', 'abw-ai'));
        }
        if (empty($target_language)) {
            return new WP_Error('missing_target_language', __('Please provide a target language.', 'abw-ai'));
        }

        $post = get_post((int) $post_id);
        if (! $post) {
            return new WP_Error('invalid_post', __('Post not found.', 'abw-ai'));
        }

        $title   = $post->post_title;
        $content = $post->post_content;
        $excerpt = $post->post_excerpt;

        $prompt = sprintf(
            "You are given a WordPress post. First detect the source language, then translate all three fields to %s.\n\nTitle: %s\n\nContent:\n%s\n\nExcerpt:\n%s\n\nRespond with valid JSON only:\n{\"source_language\": \"...\", \"translated_title\": \"...\", \"translated_content\": \"...\", \"translated_excerpt\": \"...\"}",
            $target_language,
            $title,
            $content,
            $excerpt ?: '(empty)'
        );

        $messages = [
            [
                'role'    => 'system',
                'content' => 'You are a professional translator. Detect the source language and translate accurately while preserving HTML formatting. Output valid JSON only.',
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

        $result = self::maybe_decode_json_payload($response['content']);

        if (! is_array($result)) {
            return new WP_Error('parse_error', __('Failed to parse translation response.', 'abw-ai'));
        }

        return [
            'source_language'    => $result['source_language'] ?? 'unknown',
            'target_language'    => $target_language,
            'translated_title'   => $result['translated_title'] ?? '',
            'translated_content' => $result['translated_content'] ?? '',
            'translated_excerpt' => $result['translated_excerpt'] ?? '',
        ];
    }

    /**
     * Bulk translate multiple posts
     *
     * @param array $input Input parameters
     * @return array|WP_Error
     */
    public static function bulk_translate_posts(array $input)
    {
        $post_ids = $input['post_ids'] ?? [];
        $target_language = $input['target_language'] ?? '';
        $create_new = $input['create_new'] ?? false;

        if (empty($post_ids) || ! is_array($post_ids)) {
            return new WP_Error('missing_post_ids', __('Please provide an array of post IDs.', 'abw-ai'));
        }
        if (empty($target_language)) {
            return new WP_Error('missing_target_language', __('Please provide a target language.', 'abw-ai'));
        }

        $results = [];
        $translated = 0;

        foreach ($post_ids as $post_id) {
            $translation = self::detect_and_translate_post([
                'post_id'         => (int) $post_id,
                'target_language' => $target_language,
            ]);

            if (is_wp_error($translation)) {
                $results[] = [
                    'post_id' => $post_id,
                    'success' => false,
                    'error'   => $translation->get_error_message(),
                ];
                continue;
            }

            $entry = [
                'post_id'          => $post_id,
                'success'          => true,
                'translated_title' => $translation['translated_title'],
            ];

            if ($create_new) {
                $new_post_id = wp_insert_post([
                    'post_title'   => $translation['translated_title'] . ' [' . $target_language . ']',
                    'post_content' => $translation['translated_content'],
                    'post_excerpt' => $translation['translated_excerpt'],
                    'post_status'  => 'draft',
                    'post_type'    => get_post_type($post_id),
                ]);

                if (is_wp_error($new_post_id)) {
                    $entry['new_post_id'] = null;
                    $entry['create_error'] = $new_post_id->get_error_message();
                } else {
                    update_post_meta($new_post_id, '_abw_translated_from', (int) $post_id);
                    update_post_meta($new_post_id, '_abw_translation_language', $target_language);
                    $entry['new_post_id'] = $new_post_id;
                }
            }

            $results[] = $entry;
            $translated++;
        }

        return [
            'translated' => $translated,
            'total'      => count($post_ids),
            'results'    => $results,
        ];
    }

    /**
     * Manage translations via WPML or Polylang
     *
     * @param array $input Input parameters
     * @return array|WP_Error
     */
    public static function manage_translations(array $input)
    {
        $action  = $input['action'] ?? 'status';
        $post_id = $input['post_id'] ?? 0;

        if (empty($post_id)) {
            return new WP_Error('missing_post_id', __('Please provide a post ID.', 'abw-ai'));
        }

        $post = get_post((int) $post_id);
        if (! $post) {
            return new WP_Error('invalid_post', __('Post not found.', 'abw-ai'));
        }

        // Detect translation plugin
        $plugin = 'none';
        if (defined('ICL_SITEPRESS_VERSION')) {
            $plugin = 'wpml';
        } elseif (function_exists('pll_get_post_translations')) {
            $plugin = 'polylang';
        }

        $translations = [];

        if ($action === 'status') {
            if ($plugin === 'wpml' && function_exists('wpml_get_language_information')) {
                $lang_info = wpml_get_language_information(null, $post_id);
                $trid = apply_filters('wpml_element_trid', null, $post_id, 'post_' . $post->post_type);
                $all_translations = apply_filters('wpml_get_element_translations', null, $trid, 'post_' . $post->post_type);

                if (is_array($all_translations)) {
                    foreach ($all_translations as $lang => $t) {
                        $translations[$lang] = [
                            'post_id' => $t->element_id ?? null,
                            'status'  => isset($t->element_id) ? get_post_status($t->element_id) : 'not_translated',
                        ];
                    }
                }
            } elseif ($plugin === 'polylang') {
                $post_translations = pll_get_post_translations($post_id);
                foreach ($post_translations as $lang => $tid) {
                    $translations[$lang] = [
                        'post_id' => $tid,
                        'status'  => get_post_status($tid),
                    ];
                }
            }
        } elseif ($action === 'sync') {
            if ($plugin === 'wpml') {
                do_action('wpml_make_post_duplicates', $post_id);
                $translations['sync'] = 'triggered';
            } elseif ($plugin === 'polylang') {
                $translations['sync'] = 'polylang_does_not_support_auto_sync';
            } else {
                return new WP_Error('no_translation_plugin', __('No translation plugin (WPML or Polylang) detected.', 'abw-ai'));
            }
        } else {
            return new WP_Error('invalid_action', __('Action must be "status" or "sync".', 'abw-ai'));
        }

        return [
            'plugin'       => $plugin,
            'post_id'      => $post_id,
            'action'       => $action,
            'translations' => $translations,
        ];
    }

    // =========================================================================
    // Analytics & Reporting Tools
    // =========================================================================

    /**
     * Get content calendar (scheduled posts)
     *
     * @param array $input Input parameters
     * @return array|WP_Error
     */
    public static function get_content_calendar(array $input)
    {
        $days_ahead = max(1, (int) ($input['days_ahead'] ?? 30));

        $posts = get_posts([
            'post_status'    => 'future',
            'post_type'      => 'any',
            'posts_per_page' => 100,
            'orderby'        => 'date',
            'order'          => 'ASC',
            'date_query'     => [
                [
                    'after'     => 'now',
                    'before'    => gmdate('Y-m-d', strtotime("+{$days_ahead} days")),
                    'inclusive' => true,
                ],
            ],
        ]);

        $calendar = [];
        foreach ($posts as $post) {
            $categories = wp_get_post_categories($post->ID, ['fields' => 'names']);
            $author = get_the_author_meta('display_name', $post->post_author);

            $calendar[] = [
                'id'             => $post->ID,
                'title'          => $post->post_title,
                'scheduled_date' => $post->post_date,
                'author'         => $author,
                'post_type'      => $post->post_type,
                'categories'     => $categories,
            ];
        }

        return [
            'days_ahead' => $days_ahead,
            'total'      => count($calendar),
            'posts'      => $calendar,
        ];
    }

    /**
     * Get publishing statistics for a period
     *
     * @param array $input Input parameters
     * @return array|WP_Error
     */
    public static function get_publishing_stats(array $input)
    {
        $period = $input['period'] ?? 'month';

        $date_map = [
            'week'  => '-7 days',
            'month' => '-30 days',
            'year'  => '-365 days',
        ];
        $after = $date_map[$period] ?? $date_map['month'];

        $posts = get_posts([
            'post_status'    => 'publish',
            'post_type'      => 'any',
            'posts_per_page' => -1,
            'date_query'     => [
                ['after' => $after, 'inclusive' => true],
            ],
        ]);

        $by_date   = [];
        $by_author = [];
        $by_type   = [];

        foreach ($posts as $post) {
            $date = gmdate('Y-m-d', strtotime($post->post_date));
            $author = get_the_author_meta('display_name', $post->post_author);
            $type = $post->post_type;

            $by_date[$date]     = ($by_date[$date] ?? 0) + 1;
            $by_author[$author] = ($by_author[$author] ?? 0) + 1;
            $by_type[$type]     = ($by_type[$type] ?? 0) + 1;
        }

        ksort($by_date);
        arsort($by_author);
        arsort($by_type);

        return [
            'total_published' => count($posts),
            'by_date'         => $by_date,
            'by_author'       => $by_author,
            'by_type'         => $by_type,
            'period'          => $period,
        ];
    }

    /**
     * Get comment statistics for a period
     *
     * @param array $input Input parameters
     * @return array|WP_Error
     */
    public static function get_comment_stats(array $input)
    {
        $period = $input['period'] ?? 'month';

        $date_map = [
            'week'  => '-7 days',
            'month' => '-30 days',
            'year'  => '-365 days',
        ];
        $after = $date_map[$period] ?? $date_map['month'];
        $after_date = gmdate('Y-m-d H:i:s', strtotime($after));

        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $counts = $wpdb->get_results($wpdb->prepare(
            "SELECT comment_approved, COUNT(*) as cnt
             FROM {$wpdb->comments}
             WHERE comment_date_gmt >= %s
             GROUP BY comment_approved",
            $after_date
        ));

        $status_map = [
            '1'     => 'approved',
            '0'     => 'pending',
            'spam'  => 'spam',
            'trash' => 'trash',
        ];

        $stats = ['approved' => 0, 'pending' => 0, 'spam' => 0, 'trash' => 0];
        $total = 0;
        foreach ($counts as $row) {
            $key = $status_map[$row->comment_approved] ?? 'other';
            if (isset($stats[$key])) {
                $stats[$key] = (int) $row->cnt;
            }
            $total += (int) $row->cnt;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $top_commenters = $wpdb->get_results($wpdb->prepare(
            "SELECT comment_author, comment_author_email, COUNT(*) as cnt
             FROM {$wpdb->comments}
             WHERE comment_approved = '1' AND comment_date_gmt >= %s
             GROUP BY comment_author_email
             ORDER BY cnt DESC
             LIMIT 10",
            $after_date
        ));

        $commenters = [];
        foreach ($top_commenters as $c) {
            $commenters[] = [
                'name'  => $c->comment_author,
                'email' => $c->comment_author_email,
                'count' => (int) $c->cnt,
            ];
        }

        return [
            'total'          => $total,
            'approved'       => $stats['approved'],
            'pending'        => $stats['pending'],
            'spam'           => $stats['spam'],
            'top_commenters' => $commenters,
            'period'         => $period,
        ];
    }

    /**
     * Generate a comprehensive site report
     *
     * @param array $input Input parameters
     * @return array|WP_Error
     */
    public static function generate_site_report(array $input)
    {
        $sections = $input['sections'] ?? ['content', 'seo', 'performance', 'security'];
        $raw = [];

        // Content section
        if (in_array('content', $sections, true)) {
            $post_types = get_post_types(['public' => true], 'names');
            $content_stats = [];
            foreach ($post_types as $pt) {
                $counts = wp_count_posts($pt);
                $content_stats[$pt] = [
                    'publish' => (int) ($counts->publish ?? 0),
                    'draft'   => (int) ($counts->draft ?? 0),
                    'pending' => (int) ($counts->pending ?? 0),
                    'future'  => (int) ($counts->future ?? 0),
                    'trash'   => (int) ($counts->trash ?? 0),
                ];
            }

            $recent = get_posts([
                'post_status'    => 'publish',
                'posts_per_page' => 5,
                'orderby'        => 'date',
                'order'          => 'DESC',
            ]);
            $recent_list = [];
            foreach ($recent as $p) {
                $recent_list[] = [
                    'id'    => $p->ID,
                    'title' => $p->post_title,
                    'date'  => $p->post_date,
                ];
            }

            $comment_counts = wp_count_comments();

            $raw['content'] = [
                'post_counts'    => $content_stats,
                'recent_posts'   => $recent_list,
                'comment_counts' => [
                    'total'    => (int) $comment_counts->total_comments,
                    'approved' => (int) $comment_counts->approved,
                    'pending'  => (int) $comment_counts->moderated,
                    'spam'     => (int) $comment_counts->spam,
                ],
            ];
        }

        // Performance section
        if (in_array('performance', $sections, true)) {
            if (is_callable(['ABW_Abilities_Registration', 'execute_get_performance_report'])) {
                $perf = ABW_Abilities_Registration::execute_get_performance_report([]);
                $raw['performance'] = is_wp_error($perf) ? ['error' => $perf->get_error_message()] : $perf;
            } else {
                $raw['performance'] = ['note' => 'Performance report not available.'];
            }
        }

        // Security section
        if (in_array('security', $sections, true)) {
            if (is_callable(['ABW_Security_Tools', 'get_security_report'])) {
                $sec = ABW_Security_Tools::get_security_report([]);
                $raw['security'] = is_wp_error($sec) ? ['error' => $sec->get_error_message()] : $sec;
            } else {
                $raw['security'] = ['note' => 'Security report not available.'];
            }
        }

        // SEO section (basic on-site checks)
        if (in_array('seo', $sections, true)) {
            $raw['seo'] = [
                'site_title'   => get_bloginfo('name'),
                'tagline'      => get_bloginfo('description'),
                'permalink'    => get_option('permalink_structure') ?: 'plain',
                'search_visibility' => get_option('blog_public') ? 'visible' : 'discouraged',
            ];
        }

        // Summarise with AI
        $prompt = sprintf(
            "Summarise the following WordPress site report data into a clear, readable report with sections and key findings.\n\n%s",
            wp_json_encode($raw, JSON_PRETTY_PRINT)
        );

        $messages = [
            [
                'role'    => 'system',
                'content' => 'You are a WordPress site analyst. Produce a concise, well-structured site report highlighting key findings, potential issues, and recommendations.',
            ],
            [
                'role'    => 'user',
                'content' => $prompt,
            ],
        ];

        $ai_response = ABW_AI_Router::chat($messages);
        $report_text = is_wp_error($ai_response) ? 'AI summary unavailable.' : ($ai_response['content'] ?? '');

        return [
            'report'       => $report_text,
            'sections'     => $raw,
            'generated_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    /**
     * Generate a concise daily copilot brief across content, operations, and risk.
     *
     * @param array $input Input parameters.
     * @return array|WP_Error
     */
    public static function get_daily_brief(array $input)
    {
        $days_ahead = max(1, (int) ($input['days_ahead'] ?? 7));
        $raw        = [
            'post_stats'       => ABW_Abilities_Registration::execute_get_post_stats([]),
            'recent_activity'  => ABW_Abilities_Registration::execute_get_recent_activity(['per_page' => 8]),
            'popular_content'  => ABW_Abilities_Registration::execute_get_popular_content(['per_page' => 5]),
            'content_calendar' => self::get_content_calendar(['days_ahead' => $days_ahead]),
        ];

        if (is_callable(['ABW_Abilities_Registration', 'execute_get_performance_report'])) {
            $perf = ABW_Abilities_Registration::execute_get_performance_report([]);
            $raw['performance'] = is_wp_error($perf) ? ['error' => $perf->get_error_message()] : $perf;
        }

        if (is_callable(['ABW_Security_Tools', 'get_security_report'])) {
            $security = ABW_Security_Tools::get_security_report([]);
            $raw['security'] = is_wp_error($security) ? ['error' => $security->get_error_message()] : $security;
        }

        $messages = [
            [
                'role'    => 'system',
                'content' => 'You are a WordPress operations copilot. Produce a concise daily brief with sections for priorities, risks, opportunities, and recommended next steps.',
            ],
            [
                'role'    => 'user',
                'content' => sprintf(
                    "Turn this WordPress site snapshot into a practical daily brief. Keep it concise, action-oriented, and grounded in the provided data.\n\n%s",
                    wp_json_encode($raw, JSON_PRETTY_PRINT)
                ),
            ],
        ];

        $response = ABW_AI_Router::chat($messages);
        $brief    = is_wp_error($response) ? __('AI summary unavailable.', 'abw-ai') : trim((string) ($response['content'] ?? ''));

        return [
            'brief'        => $brief,
            'snapshot'     => $raw,
            'generated_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    /**
     * Identify high-value site opportunities using existing analytics and health data.
     *
     * @param array $input Input parameters.
     * @return array|WP_Error
     */
    public static function get_site_opportunities(array $input)
    {
        $focus = $input['focus'] ?? 'all';
        $raw   = [
            'post_stats'       => ABW_Abilities_Registration::execute_get_post_stats([]),
            'recent_activity'  => ABW_Abilities_Registration::execute_get_recent_activity(['per_page' => 10]),
            'popular_content'  => ABW_Abilities_Registration::execute_get_popular_content(['per_page' => 8]),
            'publishing_stats' => self::get_publishing_stats(['period' => 'month']),
            'comment_stats'    => self::get_comment_stats(['period' => 'month']),
            'content_calendar' => self::get_content_calendar(['days_ahead' => 30]),
        ];

        if (is_callable(['ABW_Abilities_Registration', 'execute_get_performance_report'])) {
            $perf = ABW_Abilities_Registration::execute_get_performance_report([]);
            $raw['performance'] = is_wp_error($perf) ? ['error' => $perf->get_error_message()] : $perf;
        }

        if (is_callable(['ABW_Security_Tools', 'get_security_report'])) {
            $security = ABW_Security_Tools::get_security_report([]);
            $raw['security'] = is_wp_error($security) ? ['error' => $security->get_error_message()] : $security;
        }

        $messages = [
            [
                'role'    => 'system',
                'content' => 'You are a WordPress growth and operations strategist. Return valid JSON only with an `opportunities` array and optional `summary`. Each opportunity should include title, category, impact, effort, why, and next_step.',
            ],
            [
                'role'    => 'user',
                'content' => sprintf(
                    "Analyze this WordPress site snapshot and identify 3-6 high-value opportunities. Focus area: %s. Use the supplied data only.\n\n%s",
                    $focus,
                    wp_json_encode($raw, JSON_PRETTY_PRINT)
                ),
            ],
        ];

        $response = ABW_AI_Router::chat($messages);
        if (is_wp_error($response)) {
            return $response;
        }

        $decoded = self::maybe_decode_json_payload((string) ($response['content'] ?? ''));

        return [
            'focus'         => $focus,
            'summary'       => $decoded['summary'] ?? '',
            'opportunities' => is_array($decoded['opportunities'] ?? null) ? $decoded['opportunities'] : [],
            'raw_response'  => (string) ($response['content'] ?? ''),
            'snapshot'      => $raw,
        ];
    }

    /**
     * Analyze content for SEO score
     *
     * @param array $input Input parameters
     * @return array|WP_Error
     */
    public static function analyze_seo_score(array $input)
    {
        $content = $input['content'] ?? '';
        $title = $input['title'] ?? '';
        $focus_keyword = $input['focus_keyword'] ?? '';
        $url = $input['url'] ?? '';

        if (empty($content)) {
            return new WP_Error('missing_content', __('Please provide content to analyze.', 'abw-ai'));
        }

        $prompt = sprintf(
            "Perform a comprehensive SEO analysis of the following content.\n\n%s%s%sContent:\n%s\n\nAnalyze and provide:\n1. Overall SEO score (0-100)\n2. Grade (A+, A, B, C, D, F)\n3. Issues found (array of strings)\n4. Suggestions for improvement (array of strings)\n5. Keyword density (percentage if focus keyword provided)\n6. Readability score (0-100)\n\nFormat as JSON with fields: score, grade, issues, suggestions, keyword_density, readability_score.",
            $title ? "Title: $title\n" : '',
            $focus_keyword ? "Focus keyword: $focus_keyword\n" : '',
            $url ? "URL: $url\n" : '',
            substr(wp_strip_all_tags($content), 0, 3000)
        );

        $messages = [
            [
                'role'    => 'system',
                'content' => 'You are an SEO analysis expert. Provide thorough, actionable SEO audits with specific scores and recommendations. Output valid JSON only.',
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

        $analysis = self::maybe_decode_json_payload($response['content']);

        if (! is_array($analysis)) {
            return [
                'score'             => 0,
                'grade'             => 'N/A',
                'issues'            => [],
                'suggestions'       => [],
                'keyword_density'   => 0,
                'readability_score' => 0,
                'raw_response'      => $response['content'],
            ];
        }

        return [
            'score'             => $analysis['score'] ?? 0,
            'grade'             => $analysis['grade'] ?? 'N/A',
            'issues'            => $analysis['issues'] ?? [],
            'suggestions'       => $analysis['suggestions'] ?? [],
            'keyword_density'   => $analysis['keyword_density'] ?? 0,
            'readability_score' => $analysis['readability_score'] ?? 0,
        ];
    }

    /**
     * Suggest internal links for a post
     *
     * @param array $input Input parameters
     * @return array|WP_Error
     */
    public static function suggest_internal_links(array $input)
    {
        $post_id = $input['post_id'] ?? 0;

        if (empty($post_id)) {
            return new WP_Error('missing_post_id', __('Please provide a post ID.', 'abw-ai'));
        }

        $post = get_post($post_id);
        if (! $post) {
            return new WP_Error('invalid_post', __('Post not found.', 'abw-ai'));
        }

        $related_posts = get_posts([
            'post_type'      => $post->post_type,
            'post_status'    => 'publish',
            'posts_per_page' => 20,
            'exclude'        => [$post_id],
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);

        if (empty($related_posts)) {
            return [
                'suggestions' => [],
            ];
        }

        $posts_context = [];
        foreach ($related_posts as $rp) {
            $posts_context[] = [
                'id'    => $rp->ID,
                'title' => $rp->post_title,
                'url'   => get_permalink($rp->ID),
                'excerpt' => wp_trim_words(wp_strip_all_tags($rp->post_content), 30),
            ];
        }

        $prompt = sprintf(
            "Analyze the following post content and suggest where internal links to other posts should be added.\n\nCurrent post content:\n%s\n\nAvailable posts to link to:\n%s\n\nFor each suggestion provide:\n- anchor_text: the text in the current post to link\n- target_post_id: the ID of the post to link to\n- target_title: the title of the target post\n- target_url: the URL of the target post\n- context: brief explanation of why this link is relevant\n\nFormat as JSON array of suggestions.",
            substr(wp_strip_all_tags($post->post_content), 0, 3000),
            wp_json_encode($posts_context)
        );

        $messages = [
            [
                'role'    => 'system',
                'content' => 'You are an SEO and internal linking expert. Suggest relevant, natural internal links that improve site structure and user navigation. Output valid JSON only.',
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

        $suggestions = self::maybe_decode_json_payload($response['content']);

        if (! is_array($suggestions)) {
            return [
                'suggestions'  => [],
                'raw_response' => $response['content'],
            ];
        }

        return [
            'suggestions' => is_array($suggestions) ? $suggestions : [],
        ];
    }

    /**
     * Generate an SEO-optimized URL slug
     *
     * @param array $input Input parameters
     * @return array|WP_Error
     */
    public static function generate_slug(array $input)
    {
        $title = $input['title'] ?? '';
        $focus_keyword = $input['focus_keyword'] ?? '';

        if (empty($title)) {
            return new WP_Error('missing_title', __('Please provide a title.', 'abw-ai'));
        }

        $prompt = sprintf(
            "Generate an SEO-optimized URL slug for the following title.\n\nTitle: %s\n%s\nRules:\n- Use lowercase letters, numbers, and hyphens only\n- Keep it concise (3-6 words ideal)\n- Include the focus keyword if provided\n- Remove stop words when possible\n\nProvide:\n1. Best slug option\n2. Three alternative options\n\nFormat as JSON with 'slug' (string) and 'alternatives' (array of 3 strings).",
            $title,
            $focus_keyword ? "Focus keyword: $focus_keyword\n" : ''
        );

        $messages = [
            [
                'role'    => 'system',
                'content' => 'You are an SEO expert specializing in URL optimization. Generate clean, keyword-rich slugs. Output valid JSON only.',
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

        $result = self::maybe_decode_json_payload($response['content']);

        if (! is_array($result)) {
            $fallback = sanitize_title($title);
            return [
                'slug'         => $fallback,
                'alternatives' => [],
            ];
        }

        $slug = sanitize_title($result['slug'] ?? $title);
        $alternatives = [];
        if (! empty($result['alternatives']) && is_array($result['alternatives'])) {
            foreach ($result['alternatives'] as $alt) {
                $alternatives[] = sanitize_title($alt);
            }
        }

        return [
            'slug'         => $slug,
            'alternatives' => $alternatives,
        ];
    }

    /**
     * Check for broken links in a post or URL
     *
     * @param array $input Input parameters
     * @return array|WP_Error
     */
    public static function check_broken_links(array $input)
    {
        $post_id = $input['post_id'] ?? 0;
        $url = $input['url'] ?? '';

        if (empty($post_id) && empty($url)) {
            return new WP_Error('missing_input', __('Please provide a post ID or URL.', 'abw-ai'));
        }

        $content = '';
        if (! empty($post_id)) {
            $post = get_post($post_id);
            if (! $post) {
                return new WP_Error('invalid_post', __('Post not found.', 'abw-ai'));
            }
            $content = $post->post_content;
        } elseif (! empty($url)) {
            $page_response = wp_remote_get($url, ['timeout' => 10]);
            if (is_wp_error($page_response)) {
                return new WP_Error('fetch_failed', __('Could not fetch the URL.', 'abw-ai'));
            }
            $content = wp_remote_retrieve_body($page_response);
        }

        $links = [];
        if (preg_match_all('/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $href = $match[1];
                if (strpos($href, '#') === 0 || strpos($href, 'mailto:') === 0 || strpos($href, 'tel:') === 0) {
                    continue;
                }
                $links[] = [
                    'url'     => $href,
                    'context' => wp_strip_all_tags($match[2]),
                ];
            }
        }

        if (empty($links)) {
            return [
                'total_links'  => 0,
                'broken_links' => [],
            ];
        }

        $broken = [];
        foreach ($links as $link) {
            $check = wp_remote_head($link['url'], [
                'timeout'     => 5,
                'redirection' => 3,
                'sslverify'   => false,
            ]);

            if (is_wp_error($check)) {
                $broken[] = [
                    'url'         => $link['url'],
                    'status_code' => 0,
                    'context'     => $link['context'],
                ];
                continue;
            }

            $status = wp_remote_retrieve_response_code($check);
            if ($status >= 400) {
                $broken[] = [
                    'url'         => $link['url'],
                    'status_code' => $status,
                    'context'     => $link['context'],
                ];
            }
        }

        return [
            'total_links'  => count($links),
            'broken_links' => $broken,
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
        $text = wp_strip_all_tags($content);
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
            [
                'name'        => 'generate_product_description',
                'description' => 'Generate a WooCommerce product description',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['product_name'],
                    'properties' => [
                        'product_name' => ['type' => 'string', 'description' => 'Name of the product'],
                        'category'     => ['type' => 'string', 'description' => 'Product category'],
                        'attributes'   => ['type' => 'object', 'description' => 'Product attributes as key-value pairs'],
                        'price'        => ['type' => 'string', 'description' => 'Product price'],
                        'tone'         => ['type' => 'string', 'enum' => ['persuasive', 'professional', 'casual', 'luxury', 'friendly'], 'default' => 'persuasive'],
                    ],
                ],
            ],
            [
                'name'        => 'rewrite_for_tone',
                'description' => 'Rewrite content in a specified tone',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['content', 'tone'],
                    'properties' => [
                        'content' => ['type' => 'string', 'description' => 'Content to rewrite'],
                        'tone'    => ['type' => 'string', 'enum' => ['professional', 'casual', 'persuasive', 'humorous', 'academic', 'friendly', 'authoritative'], 'description' => 'Target tone'],
                    ],
                ],
            ],
            [
                'name'        => 'generate_outline',
                'description' => 'Generate a structured content outline',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['topic'],
                    'properties' => [
                        'topic'         => ['type' => 'string', 'description' => 'Topic for the outline'],
                        'depth'         => ['type' => 'string', 'enum' => ['basic', 'detailed'], 'default' => 'detailed'],
                        'target_length' => ['type' => 'string', 'description' => 'Target content length (e.g. 1000 words)'],
                    ],
                ],
            ],
            [
                'name'        => 'expand_from_outline',
                'description' => 'Expand an outline into full content',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['outline'],
                    'properties' => [
                        'outline' => ['type' => 'string', 'description' => 'The outline text to expand'],
                        'style'   => ['type' => 'string', 'description' => 'Writing style'],
                        'length'  => ['type' => 'string', 'description' => 'Target length (short, medium, long)'],
                    ],
                ],
            ],
            [
                'name'        => 'generate_excerpt_ai',
                'description' => 'Generate a compelling post excerpt using AI',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['content'],
                    'properties' => [
                        'content'    => ['type' => 'string', 'description' => 'Content to generate excerpt from'],
                        'max_length' => ['type' => 'integer', 'description' => 'Maximum excerpt length in characters', 'default' => 155],
                    ],
                ],
            ],
            [
                'name'        => 'generate_table_of_contents',
                'description' => 'Generate a table of contents from HTML content',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['content'],
                    'properties' => [
                        'content' => ['type' => 'string', 'description' => 'HTML content to extract headings from'],
                    ],
                ],
            ],
            [
                'name'        => 'analyze_seo_score',
                'description' => 'Analyze content for SEO score and recommendations',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['content'],
                    'properties' => [
                        'content'       => ['type' => 'string', 'description' => 'Content to analyze'],
                        'title'         => ['type' => 'string', 'description' => 'Page/post title'],
                        'focus_keyword' => ['type' => 'string', 'description' => 'Target keyword'],
                        'url'           => ['type' => 'string', 'description' => 'Page URL'],
                    ],
                ],
            ],
            [
                'name'        => 'suggest_internal_links',
                'description' => 'Suggest internal links for a post',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['post_id'],
                    'properties' => [
                        'post_id' => ['type' => 'integer', 'description' => 'WordPress post ID'],
                    ],
                ],
            ],
            [
                'name'        => 'generate_slug',
                'description' => 'Generate an SEO-optimized URL slug',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['title'],
                    'properties' => [
                        'title'         => ['type' => 'string', 'description' => 'Post/page title'],
                        'focus_keyword' => ['type' => 'string', 'description' => 'Focus keyword to include'],
                    ],
                ],
            ],
            [
                'name'        => 'check_broken_links',
                'description' => 'Check for broken links in a post or URL',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'post_id' => ['type' => 'integer', 'description' => 'WordPress post ID to check'],
                        'url'     => ['type' => 'string', 'description' => 'URL to check for broken links'],
                    ],
                ],
            ],
            // i18n Tools
            [
                'name'        => 'detect_and_translate_post',
                'description' => 'Detect the source language of a post and translate its title, content, and excerpt to a target language',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['post_id', 'target_language'],
                    'properties' => [
                        'post_id'         => ['type' => 'integer', 'description' => 'WordPress post ID'],
                        'target_language' => ['type' => 'string', 'description' => 'Target language (e.g. Spanish, French, Japanese)'],
                    ],
                ],
            ],
            [
                'name'        => 'bulk_translate_posts',
                'description' => 'Translate multiple posts to a target language, optionally creating new draft posts with the translations',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['post_ids', 'target_language'],
                    'properties' => [
                        'post_ids'        => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Array of post IDs to translate'],
                        'target_language' => ['type' => 'string', 'description' => 'Target language'],
                        'create_new'      => ['type' => 'boolean', 'description' => 'Create new draft posts with translated content', 'default' => false],
                    ],
                ],
            ],
            [
                'name'        => 'manage_translations',
                'description' => 'Check translation status or trigger sync for a post via WPML or Polylang',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['post_id'],
                    'properties' => [
                        'action'  => ['type' => 'string', 'enum' => ['status', 'sync'], 'description' => 'Action to perform', 'default' => 'status'],
                        'post_id' => ['type' => 'integer', 'description' => 'WordPress post ID'],
                    ],
                ],
            ],
            // Analytics & Reporting Tools
            [
                'name'        => 'get_content_calendar',
                'description' => 'Get a content calendar showing all scheduled (future) posts',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'days_ahead' => ['type' => 'integer', 'description' => 'Number of days ahead to look', 'default' => 30],
                    ],
                ],
            ],
            [
                'name'        => 'get_publishing_stats',
                'description' => 'Get publishing statistics grouped by date, author, and post type for a given period',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'period' => ['type' => 'string', 'enum' => ['week', 'month', 'year'], 'description' => 'Time period', 'default' => 'month'],
                    ],
                ],
            ],
            [
                'name'        => 'get_comment_stats',
                'description' => 'Get comment statistics including counts by status and top commenters',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'period' => ['type' => 'string', 'enum' => ['week', 'month', 'year'], 'description' => 'Time period', 'default' => 'month'],
                    ],
                ],
            ],
            [
                'name'        => 'generate_site_report',
                'description' => 'Generate a comprehensive site report covering content, SEO, performance, and security with an AI summary',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'sections' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Sections to include: content, seo, performance, security. Defaults to all.'],
                    ],
                ],
            ],
            [
                'name'        => 'get_daily_brief',
                'description' => 'Generate a concise daily WordPress copilot brief with priorities, risks, and next steps',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'days_ahead' => ['type' => 'integer', 'description' => 'How many days ahead to inspect the content calendar', 'default' => 7],
                    ],
                ],
            ],
            [
                'name'        => 'get_site_opportunities',
                'description' => 'Identify the highest-value site opportunities using current analytics, performance, and security data',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'focus' => ['type' => 'string', 'description' => 'Optional focus area such as content, seo, performance, security, engagement, or all', 'default' => 'all'],
                    ],
                ],
            ],
        ];
    }
}
