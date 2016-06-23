<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\CoreHome\Tracker\LogTable;

use Piwik\Plugins\CoreHome\Tracker\LogTable;

class Conversion extends LogTable
{
    public function getName()
    {
        return 'log_conversion';
    }

    public function canBeJoinedOnIdVisit()
    {
        return true;
    }
    
    public function shouldJoinWithSubSelect($table)
    {
        // if conversions are joined on visits, we need a complex join
        return $table == 'log_visits';
    }
}