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

    /** @var string Option key for storing recent workflow runs */
    const RUNS_OPTION_KEY = 'abw_ai_workflow_runs';

    /** @var string WP-Cron hook name */
    const CRON_HOOK = 'abw_run_workflow';

    /**
     * Initialize workflow hooks.
     */
    public static function init()
    {
        add_action('init', [__CLASS__, 'register_hooks']);
    }

    /**
     * Register cron and event hooks for workflow execution.
     */
    public static function register_hooks()
    {
        add_action(self::CRON_HOOK, [__CLASS__, 'execute_scheduled_workflow']);
        add_action('transition_post_status', [__CLASS__, 'handle_post_publish'], 10, 3);
        add_action('comment_post', [__CLASS__, 'handle_comment_event'], 10, 3);
        add_action('woocommerce_order_status_changed', [__CLASS__, 'handle_order_event'], 10, 4);
    }

    /**
     * Create a new automated AI workflow.
     *
     * @param array $input Workflow configuration.
     * @return array|WP_Error
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
            'created_by'   => get_current_user_id(),
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
     * List all configured AI workflows.
     *
     * @param array $input Unused, present for tool interface consistency.
     * @return array
     */
    public static function list_workflows(array $input = [])
    {
        unset($input);

        $workflows = get_option(self::OPTION_KEY, []);
        $runs      = get_option(self::RUNS_OPTION_KEY, []);
        $list      = [];

        foreach ($workflows as $workflow) {
            $latest_run = $runs[$workflow['id']][0] ?? null;
            $list[] = [
                'id'           => $workflow['id'],
                'name'         => $workflow['name'],
                'trigger_type' => $workflow['trigger_type'],
                'schedule'     => $workflow['schedule'],
                'enabled'      => $workflow['enabled'],
                'created_by'   => (int) ($workflow['created_by'] ?? 0),
                'last_run'     => $workflow['last_run'],
                'last_status'  => $latest_run['status'] ?? 'never_run',
                'steps'        => count($workflow['steps']),
            ];
        }

        return $list;
    }

    /**
     * Enable or disable a workflow.
     *
     * @param array $input Workflow update payload.
     * @return array|WP_Error
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
     * Manually run a workflow by ID.
     *
     * @param array $input Workflow execution payload.
     * @return array|WP_Error
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

        return self::execute_workflow(
            $workflow,
            is_array($input['context'] ?? null) ? $input['context'] : [],
            [
                'source'         => 'manual',
                'approved_steps' => array_map('intval', (array) ($input['approved_steps'] ?? [])),
            ]
        );
    }

    /**
     * Execute a scheduled workflow via WP-Cron.
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

        self::execute_workflow(
            $workflow,
            [
                'schedule' => $workflow['schedule'],
                'source'   => 'scheduled',
            ],
            [
                'source' => 'scheduled',
            ]
        );
    }

    /**
     * Execute a workflow with runtime context and logging.
     *
     * @param array $workflow Workflow data.
     * @param array $context  Trigger context.
     * @param array $options  Execution options.
     * @return array
     */
    public static function execute_workflow(array $workflow, array $context = [], array $options = []): array
    {
        $previous_user_id = get_current_user_id();
        $owner_id         = (int) ($workflow['created_by'] ?? 0);
        if ($owner_id > 0) {
            wp_set_current_user($owner_id);
        }

        $results = self::execute_workflow_steps(
            $workflow['steps'],
            $context,
            [
                'approved_steps' => array_map('intval', (array) ($options['approved_steps'] ?? [])),
                'workflow_id'    => $workflow['id'],
                'user_id'        => $owner_id,
            ]
        );

        if ($previous_user_id !== $owner_id) {
            wp_set_current_user($previous_user_id);
        }

        $workflows = get_option(self::OPTION_KEY, []);
        if (isset($workflows[$workflow['id']])) {
            $workflows[$workflow['id']]['last_run'] = current_time('mysql');
            update_option(self::OPTION_KEY, $workflows, false);
        }

        $status = self::derive_run_status($results);
        self::log_workflow_run($workflow['id'], [
            'workflow_name' => $workflow['name'],
            'status'        => $status,
            'trigger_type'  => $workflow['trigger_type'],
            'context'       => $context,
            'results'       => $results,
            'ran_at'        => current_time('mysql'),
        ]);

        return [
            'workflow_id'   => $workflow['id'],
            'workflow_name' => $workflow['name'],
            'status'        => $status,
            'results'       => $results,
            'context'       => $context,
        ];
    }

    /**
     * Execute an array of workflow steps sequentially.
     *
     * @param array $steps   Array of step objects with 'tool' and 'arguments'.
     * @param array $context Trigger/runtime context.
     * @param array $options Execution options.
     * @return array
     */
    public static function execute_workflow_steps(array $steps, array $context = [], array $options = []): array
    {
        $results        = [];
        $approved_steps = array_map('intval', (array) ($options['approved_steps'] ?? []));
        $runtime        = [
            'trigger' => $context,
            'steps'   => [],
        ];

        foreach ($steps as $index => $step) {
            $step_number = $index + 1;
            $tool_name   = $step['tool'] ?? '';
            $arguments   = self::resolve_runtime_values($step['arguments'] ?? [], $runtime);

            if (empty($tool_name)) {
                $results[] = [
                    'step'   => $step_number,
                    'name'   => $step['name'] ?? '',
                    'tool'   => $tool_name,
                    'status' => 'skipped',
                    'error'  => 'No tool specified.',
                ];
                continue;
            }

            $needs_approval = ! empty($step['requires_approval']);
            $is_approved    = in_array($step_number, $approved_steps, true);

            if ($needs_approval && ! $is_approved) {
                $results[] = [
                    'step'    => $step_number,
                    'name'    => $step['name'] ?? '',
                    'tool'    => $tool_name,
                    'status'  => 'pending_approval',
                    'preview' => [
                        'tool'      => $tool_name,
                        'arguments' => $arguments,
                    ],
                ];
                break;
            }

            $result = ABW_AI_Router::execute_tool(
                $tool_name,
                is_array($arguments) ? $arguments : [],
                [
                    'confirmed' => $is_approved,
                    'source'    => 'workflow',
                    'user_id'   => (int) ($options['user_id'] ?? 0),
                ]
            );

            if (is_wp_error($result)) {
                $results[] = [
                    'step'   => $step_number,
                    'name'   => $step['name'] ?? '',
                    'tool'   => $tool_name,
                    'status' => 'error',
                    'error'  => $result->get_error_message(),
                ];
                break;
            }

            if (is_array($result) && ! empty($result['__abw_requires_confirmation'])) {
                $results[] = [
                    'step'          => $step_number,
                    'name'          => $step['name'] ?? '',
                    'tool'          => $tool_name,
                    'status'        => 'pending_approval',
                    'confirmation'  => $result['__abw_requires_confirmation'],
                ];
                break;
            }

            $results[] = [
                'step'   => $step_number,
                'name'   => $step['name'] ?? '',
                'tool'   => $tool_name,
                'status' => 'success',
                'result' => $result,
            ];

            $runtime['steps'][$step_number] = [
                'name'      => $step['name'] ?? '',
                'tool'      => $tool_name,
                'arguments' => $arguments,
                'result'    => $result,
            ];
        }

        return $results;
    }

    /**
     * Get tool schemas for workflow management tools.
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
                                    'name'              => ['type' => 'string', 'description' => 'Optional human-readable step label'],
                                    'tool'              => ['type' => 'string', 'description' => 'Tool name to execute'],
                                    'arguments'         => ['type' => 'object', 'description' => 'Arguments to pass to the tool. Supports {{trigger.key}} and {{steps.1.result.key}} templates.'],
                                    'requires_approval' => ['type' => 'boolean', 'description' => 'Whether this step should pause until manually approved'],
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
                        'id'             => ['type' => 'string', 'description' => 'Workflow ID to execute'],
                        'approved_steps' => ['type' => 'array', 'description' => 'Step numbers that have been explicitly approved', 'items' => ['type' => 'integer']],
                        'context'        => ['type' => 'object', 'description' => 'Optional runtime context made available as {{trigger.*}}'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Activate the trigger for a workflow.
     *
     * @param array $workflow Workflow data.
     */
    private static function activate_trigger(array $workflow)
    {
        if ('scheduled' === ($workflow['trigger_type'] ?? '')) {
            if (! wp_next_scheduled(self::CRON_HOOK, [$workflow['id']])) {
                wp_schedule_event(time(), $workflow['schedule'], self::CRON_HOOK, [$workflow['id']]);
            }
        }
    }

    /**
     * Deactivate the trigger for a workflow.
     *
     * @param array $workflow Workflow data.
     */
    private static function deactivate_trigger(array $workflow)
    {
        if ('scheduled' === ($workflow['trigger_type'] ?? '')) {
            wp_clear_scheduled_hook(self::CRON_HOOK, [$workflow['id']]);
        }
    }

    /**
     * Handle post publish events for on_publish workflows.
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

        self::run_triggered_workflows(
            'on_publish',
            [
                'post_id'     => (int) $post->ID,
                'post_title'  => $post->post_title,
                'post_type'   => $post->post_type,
                'new_status'  => $new_status,
                'old_status'  => $old_status,
            ]
        );
    }

    /**
     * Handle comment creation events for on_comment workflows.
     *
     * @param int   $comment_id       Comment ID.
     * @param int   $comment_approved Whether comment is approved.
     * @param array $commentdata      Raw comment data.
     */
    public static function handle_comment_event($comment_id, $comment_approved, $commentdata)
    {
        $comment = get_comment($comment_id);

        self::run_triggered_workflows(
            'on_comment',
            [
                'comment_id'       => (int) $comment_id,
                'post_id'          => (int) ($commentdata['comment_post_ID'] ?? 0),
                'comment_approved' => $comment_approved,
                'author'           => $comment ? $comment->comment_author : '',
                'content'          => $comment ? $comment->comment_content : '',
            ]
        );
    }

    /**
     * Handle WooCommerce order status change events for on_order workflows.
     *
     * @param int        $order_id    Order ID.
     * @param string     $from_status Previous status.
     * @param string     $to_status   New status.
     * @param WC_Order|mixed $order   Order object.
     */
    public static function handle_order_event($order_id, $from_status, $to_status, $order)
    {
        self::run_triggered_workflows(
            'on_order',
            [
                'order_id'    => (int) $order_id,
                'from_status' => $from_status,
                'to_status'   => $to_status,
                'total'       => is_object($order) && method_exists($order, 'get_total') ? $order->get_total() : null,
            ]
        );
    }

    /**
     * Run all enabled workflows for a trigger type.
     *
     * @param string $trigger_type Trigger type.
     * @param array  $context      Trigger context.
     */
    private static function run_triggered_workflows(string $trigger_type, array $context): void
    {
        $workflows = get_option(self::OPTION_KEY, []);

        foreach ($workflows as $workflow) {
            if ($trigger_type !== ($workflow['trigger_type'] ?? '') || empty($workflow['enabled'])) {
                continue;
            }

            self::execute_workflow(
                $workflow,
                $context,
                [
                    'source' => $trigger_type,
                ]
            );
        }
    }

    /**
     * Sanitize workflow steps.
     *
     * @param array $steps Raw step data.
     * @return array
     */
    private static function sanitize_steps(array $steps): array
    {
        $sanitized = [];

        foreach ($steps as $step) {
            if (empty($step['tool'])) {
                continue;
            }

            $sanitized[] = [
                'name'              => sanitize_text_field($step['name'] ?? ''),
                'tool'              => sanitize_text_field($step['tool']),
                'arguments'         => isset($step['arguments']) && is_array($step['arguments'])
                    ? $step['arguments']
                    : [],
                'requires_approval' => ! empty($step['requires_approval']),
            ];
        }

        return $sanitized;
    }

    /**
     * Resolve runtime templates inside a workflow value.
     *
     * @param mixed $value   Raw step value.
     * @param array $runtime Runtime context.
     * @return mixed
     */
    private static function resolve_runtime_values($value, array $runtime)
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::resolve_runtime_values($item, $runtime);
            }

            return $value;
        }

        if (! is_string($value)) {
            return $value;
        }

        if (! preg_match_all('/\{\{\s*([^}]+)\s*\}\}/', $value, $matches, PREG_SET_ORDER)) {
            return $value;
        }

        $resolved = $value;
        foreach ($matches as $match) {
            $path         = trim($match[1]);
            $replacement  = self::get_runtime_value($runtime, $path);

            if (null === $replacement) {
                continue;
            }

            if (is_array($replacement)) {
                $replacement = wp_json_encode($replacement);
            } elseif (is_bool($replacement)) {
                $replacement = $replacement ? 'true' : 'false';
            }

            $resolved = str_replace($match[0], (string) $replacement, $resolved);
        }

        return $resolved;
    }

    /**
     * Resolve a dotted path from runtime context.
     *
     * @param array  $runtime Runtime data.
     * @param string $path    Dotted path.
     * @return mixed|null
     */
    private static function get_runtime_value(array $runtime, string $path)
    {
        $segments = explode('.', $path);
        $cursor   = $runtime;

        foreach ($segments as $segment) {
            if (is_array($cursor) && array_key_exists($segment, $cursor)) {
                $cursor = $cursor[$segment];
                continue;
            }

            if (is_array($cursor) && ctype_digit($segment) && array_key_exists((int) $segment, $cursor)) {
                $cursor = $cursor[(int) $segment];
                continue;
            }

            return null;
        }

        return $cursor;
    }

    /**
     * Derive an overall run status from step results.
     *
     * @param array $results Step results.
     * @return string
     */
    private static function derive_run_status(array $results): string
    {
        foreach ($results as $result) {
            if ('error' === ($result['status'] ?? '')) {
                return 'error';
            }
            if ('pending_approval' === ($result['status'] ?? '')) {
                return 'pending_approval';
            }
        }

        return 'success';
    }

    /**
     * Persist recent workflow run logs.
     *
     * @param string $workflow_id Workflow ID.
     * @param array  $run         Run data.
     */
    private static function log_workflow_run(string $workflow_id, array $run): void
    {
        $runs = get_option(self::RUNS_OPTION_KEY, []);
        if (! isset($runs[$workflow_id]) || ! is_array($runs[$workflow_id])) {
            $runs[$workflow_id] = [];
        }

        array_unshift($runs[$workflow_id], $run);
        $runs[$workflow_id] = array_slice($runs[$workflow_id], 0, 20);

        update_option(self::RUNS_OPTION_KEY, $runs, false);
    }
}
