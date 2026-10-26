<?php

declare(strict_types=1);

namespace MyBeast\Sessions\Laravel\Tests;

use MyBeast\Sessions\Laravel\Flows\FlowDefinition;
use MyBeast\Sessions\Laravel\Flows\FlowStepDefinition;
use MyBeast\Sessions\Laravel\Flows\KeyFlows;

/** A host's key flows, as a host writes them: a first run for new accounts, and two tasks. */
final class HostKeyFlows implements KeyFlows
{
    /** @param  list<mixed>|null  $flows  others instead (a test's broken ones) */
    public function __construct(private readonly ?array $flows = null) {}

    public function flows(): array
    {
        return $this->flows ?? [
            new FlowDefinition('onboarding', 'First run', [
                FlowStepDefinition::start('signed-up', 'Signed up'),
                FlowStepDefinition::save('project-saved', 'First project saved', ['projects.store']),
                FlowStepDefinition::save('member-invited', 'First member invited', ['invitations.store']),
            ], giveUpAfterHours: 168, newAccountDays: 7),
            new FlowDefinition('add-project', 'Add a project', [
                FlowStepDefinition::visit('opened', 'Opened', ['properties.index', 'projects.create']),
                FlowStepDefinition::save('saved', 'Saved', ['projects.store']),
            ]),
            new FlowDefinition('import', 'Import a file', [
                FlowStepDefinition::save('uploaded', 'Uploaded', ['imports.store']),
                FlowStepDefinition::visit('reviewed', 'Reviewed', ['imports.review', 'sheets.rent']),
                FlowStepDefinition::save('booked', 'Booked', ['imports.book']),
            ]),
        ];
    }
}
