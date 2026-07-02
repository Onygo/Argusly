<?php

use App\Support\Interaction\Action;
use App\Support\Interaction\ActionContext;
use App\Support\Interaction\ActionGroup;
use App\Support\Interaction\ActionPolicyResolver;
use App\Support\Interaction\ActionRegistry;
use Illuminate\Support\Facades\Gate;

it('resolves a route-backed action contract for shell, toolbar, command palette, shortcuts, and AI surfaces', function () {
    $registry = ActionRegistry::make()->register(
        Action::make('app.dashboard.open', 'Open dashboard', 'navigate')
            ->description('Return to the workspace dashboard.')
            ->icon('layout-dashboard')
            ->shortcut('g d')
            ->route('app.dashboard')
            ->visibleIn(
                Action::SURFACE_TOOLBAR,
                Action::SURFACE_COMMAND_PALETTE,
                Action::SURFACE_SHORTCUT,
                Action::SURFACE_AI_RECOMMENDATION,
            )
            ->history(['records' => true, 'event' => 'dashboard.opened'])
            ->ai(['intent' => 'navigation', 'explain' => 'Open the current workspace dashboard.'])
    );

    $action = $registry->resolve(
        'app.dashboard.open',
        ActionContext::make([
            'surface' => Action::SURFACE_COMMAND_PALETTE,
            'page_key' => 'app.content.index',
            'route_name' => 'app.content.index',
            'workspace_id' => 'workspace-1',
        ])
    );

    expect($action)
        ->toMatchArray([
            'key' => 'app.dashboard.open',
            'label' => 'Open dashboard',
            'description' => 'Return to the workspace dashboard.',
            'verb' => 'navigate',
            'icon' => 'layout-dashboard',
            'shortcut' => 'g d',
            'execution_mode' => Action::EXECUTION_LINK,
            'method' => 'GET',
            'authorized' => true,
            'visible' => true,
            'disabled' => false,
        ])
        ->and($action['route'])->toMatchArray(['name' => 'app.dashboard', 'exists' => true])
        ->and($action['url'])->toContain('/dashboard')
        ->and($action['history'])->toBe(['records' => true, 'event' => 'dashboard.opened'])
        ->and($action['ai']['intent'])->toBe('navigation');
});

it('filters actions by authorization, visibility, and surface without duplicating action definitions', function () {
    Gate::define('manage-action-registry-test', fn () => false);

    $registry = ActionRegistry::make()
        ->register(
            Action::make('visible.toolbar', 'Visible action')
                ->route('app.dashboard')
                ->visibleIn(Action::SURFACE_TOOLBAR)
        )
        ->register(
            Action::make('hidden.by.policy', 'Policy-hidden action')
                ->route('admin.dashboard')
                ->policy('manage-action-registry-test')
                ->visibleIn(Action::SURFACE_TOOLBAR)
        )
        ->register(
            Action::make('hidden.by.state', 'State-hidden action')
                ->route('app.dashboard')
                ->visibleWhen(fn (ActionContext $context): bool => $context->permission('show_state_action'))
                ->visibleIn(Action::SURFACE_TOOLBAR)
        )
        ->register(
            Action::make('row.only', 'Row only action')
                ->route('app.dashboard')
                ->visibleIn(Action::SURFACE_ROW)
        );

    $toolbarActions = $registry->forSurface(Action::SURFACE_TOOLBAR, ActionContext::make());

    expect($toolbarActions)->toHaveCount(1)
        ->and($toolbarActions[0]['key'])->toBe('visible.toolbar')
        ->and($registry->forSurface(Action::SURFACE_TOOLBAR, ActionContext::make(), includeHidden: true))->toHaveCount(4);
});

it('keeps disabled reasons, confirmation metadata, bulk support, and form execution metadata together', function () {
    $registry = ActionRegistry::make()->register(
        Action::make('admin.queues.failed.bulk-delete', 'Delete failed jobs', 'delete')
            ->form('failed-bulk-delete-form', 'admin.queues.destroy-bulk', method: 'POST')
            ->icon('trash-2')
            ->bulk(
                selectionMode: 'selected',
                resourceType: 'failed_job',
                supportsAllMatching: false,
                eligibility: 'Selected failed jobs that still exist may be deleted.',
            )
            ->confirm(
                title: 'Delete selected failed jobs?',
                message: 'This removes the selected failed job records from the failure queue.',
                severity: 'destructive',
                confirmLabel: 'Delete jobs',
            )
            ->disabledWhen(
                fn (ActionContext $context): bool => ! $context->hasSelection(),
                'Select at least one failed job.',
            )
            ->history(['records' => true, 'type' => 'bulk_mutation'])
            ->ai(['risk' => 'destructive', 'reason_source' => 'failed_job_selection'])
    );

    $withoutSelection = $registry->bulkActions(ActionContext::make());
    $withSelection = $registry->bulkActions(ActionContext::make()->withSelection(['job-1', 'job-2']));

    expect($withoutSelection[0])
        ->toMatchArray([
            'key' => 'admin.queues.failed.bulk-delete',
            'execution_mode' => Action::EXECUTION_FORM,
            'method' => 'POST',
            'disabled' => true,
            'disabled_reason' => 'Select at least one failed job.',
        ])
        ->and($withoutSelection[0]['form'])->toBe(['id' => 'failed-bulk-delete-form'])
        ->and($withoutSelection[0]['confirmation']['severity'])->toBe('destructive')
        ->and($withoutSelection[0]['bulk']['resource_type'])->toBe('failed_job')
        ->and($withoutSelection[0]['route']['exists'])->toBeTrue()
        ->and($withSelection[0]['disabled'])->toBeFalse()
        ->and($withSelection[0]['disabled_reason'])->toBeNull();
});

it('supports drawer and row action metadata from the same action contract', function () {
    $registry = ActionRegistry::make()->register(
        Action::make('app.recommended-actions.inspect', 'Inspect recommendation', 'open')
            ->route('app.recommended-actions.index')
            ->resource('recommended_action')
            ->drawer(target: 'recommended-action-detail', mode: 'inspect', width: 'lg')
            ->visibleIn(Action::SURFACE_ROW, Action::SURFACE_CONTEXT_MENU, Action::SURFACE_DRAWER)
            ->ai(['explainable_context' => ['recommendation_score', 'source_signal']])
    );

    $rowAction = $registry->forSurface(
        Action::SURFACE_ROW,
        ActionContext::make(['resource_type' => 'recommended_action', 'resource_id' => 42])
    )[0];

    $drawerAction = $registry->drawerActions(
        ActionContext::make(['resource_type' => 'recommended_action', 'resource_id' => 42])
    )[0];

    expect($rowAction['drawer'])
        ->toMatchArray(['target' => 'recommended-action-detail', 'mode' => 'inspect', 'width' => 'lg', 'modal' => false])
        ->and($rowAction['resource'])->toBe(['type' => 'recommended_action', 'id' => null])
        ->and($drawerAction['key'])->toBe('app.recommended-actions.inspect')
        ->and($drawerAction['ai']['explainable_context'])->toBe(['recommendation_score', 'source_signal']);
});

it('groups actions and rejects duplicate action keys', function () {
    $group = ActionGroup::make('content', 'Content actions')
        ->add(Action::make('content.open', 'Open content')->route('app.dashboard'))
        ->add(Action::make('content.create', 'Create content', 'create')->route('app.content.create'));

    $registry = ActionRegistry::make()->register($group);

    expect($registry->groupedForContext(ActionContext::make()))
        ->toHaveCount(1)
        ->and($registry->has('content.open'))->toBeTrue()
        ->and(fn () => $registry->register(Action::make('content.open', 'Duplicate')->route('app.dashboard')))
        ->toThrow(LogicException::class);
});

it('asserts registered actions map to existing routes, forms, policies, urls, or drawers', function () {
    $valid = ActionRegistry::make()->register(
        Action::make('valid.route', 'Valid route')->route('app.dashboard')
    );

    $invalid = ActionRegistry::make()->register(
        Action::make('invalid.route', 'Invalid route')->route('missing.route.name')
    );

    $valid->assertAllActionsMapToEndpoints();

    expect(fn () => $invalid->assertAllActionsMapToEndpoints())
        ->toThrow(LogicException::class, 'invalid.route');
});

it('allows custom policy resolvers for tests and future engines without binding UI concerns', function () {
    $resolver = new class extends ActionPolicyResolver
    {
        public function can(Action $action, ActionContext $context): bool
        {
            return $context->permission($action->key);
        }
    };

    $registry = ActionRegistry::make()->register(
        Action::make('future.command.execute', 'Execute future command')
            ->route('app.dashboard')
            ->visibleIn(Action::SURFACE_COMMAND_PALETTE)
    );

    expect($registry->forSurface(Action::SURFACE_COMMAND_PALETTE, ActionContext::make(), $resolver))->toHaveCount(0)
        ->and($registry->forSurface(
            Action::SURFACE_COMMAND_PALETTE,
            ActionContext::make(['permissions' => ['future.command.execute' => true]]),
            $resolver,
        ))->toHaveCount(1);
});
