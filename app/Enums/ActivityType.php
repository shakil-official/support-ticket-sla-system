<?php

namespace App\Enums;

enum ActivityType: string
{
    case Created = 'created';
    case Imported = 'imported';
    case Assigned = 'assigned';
    case Reassigned = 'reassigned';
    case AssignmentFailed = 'assignment_failed';
    case StatusChanged = 'status_changed';
    case PriorityChanged = 'priority_changed';
    case CategoryChanged = 'category_changed';
    case Updated = 'updated';
    case Replied = 'replied';
    case InternalNote = 'internal_note';
    case Attachment = 'attachment';
    case SlaWarning = 'sla_warning';
    case Escalated = 'escalated';
}
