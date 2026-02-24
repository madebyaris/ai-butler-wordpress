<?php

/**
 * AI Workflow Automation
 *
 * Manages automated AI workflows with scheduled and event-based triggers.
 * Workflows are stored in a custom option and executed via WP-Cron.
 *
 * @package ABW_AI
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Class ABW_AI_Workflows
 *
 * Creates, manages, and executes automated AI workflows
 * with support for scheduled, event-based, and manual triggers.
 */
class ABW_AI_Workflows
{
    /** @var string Option key for storing workflows */
    const OPTION_KEY = 'abw_ai_workflows';

    /** @var string WP-Cron hook name */
    const CRON_HOOK = 'abw_run_workflow';

    /**
     * Initialize workflow hooks
     */
    public static function init()
    {
        add_action('init', [__CLASS__, 'register_cron_hooks']);
    }

    /**
     * Register WP-Cron hooks for workflow execution
     */
    public static function register_cron_hooks()
    {
        add_action(self::CRON_HOOK, [__CLASS__, 'execute_scheduled_workflow']);
    }

    /**
     * Create a new automated AI workflow
     *
     * @param array $input {
     *     Workflow configuration.
     *
     *     @type string $name         Workflow name (required).
     *     @type string $trigger_type Trigger type: 'scheduled', 'on_publish', 'on_comment', 'on_order', 'manual'.
     *     @type string $schedule     Cron schedule: 'hourly', 'daily', 'weekly'. Required when trigger_type is 'scheduled'.
     *     @type array  $steps        Array of step objects with 'tool' and 'arguments' keys.
     *     @type bool   $enabled      Whether the workflow is active. Default true.
     * }
     * @return array|WP_Error Created workflow data or error.
     */
    public static function create_workflow(array $input)
    {
        if (empty($input['name'])) {
            return new WP_Error('missing_name', __('Workflow name is required.', 'abw-ai'));
        }

        $valid_triggers = ['scheduled', 'on_publish', 'on_comment', 'on_order', 'manual'];
        $trigger_type   = $input['trigger_type'] ?? 'manual';

        if (! in_array($trigger_type, $valid_triggers, true)) {
            return new WP_Error('invalid_trigger', __('Invalid trigger type.', 'abw-ai'));
        }

        if ('scheduled' === $trigger_type && empty($input['schedule'])) {
            return new WP_Error('missing_schedule', __('Schedule is required for scheduled workflows.', 'abw-ai'));
        }

        $valid_schedules = ['hourly', 'daily', 'weekly'];
        if ('scheduled' === $trigger_type && ! in_array($input['schedule'], $valid_schedules, true)) {
            return new WP_Error('invalid_schedule', __('Invalid schedule. Must be hourly, daily, or weekly.', 'abw-ai'));
        }

        if (empty($input['steps']) || ! is_array($input['steps'])) {
            return new WP_Error('missing_steps', __('At least one workflow step is required.', 'abw-ai'));
        }

        $workflow_id = wp_generate_uuid4();
        $enabled     = $input['enabled'] ?? true;

        $workflow = [
            'id'           => $workflow_id,
            'name'         => sanitize_text_field($input['name']),
            'trigger_type' => $trigger_type,
            'schedule'     => ('scheduled' === $trigger_type) ? $input['schedule'] : null,
            'steps'        => self::sanitize_steps($input['steps']),
            'enabled'      => (bool) $enabled,
            'created_at'   => current_time('mysql'),
            'last_run'     => null,
        ];

        $workflows               = get_option(self::OPTION_KEY, []);
        $workflows[$workflow_id] = $workflow;
        update_option(self::OPTION_KEY, $workflows, false);

        if ($enabled) {
            self::activate_trigger($workflow);
        }

        return $workflow;
    }

    /**
     * List all configured AI workflows
     *
     * @param array $input Unused, present for tool interface consistency.
     * @return array List of workflow summaries.
     */
    public static function list_workflows(array $input = [])
    {
        $workflows = get_option(self::OPTION_KEY, []);
        $list      = [];

        foreach ($workflows as $workflow) {
            $list[] = [
                'id'           => $workflow['id'],
                'name'         => $workflow['name'],
                'trigger_type' => $workflow['trigger_type'],
                'schedule'     => $workflow['schedule'],
                'enabled'      => $workflow['enabled'],
                'last_run'     => $workflow['last_run'],
                'steps'        => count($workflow['steps']),
            ];
        }

        return $list;
    }

    /**
     * Enable or disable a workflow
     *
     * @param array $input {
     *     @type string $id      Workflow ID (required).
     *     @type bool   $enabled Whether to enable or disable.
     * }
     * @return array|WP_Error Updated workflow data or error.
     */
    public static function toggle_workflow(array $input)
    {
        if (empty($input['id'])) {
            return new WP_Error('missing_id', __('Workflow ID is required.', 'abw-ai'));
        }

        if (! isset($input['enabled'])) {
            return new WP_Error('missing_enabled', __('Enabled status is required.', 'abw-ai'));
        }

        $workflows = get_option(self::OPTION_KEY, []);

        if (! isset($workflows[$input['id']])) {
            return new WP_Error('not_found', __('Workflow not found.', 'abw-ai'));
        }

        $workflow = &$workflows[$input['id']];
        $enabled  = (bool) $input['enabled'];

        if ($workflow['enabled'] && ! $enabled) {
            self::deactivate_trigger($workflow);
        } elseif (! $workflow['enabled'] && $enabled) {
            self::activate_trigger($workflow);
        }

        $workflow['enabled'] = $enabled;
        update_option(self::OPTION_KEY, $workflows, false);

        return $workflow;
    }

    /**
     * Manually run a workflow by ID
     *
     * @param array $input {
     *     @type string $id Workflow ID (required).
     * }
     * @return array|WP_Error Execution results or error.
     */
    public static function run_workflow(array $input)
    {
        if (empty($input['id'])) {
            return new WP_Error('missing_id', __('Workflow ID is required.', 'abw-ai'));
        }

        $workflows = get_option(self::OPTION_KEY, []);

        if (! isset($workflows[$input['id']])) {
            return new WP_Error('not_found', __('Workflow not found.', 'abw-ai'));
        }

        $workflow = $workflows[$input['id']];
        $results  = self::execute_workflow_steps($workflow['steps']);

        $workflows[$input['id']]['last_run'] = current_time('mysql');
        update_option(self::OPTION_KEY, $workflows, false);

        return [
            'workflow_id'   => $workflow['id'],
            'workflow_name' => $workflow['name'],
            'results'       => $results,
        ];
    }

    /**
     * Execute a scheduled workflow via WP-Cron
     *
     * @param string $workflow_id The workflow ID to execute.
     */
    public static function execute_scheduled_workflow($workflow_id)
    {
        $workflows = get_option(self::OPTION_KEY, []);

        if (! isset($workflows[$workflow_id])) {
            return;
        }

        $workflow = $workflows[$workflow_id];

        if (! $workflow['enabled']) {
            return;
        }

        self::execute_workflow_steps($workflow['steps']);

        $workflows[$workflow_id]['last_run'] = current_time('mysql');
        update_option(self::OPTION_KEY, $workflows, false);
    }

    /**
     * Execute an array of workflow steps sequentially
     *
     * Each step calls ABW_AI_Router::execute_tool() with the configured
     * tool name and arguments.
     *
     * @param array $steps Array of step objects with 'tool' and 'arguments'.
     * @return array Summary of step results.
     */
    public static function execute_workflow_steps(array $steps): array
    {
        $results = [];

        foreach ($steps as $index => $step) {
            $tool_name = $step['tool'] ?? '';
            $arguments = $step['arguments'] ?? [];

            if (empty($tool_name)) {
                $results[] = [
                    'step'   => $index + 1,
                    'tool'   => $tool_name,
                    'status' => 'skipped',
                    'error'  => 'No tool specified.',
                ];
                continue;
            }

            $result = ABW_AI_Router::execute_tool($tool_name, $arguments);

            if (is_wp_error($result)) {
                $results[] = [
                    'step'   => $index + 1,
                    'tool'   => $tool_name,
                    'status' => 'error',
                    'error'  => $result->get_error_message(),
                ];
            } else {
                $results[] = [
                    'step'   => $index + 1,
                    'tool'   => $tool_name,
                    'status' => 'success',
                    'result' => $result,
                ];
            }
        }

        return $results;
    }

    /**
     * Get tool schemas for workflow management tools
     *
     * @return array
     */
    public static function get_tools_list(): array
    {
        return [
            [
                'name'        => 'create_workflow',
                'description' => 'Create an automated AI workflow with scheduled or event-based triggers',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['name', 'trigger_type', 'steps'],
                    'properties' => [
                        'name'         => ['type' => 'string', 'description' => 'Workflow name'],
                        'trigger_type' => [
                            'type'        => 'string',
                            'description' => 'When the workflow should run',
                            'enum'        => ['scheduled', 'on_publish', 'on_comment', 'on_order', 'manual'],
                        ],
                        'schedule' => [
                            'type'        => 'string',
                            'description' => 'Cron schedule (required for scheduled trigger)',
                            'enum'        => ['hourly', 'daily', 'weekly'],
                        ],
                        'steps' => [
                            'type'        => 'array',
                            'description' => 'Workflow steps to execute in order',
                            'items'       => [
                                'type'       => 'object',
                                'properties' => [
                                    'tool'      => ['type' => 'string', 'description' => 'Tool name to execute'],
                                    'arguments' => ['type' => 'object', 'description' => 'Arguments to pass to the tool'],
                                ],
                            ],
                        ],
                        'enabled' => ['type' => 'boolean', 'description' => 'Whether the workflow is active (default true)'],
                    ],
                ],
            ],
            [
                'name'        => 'list_workflows',
                'description' => 'List all configured AI workflows',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => new \stdClass(),
                ],
            ],
            [
                'name'        => 'toggle_workflow',
                'description' => 'Enable or disable a workflow',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['id', 'enabled'],
                    'properties' => [
                        'id'      => ['type' => 'string', 'description' => 'Workflow ID'],
                        'enabled' => ['type' => 'boolean', 'description' => 'Whether to enable or disable the workflow'],
                    ],
                ],
            ],
            [
                'name'        => 'run_workflow',
                'description' => 'Manually run a workflow by ID',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['id'],
                    'properties' => [
                        'id' => ['type' => 'string', 'description' => 'Workflow ID to execute'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Activate the trigger for a workflow
     *
     * @param array $workflow Workflow data.
     */
    private static function activate_trigger(array $workflow)
    {
        switch ($workflow['trigger_type']) {
            case 'scheduled':
                if (! wp_next_scheduled(self::CRON_HOOK, [$workflow['id']])) {
                    wp_schedule_event(time(), $workflow['schedule'], self::CRON_HOOK, [$workflow['id']]);
                }
                break;

            case 'on_publish':
                add_action('transition_post_status', [__CLASS__, 'handle_post_publish'], 10, 3);
                break;
        }
    }

    /**
     * Deactivate the trigger for a workflow
     *
     * @param array $workflow Workflow data.
     */
    private static function deactivate_trigger(array $workflow)
    {
        switch ($workflow['trigger_type']) {
            case 'scheduled':
                wp_clear_scheduled_hook(self::CRON_HOOK, [$workflow['id']]);
                break;
        }
    }

    /**
     * Handle post publish events for on_publish workflows
     *
     * @param string  $new_status New post status.
     * @param string  $old_status Old post status.
     * @param WP_Post $post       Post object.
     */
    public static function handle_post_publish($new_status, $old_status, $post)
    {
        if ('publish' !== $new_status || 'publish' === $old_status) {
            return;
        }

        $workflows = get_option(self::OPTION_KEY, []);

        foreach ($workflows as $id => $workflow) {
            if ('on_publish' !== $workflow['trigger_type'] || ! $workflow['enabled']) {
                continue;
            }

            self::execute_workflow_steps($workflow['steps']);

            $workflows[$id]['last_run'] = current_time('mysql');
        }

        update_option(self::OPTION_KEY, $workflows, false);
    }

    /**
     * Sanitize workflow steps
     *
     * @param array $steps Raw step data.
     * @return array Sanitized steps.
     */
    private static function sanitize_steps(array $steps): array
    {
        $sanitized = [];

        foreach ($steps as $step) {
            if (empty($step['tool'])) {
                continue;
            }

            $sanitized[] = [
                'tool'      => sanitize_text_field($step['tool']),
                'arguments' => isset($step['arguments']) && is_array($step['arguments'])
                    ? $step['arguments']
                    : [],
            ];
        }

        return $sanitized;
    }
}
