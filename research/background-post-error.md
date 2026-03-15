# Background Post Creation Error

## Summary

The failure is not happening during `create_post` itself.

The request succeeds through the tool phase, but the workflow still makes an additional AI call after the tool finishes. When that final AI call fails, the whole background job is marked as failed unless a fallback response is generated in time.

## What The Logs Show

For the earlier `war in 2026` run:

1. The model first chose `generate_post_content`.
2. Because that tool is marked long-running, the chat queued a background job.
3. The background job replayed the chat and asked the AI again.
4. The AI then called `generate_post_content`.
5. The AI then called `create_post`.
6. `create_post` succeeded with `{"id":253,"success":true}`.
7. After that, the system made one more AI call to produce the final assistant reply.
8. That last AI call failed with a custom provider server error.

So the user-visible error is caused by the follow-up summarization turn, not by WordPress failing to create the post.

## Why This Happens

Two design choices combine here:

1. Background jobs replay the chat from the saved `messages` and `tools` instead of continuing from the exact already-decided tool call.
2. After tool execution, the agent loop asks the AI to continue and produce a final answer.

That means a "create post" request can involve several model calls:

- initial planning call
- content-generation call
- create-post call
- final summarization call

If the custom provider is unstable, the last call can fail even after the post has already been created.

## Important Observation

`queue_background_job()` stores the original conversation and re-runs the agent later. It does not persist the exact first tool-call decision as the job payload. Because of that, the background worker must ask the model again and may take a slightly different path on replay.

Relevant code:

- `includes/class-chat-interface.php`
- `includes/class-background-jobs.php`

## Current Status

The code now has a fallback that can return a deterministic success message from successful tool results, for example after `create_post` succeeds.

That reduces the bad UX, but it does not remove the deeper cause:

- the workflow still depends on an extra AI turn after the tool succeeds
- the background worker still replays the agent from saved messages instead of resuming from a fixed tool plan

## Likely Root Cause

The main root cause is workflow design, not the `create_post` tool:

- the job succeeds at the tool layer
- the provider fails at the final AI narration layer

## Better Long-Term Fixes

1. For action tools like `create_post`, skip the final AI summarization turn and return a deterministic success response immediately.
2. When queueing a background job, persist the chosen tool call and arguments so the worker can continue from that exact state instead of asking the model again.
3. Reserve AI follow-up turns for cases that truly need more reasoning, not for simple success narration.
4. Add explicit job logging for "tool succeeded, final narration failed" so the failure mode is obvious in logs.
