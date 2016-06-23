<?php
namespace Piwik\Plugins\CoreHome\Tracker\LogTable;

use Piwik\Plugins\CoreHome\Tracker\LogTable;

class Provider {

    /**
     * @var LogTable[]
     */
    private $tables = array();

    public function __construct()
    {
        $this->tables = array(
            new Visit(),
            new LinkVisitAction(),
            new Action(),
            new Conversion(),
            new ConversionItem()
        );
    }

    /**
     * @param $tableName
     * @return LogTable
     */
    public function findLogTable($tableName)
    {
        foreach ($this->tables as $table) {
            if ($table->getName() === $tableName) {
                return $table;
            }
        }
    }

    /**
     * @return LogTable[]
     */
    public function getAllLogTables()
    {
        return $this->tables;
    }

}
