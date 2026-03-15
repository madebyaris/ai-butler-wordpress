<?php

/**
 * Brand Voice Manager
 *
 * Learns and applies brand voice profiles from existing site content.
 * Stores profiles in wp_options for use by the AI router.
 *
 * @package ABW_AI
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Class ABW_Brand_Voice
 *
 * Provides tools for training, retrieving, and applying brand voice profiles.
 */
class ABW_Brand_Voice
{
    /**
     * Option name prefix for stored voice profiles.
     */
    const OPTION_PREFIX = 'abw_brand_voice_';

    /**
     * Analyze existing posts to learn writing style and save as a brand voice profile.
     *
     * @param array $input {
     *     Optional input parameters.
     *
     *     @type int    $sample_count Number of recent posts to analyze. Default 5.
     *     @type string $voice_name   Name for this voice profile. Default 'default'.
     * }
     * @return array|WP_Error The brand voice profile on success, WP_Error on failure.
     */
    public static function train_brand_voice(array $input)
    {
        $sample_count = isset($input['sample_count']) ? absint($input['sample_count']) : 5;
        $voice_name   = sanitize_key($input['voice_name'] ?? 'default');

        if ($sample_count < 1) {
            $sample_count = 5;
        }

        $posts = get_posts([
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => $sample_count,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);

        if (empty($posts)) {
            return new WP_Error(
                'no_posts',
                __('No published posts found to analyze. Publish some content first.', 'abw-ai')
            );
        }

        $samples = [];
        foreach ($posts as $post) {
            $samples[] = sprintf(
                "Title: %s\n\n%s",
                $post->post_title,
                wp_strip_all_tags($post->post_content)
            );
        }

        $combined = implode("\n\n---\n\n", $samples);

        $messages = [
            [
                'role'    => 'system',
                'content' => 'You are an expert writing style analyst. Analyze the provided writing samples and extract a detailed brand voice profile. Respond in structured plain text only, no markdown.',
            ],
            [
                'role'    => 'user',
                'content' => sprintf(
                    "Analyze these %d writing samples and extract a brand voice profile. Identify:\n\n"
                    . "1. **Tone** — overall emotional tone (e.g., friendly, authoritative, playful)\n"
                    . "2. **Vocabulary level** — simple, moderate, advanced, or technical\n"
                    . "3. **Sentence structure** — short/punchy, varied, complex, etc.\n"
                    . "4. **Common phrases or patterns** — recurring expressions, transitions, openers\n"
                    . "5. **Personality traits** — what personality comes through in the writing\n"
                    . "6. **Audience** — who the writing appears to target\n"
                    . "7. **Formatting habits** — use of lists, headers, paragraphs length\n\n"
                    . "Samples:\n\n%s",
                    count($posts),
                    $combined
                ),
            ],
        ];

        $response = ABW_AI_Router::chat($messages);

        if (is_wp_error($response)) {
            return $response;
        }

        $profile = [
            'voice_name'   => $voice_name,
            'analysis'     => $response['content'],
            'sample_count' => count($posts),
            'trained_at'   => current_time('mysql'),
            'post_ids'     => wp_list_pluck($posts, 'ID'),
        ];

        update_option(self::OPTION_PREFIX . $voice_name, $profile, false);

        return $profile;
    }

    /**
     * Retrieve a stored brand voice profile.
     *
     * @param array $input {
     *     Optional input parameters.
     *
     *     @type string $voice_name Voice profile name. Default 'default'.
     * }
     * @return array|WP_Error The stored profile or WP_Error if not found.
     */
    public static function get_brand_voice(array $input)
    {
        $voice_name = sanitize_key($input['voice_name'] ?? 'default');
        $profile    = get_option(self::OPTION_PREFIX . $voice_name, false);

        if (empty($profile)) {
            return new WP_Error(
                'no_profile',
                sprintf(
                    __('No brand voice profile found for "%s". Use train_brand_voice to create one.', 'abw-ai'),
                    $voice_name
                )
            );
        }

        return $profile;
    }

    /**
     * Build an instruction string for the AI to follow a brand voice when generating content.
     *
     * @param string $content    The content or prompt to augment with voice instructions.
     * @param string $voice_name Voice profile name. Default 'default'.
     * @return string Instruction text incorporating the brand voice, or the original content if no profile exists.
     */
    public static function apply_brand_voice(string $content, string $voice_name = 'default'): string
    {
        $voice_name = sanitize_key($voice_name);
        $profile    = get_option(self::OPTION_PREFIX . $voice_name, false);

        if (empty($profile) || empty($profile['analysis'])) {
            return $content;
        }

        return sprintf(
            "BRAND VOICE INSTRUCTIONS — follow this writing style closely:\n\n%s\n\n---\n\nNow, using the brand voice above, handle the following:\n\n%s",
            $profile['analysis'],
            $content
        );
    }

    /**
     * Return tool schemas for brand voice tools.
     *
     * @return array[] Tool definitions compatible with ABW_AI_Tools format.
     */
    public static function get_tools_list(): array
    {
        return [
            [
                'name'        => 'train_brand_voice',
                'description' => 'Analyze existing posts to learn and save the site writing style as a brand voice profile',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'sample_count' => [
                            'type'        => 'integer',
                            'description' => 'Number of recent posts to analyze',
                            'default'     => 5,
                        ],
                        'voice_name'   => [
                            'type'        => 'string',
                            'description' => 'Name for this voice profile',
                            'default'     => 'default',
                        ],
                    ],
                ],
            ],
            [
                'name'        => 'get_brand_voice',
                'description' => 'Retrieve the current brand voice profile',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'voice_name' => [
                            'type'        => 'string',
                            'description' => 'Voice profile name',
                            'default'     => 'default',
                        ],
                    ],
                ],
            ],
        ];
    }
}
