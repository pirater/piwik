<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\CoreHome\Tracker\LogTable;

use Piwik\Plugins\CoreHome\Tracker\LogTable;

class Action extends LogTable
{
    public function getName()
    {
        return 'log_action';
    }

    public function canBeJoinedOnIdAction()
    {
        return true;
    }

    public function getColumnToJoinOnIdAction()
    {
        return 'idaction';
    }

    public function getTableToJoinOnIdVisit()
    {
        return new LinkVisitAction();
    }

    public function canBeJoinedDirectly()
    {
        return false;
    }
}
