<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shopify actions with approval, and unattended actions (docs/agent-actions.md).
 *
 *  - agent_action_settings: per workspace, who may approve, expiry, the
 *    refund hard ceiling, the 4PX unattended window, discount templates.
 *  - agent_action_policies: per action (optionally per playbook):
 *    always | approval | never + limits. No row = never.
 *  - agent_action_executions: approval queue + audit trail. The unique
 *    idempotency_key makes a re-proposed identical action reuse its row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_action_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            // Minimum role that may approve money / address actions, and other actions.
            $table->string('approver_role_money', 20)->default('admin');
            $table->string('approver_role_other', 20)->default('agent');
            $table->unsignedSmallInteger('approval_expiry_hours')->default(48);
            // Refunds (and cancellations that refund) above this are approval-only, whatever the policy.
            $table->decimal('refund_hard_ceiling', 12, 2)->default(150);
            // 4PX guard: address change / cancel may run unattended only this many minutes after the order.
            $table->unsignedInteger('unattended_order_age_minutes')->default(60);
            $table->boolean('audit_note')->default(true);
            $table->jsonb('discount_templates')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('agent_action_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('action_key', 40);
            $table->foreignId('playbook_id')->nullable()->constrained('agent_playbooks')->cascadeOnDelete();
            // always | approval | never
            $table->string('mode', 16)->default('never');
            $table->jsonb('limits')->nullable();
            // A playbook policy may be LESS restrictive than the workspace one only when an admin sets this.
            $table->boolean('override_workspace')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'action_key']);
        });
        // One workspace-level row and one row per playbook, per action.
        DB::statement('CREATE UNIQUE INDEX agent_action_policies_ws_unique ON agent_action_policies (workspace_id, action_key) WHERE playbook_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX agent_action_policies_pb_unique ON agent_action_policies (workspace_id, action_key, playbook_id) WHERE playbook_id IS NOT NULL');

        Schema::create('agent_action_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('run_id')->nullable()->constrained('agent_runs')->nullOnDelete();
            $table->foreignId('playbook_id')->nullable()->constrained('agent_playbooks')->nullOnDelete();
            // Purpose of the run that proposed it. Anything but live is a hard dry run.
            $table->string('purpose', 20)->default('live');
            $table->string('action_key', 40);
            $table->jsonb('params');
            $table->jsonb('param_errors')->nullable();
            $table->jsonb('params_edited')->nullable();
            $table->string('order_number', 64)->nullable();
            $table->string('order_id')->nullable();
            $table->string('customer_email')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            // proposed | pending_approval | approved | executing | succeeded | failed
            // | rejected | expired | unknown | cancelled
            $table->string('status', 20)->default('proposed');
            // unattended | approval
            $table->string('route', 20)->nullable();
            $table->jsonb('approval_reasons')->nullable();
            $table->jsonb('warnings')->nullable();
            $table->text('description')->nullable();
            $table->text('reason')->nullable();
            $table->jsonb('cited_evidence')->nullable();
            $table->boolean('reply_blocking')->default(false);
            $table->string('idempotency_key', 64)->unique();
            $table->jsonb('policy_snapshot')->nullable();
            $table->jsonb('precondition_snapshot')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->jsonb('request')->nullable();
            $table->jsonb('response')->nullable();
            $table->text('error')->nullable();
            $table->jsonb('verify_result')->nullable();
            $table->jsonb('undo')->nullable();
            $table->unsignedSmallInteger('reconcile_attempts')->default(0);
            $table->boolean('dry_run')->default(false);
            $table->boolean('rewrite_requested')->default(false);
            $table->timestamps();

            $table->index(['workspace_id', 'status', 'created_at']);
            $table->index(['workspace_id', 'action_key', 'created_at']);
            $table->index(['conversation_id', 'created_at']);
            $table->index(['workspace_id', 'customer_email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_action_executions');
        Schema::dropIfExists('agent_action_policies');
        Schema::dropIfExists('agent_action_settings');
    }
};
