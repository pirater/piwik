<?php
namespace Piwik\Plugins\CoreHome\Tracker;

abstract class LogTable {
    abstract public function getName();

    public function canBeJoinedOnIdVisit()
    {
        return false;
    }

    // this would could be more generic eg by specifiying "this->joinableOn = array('action' => 'idaction') and this
    // would allow to also add more complex structures in the future but not needed for now I'd say. Let's go with
    // simpler, more clean and expressive solution for now until needed.
    public function canBeJoinedOnAction()
    {
        return false;
    }

    public function getColumnToJoinOnIdVisit()
    {
        return 'idvisit';
    }

    public function getColumnToJoinOnIdAction()
    {
        return '';
    }


    /**
     * // TODO: in theory there could be case where it may be needed to join via two tables, so it could be needed at some
    // point to return an array of tables here. not sure if we should handle this case just yet
     *
     * @return LogTable
     */
    public function getLinkTableToBeAbleToJoinOnVisit()
    {
        return;
    }

    public function shouldJoinWithSubSelect($table)
    {
        return false;
    }

}
