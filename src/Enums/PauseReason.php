<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Enums;

enum PauseReason: string
{
    /**
     * The owner is inactive, deleted, lost the panel, the gate or the page.
     */
    case OwnerLostAccess = 'owner_lost_access';

    /**
     * The panel, the page, the action, a filter or a tab no longer exists.
     */
    case TargetGone = 'target_gone';

    /**
     * The panel has tenancy, which the background export cannot carry.
     */
    case TenancyUnsupported = 'tenancy_unsupported';

    /**
     * Too many runs failed in a row.
     */
    case Failed = 'failed';

    /**
     * Everybody unsubscribed or lost the right to receive it.
     */
    case NoRecipients = 'no_recipients';

    /**
     * Paused by a person.
     */
    case Manual = 'manual';

    /**
     * Paused by a manager (somebody other than the owner): the owner cannot run, resume or copy it.
     */
    case ManagerPaused = 'manager_paused';

    /**
     * The panel requires a data-scope hook (`requireScopeHook()`) and has none.
     */
    case ScopeHookMissing = 'scope_hook_missing';

    public function label(): string
    {
        return __('filament-scheduled-reports::filament-scheduled-reports.pause_'.$this->value);
    }
}
