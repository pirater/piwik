<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\DataAccess;

use Exception;
use Piwik\Common;
use Piwik\Plugins\CoreHome\Tracker\LogTable;
use Piwik\Segment\SegmentExpression;

class LogQueryBuilder
{
    /**
     * @var LogTable[]
     */
    private $logTables = array();

    /**
     * @var LogTable\Provider
     */
    private $logTableProvider;
    
    public function __construct()
    {
        $this->logTableProvider = new LogTable\Provider();
    }

    public function getSelectQueryString(SegmentExpression $segmentExpression, $select, $from, $where, $bind, $groupBy,
                                         $orderBy, $limitAndOffset)
    {
        if (!is_array($from)) {
            $from = array($from);
        }

        $fromInitially = $from;

        if (!$segmentExpression->isEmpty()) {
            $segmentExpression->parseSubExpressionsIntoSqlExpressions($from);
            $segmentSql = $segmentExpression->getSql();
            $where = $this->getWhereMatchBoth($where, $segmentSql['where']);
            $bind = array_merge($bind, $segmentSql['bind']);
        }

        $joins = $this->generateJoinsString($from);
        $joinWithSubSelect = $joins['joinWithSubSelect'];
        $from = $joins['sql'];

        // hack for https://github.com/piwik/piwik/issues/9194#issuecomment-164321612
        $useSpecialConversionGroupBy = (!empty($segmentSql)
            && strpos($groupBy, 'log_conversion.idgoal') !== false
            && $fromInitially == array('log_conversion')
            && strpos($from, 'log_link_visit_action') !== false);

        if ($useSpecialConversionGroupBy) {
            $innerGroupBy = "CONCAT(log_conversion.idvisit, '_' , log_conversion.idgoal, '_', log_conversion.buster)";
            $sql = $this->buildWrappedSelectQuery($select, $from, $where, $groupBy, $orderBy, $limitAndOffset, $innerGroupBy);
        } elseif ($joinWithSubSelect) {
            $sql = $this->buildWrappedSelectQuery($select, $from, $where, $groupBy, $orderBy, $limitAndOffset);
        } else {
            $sql = $this->buildSelectQuery($select, $from, $where, $groupBy, $orderBy, $limitAndOffset);
        }
        return array(
            'sql' => $sql,
            'bind' => $bind
        );
    }

    private function hasJoinedTableAlreadyManually($tableToFind, $joinToFind, $tables)
    {
        foreach ($tables as $index => $table) {
            if (is_array($table)
                && !empty($table['table'])
                && $table['table'] === $tableToFind
                && (!isset($table['tableAlias']) || $table['tableAlias'] === $tableToFind)
                && isset($table['joinOn']) && $table['joinOn'] === $joinToFind) {
                return true;
            }
        }

        return false;
    }

    private function findIndexOfManuallyAddedTable($tableToFind, $tables)
    {
        foreach ($tables as $index => $table) {
            if (is_array($table)
                && !empty($table['table'])
                && $table['table'] === $tableToFind
                && (!isset($table['tableAlias']) || $table['tableAlias'] === $tableToFind)) {
                return $index;
            }
        }
    }

    private function hasTableAddedManually($tableToFind, $tables)
    {
        $table = $this->findIndexOfManuallyAddedTable($tableToFind, $tables);

        return isset($table);
    }

    private function getKnownTables()
    {
        $names = array();
        foreach ($this->logTableProvider->getAllLogTables() as $logTable) {
            $names[] = $logTable->getName();
        }
        return $names;
    }

    /**
     * Generate the join sql based on the needed tables
     * @param array $tables tables to join
     * @throws Exception if tables can't be joined
     * @return array
     */
    private function generateJoinsString(&$tables)
    {
        $availableLogTables = array();
        $joinWithSubSelect = false;
        $sql = '';

        $nonVisitJoins = array();

        /**
         * Holds the actual log table instances whereas $tables holds the table names
         * @var LogTable[]|array $logTables
         */
        $logTables = array();

        foreach ($tables as $table) {
            if (is_array($table)) {
                $logTables[] = $table;
            } else {
                $logTable = $this->logTableProvider->findLogTable($table);

                if (empty($logTable)) {
                    throw new Exception("Table '$table' can't be used for segmentation");
                }

                $logTables[] = $logTable;
            }
        }

        foreach ($logTables as $index => $logTable) {
            if (is_array($logTable)) {
                continue;
            }

            if (!$logTable->canBeJoinedOnIdVisit()) {
                $tableToJoin = $logTable->getLinkTableToBeAbleToJoinOnVisit();

                // in this case they must be joinable by action/idAction
                if (!$tableToJoin->canBeJoinedOnAction()) {
                    throw new Exception('The link table "%s" to join on visit - as returned by "%s" - is not joinable by an action', $tableToJoin->getName(), $logTable->getName());
                }

                if ($tableToJoin->canBeJoinedOnAction() && !in_array($tableToJoin->getName(), $tables)) {
                    // in theory we would need to check whether $tableToJoin can be joined with visits and if not add
                    // another table, but we won't do this for now as not needed yet. There can be currently only one
                    // "link" table to link to idvisit.
                    $logTables[] = $tableToJoin;
                    $tables[] = $tableToJoin->getName();
                }

                $defaultLogActionJoin = sprintf("%s.%s = %s.%s", $tableToJoin->getName(), $tableToJoin->getColumnToJoinOnIdAction(),
                                                                 $logTable->getName(), $logTable->getColumnToJoinOnIdAction());

                $altDefaultLogActionJoin = sprintf("%s.%s = %s.%s", $logTable->getName(), $logTable->getColumnToJoinOnIdAction(),
                                                                    $tableToJoin->getName(), $tableToJoin->getColumnToJoinOnIdAction());

                if ($index > 0
                    && $this->hasTableAddedManually($logTable->getName(), $tables)
                    && !$this->hasJoinedTableAlreadyManually($logTable->getName(), $defaultLogActionJoin, $tables)
                    && !$this->hasJoinedTableAlreadyManually($logTable->getName(), $altDefaultLogActionJoin, $tables)) {
                    $tableIndex = $this->findIndexOfManuallyAddedTable($logTable->getName(), $tables);
                    $defaultLogActionJoin = '(' . $tables[$tableIndex]['joinOn'] . ' AND ' . $defaultLogActionJoin . ')';
                    unset($tables[$tableIndex]);
                }

                $nonVisitJoins[$logTable->getName()] = array($tableToJoin->getName() => $defaultLogActionJoin);
                $nonVisitJoins[$tableToJoin->getName()] = array($logTable->getName() => $defaultLogActionJoin);
            }
        }

        // we need to make sure first table always comes first, then sort tables afterwards
        // if table cannot be joined, find out how to join
        $firstTable = array_shift($tables);
        usort($tables, array($this, 'sortTablesForJoin'));
        array_unshift($tables, $firstTable);

        $firstTable = array_shift($logTables);
        usort($logTables, array($this, 'sortTablesForJoin'));
        array_unshift($logTables, $firstTable);

        foreach ($logTables as $i => $logTable) {
            if (is_array($logTable)) {

                // join condition provided
                $alias = isset($logTable['tableAlias']) ? $logTable['tableAlias'] : $logTable['table'];
                $sql .= "
				LEFT JOIN " . Common::prefixTable($logTable['table']) . " AS " . $alias
                    . " ON " . $logTable['joinOn'];
                continue;
            }

            $tableSql = Common::prefixTable($logTable->getName()) . " AS " . $logTable->getName();

            if ($i == 0) {
                // first table
                $sql .= $tableSql;
            } else {

                // we first check for logLinkVisitsTableAvailable, and if we can join on idvisit
                // we check if logvisit available and join on idvisit
                // we check if conversion available and join on idvisit
                // we check if conversion item available and join on idvisit
                $alternativeJoin = '';
                $join = null;

                foreach ($availableLogTables as $availableLogTable) {
                    if ($logTable->canBeJoinedOnIdVisit() &&
                        $availableLogTable->canBeJoinedOnIdVisit()) {
                        $join = sprintf("%s.%s = %s.%s", $logTable->getName(), $logTable->getColumnToJoinOnIdVisit(),
                                                         $availableLogTable->getName(), $availableLogTable->getColumnToJoinOnIdVisit());
                        $alternativeJoin = sprintf("%s.%s = %s.%s", $availableLogTable->getName(), $availableLogTable->getColumnToJoinOnIdVisit(),
                                                                    $logTable->getName(), $logTable->getColumnToJoinOnIdVisit());

                        if ($availableLogTable->getName() == 'log_visit') {
                            $joinWithSubSelect = true;
                        }

                        break;
                    }

                    if ($logTable->canBeJoinedOnAction() && $availableLogTable->canBeJoinedOnAction()) {
                        $join = $nonVisitJoins[$logTable->getName()][$availableLogTable->getName()];

                        break;
                    }
                }

                if (!isset($join)) {
                    throw new Exception("Table '" . $logTable->getName() ."' can't be joined for segmentation");
                }
                // if we cannot join on idvisit, in theory, we need to find a table to join via idvisit

                if ($this->hasJoinedTableAlreadyManually($logTable->getName(), $join, $tables)
                    || $this->hasJoinedTableAlreadyManually($logTable->getName(), $alternativeJoin, $tables)) {
                    $availableLogTables[$logTable->getName()] = $logTable;
                    continue;
                }

                    // the join sql the default way
                    $sql .= "
				LEFT JOIN $tableSql ON $join";
            }

            $availableLogTables[$logTable->getName()] = $logTable;
        }

        $return = array(
            'sql'               => $sql,
            'joinWithSubSelect' => $joinWithSubSelect
        );

        return $return;
    }

    public function sortTablesForJoin($tA, $tB)
    {
        $coreSort = array('log_link_visit_action' => 0, 'log_action' => 1, 'log_visit' => 2, 'log_conversion' => 3, 'log_conversion_item' => 4);

        if (is_array($tA) && is_array($tB)) {
            return 0;
        }
        if (is_array($tA)) {
            return -1;
        }
        if (is_array($tB)) {
            return 1;
        }

        if (is_object($tA)) {
            $tA = $tA->getName();
        }

        if (is_object($tB)) {
            $tB = $tB->getName();
        }

        if (isset($coreSort[$tA])) {
            $weightA = $coreSort[$tA];
        } else {
            $weightA = 999;
        }
        if (isset($coreSort[$tB])) {
            $weightB = $coreSort[$tB];
        } else {
            $weightB = 999;
        }

        if ($weightA === $weightB) {
            return 0;
        }

        if ($weightA > $weightB) {
            return 1;
        }

        return -1;
    }


    /**
     * Build a select query where actions have to be joined on visits (or conversions)
     * In this case, the query gets wrapped in another query so that grouping by visit is possible
     * @param string $select
     * @param string $from
     * @param string $where
     * @param string $groupBy
     * @param string $orderBy
     * @param string $limitAndOffset
     * @param null|string $innerGroupBy  If given, this inner group by will be used. If not, we try to detect one
     * @throws Exception
     * @return string
     */
    private function buildWrappedSelectQuery($select, $from, $where, $groupBy, $orderBy, $limitAndOffset, $innerGroupBy = null)
    {
        $matchTables = '(' . implode('|', $this->getKnownTables()) . ')';
        preg_match_all("/". $matchTables ."\.[a-z0-9_\*]+/", $select, $matches);
        $neededFields = array_unique($matches[0]);

        if (count($neededFields) == 0) {
            throw new Exception("No needed fields found in select expression. "
                . "Please use a table prefix.");
        }

        preg_match_all("/". $matchTables . "/", $from, $matchesFrom);

        $innerSelect = implode(", \n", $neededFields);
        $innerFrom = $from;
        $innerWhere = $where;

        $innerLimitAndOffset = $limitAndOffset;

        if (!isset($innerGroupBy) && in_array('log_visit', $matchesFrom[1])) {
            $innerGroupBy = "log_visit.idvisit";
        } elseif (!isset($innerGroupBy)) {
            throw new Exception('Cannot use subselect for join as no group by rule is specified');
        }

        $innerOrderBy = "NULL";
        if ($innerLimitAndOffset && $orderBy) {
            // only When LIMITing we can apply to the inner query the same ORDER BY as the parent query
            $innerOrderBy = $orderBy;
        }
        if ($innerLimitAndOffset) {
            // When LIMITing, no need to GROUP BY (GROUPing by is done before the LIMIT which is super slow when large amount of rows is matched)
            $innerGroupBy = false;
        }

        $innerQuery = $this->buildSelectQuery($innerSelect, $innerFrom, $innerWhere, $innerGroupBy, $innerOrderBy, $innerLimitAndOffset);

        $select = preg_replace('/'.$matchTables.'\./', 'log_inner.', $select);
        $from = "
        (
            $innerQuery
        ) AS log_inner";
        $where = false;
        $orderBy = preg_replace('/'.$matchTables.'\./', 'log_inner.', $orderBy);
        $groupBy = preg_replace('/'.$matchTables.'\./', 'log_inner.', $groupBy);

        $outerLimitAndOffset = null;
        $query = $this->buildSelectQuery($select, $from, $where, $groupBy, $orderBy, $outerLimitAndOffset);
        return $query;
    }


    /**
     * Build select query the normal way
     *
     * @param string $select fieldlist to be selected
     * @param string $from tablelist to select from
     * @param string $where where clause
     * @param string $groupBy group by clause
     * @param string $orderBy order by clause
     * @param string|int $limitAndOffset limit by clause eg '5' for Limit 5 Offset 0 or '10, 5' for Limit 5 Offset 10
     * @return string
     */
    private function buildSelectQuery($select, $from, $where, $groupBy, $orderBy, $limitAndOffset)
    {
        $sql = "
			SELECT
				$select
			FROM
				$from";

        if ($where) {
            $sql .= "
			WHERE
				$where";
        }

        if ($groupBy) {
            $sql .= "
			GROUP BY
				$groupBy";
        }

        if ($orderBy) {
            $sql .= "
			ORDER BY
				$orderBy";
        }

        $sql = $this->appendLimitClauseToQuery($sql, $limitAndOffset);

        return $sql;
    }

    /**
     * @param $sql
     * @param $limit LIMIT clause eg. "10, 50" (offset 10, limit 50)
     * @return string
     */
    private function appendLimitClauseToQuery($sql, $limit)
    {
        $limitParts = explode(',', (string) $limit);
        $isLimitWithOffset = 2 === count($limitParts);

        if ($isLimitWithOffset) {
            // $limit = "10, 5". We would not have to do this but we do to prevent possible injections.
            $offset = trim($limitParts[0]);
            $limit  = trim($limitParts[1]);
            $sql   .= sprintf(' LIMIT %d, %d', $offset, $limit);
        } else {
            // $limit = "5"
            $limit = (int)$limit;
            if ($limit >= 1) {
                $sql .= " LIMIT $limit";
            }
        }

        return $sql;
    }

    /**
     * @param $where
     * @param $segmentWhere
     * @return string
     * @throws
     */
    protected function getWhereMatchBoth($where, $segmentWhere)
    {
        if (empty($segmentWhere) && empty($where)) {
            throw new \Exception("Segment where clause should be non empty.");
        }
        if (empty($segmentWhere)) {
            return $where;
        }
        if (empty($where)) {
            return $segmentWhere;
        }
        return "( $where )
                AND
                ($segmentWhere)";
    }
}
