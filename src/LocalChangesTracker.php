<?php

namespace Wabel\Zoho\CRM\Copy;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Psr\Log\LoggerInterface;

/**
 * This class is in charge of tracking local files.
 * To do so, it can add a set of triggers that observe and track changes in tables.
 */
class LocalChangesTracker
{
    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Connection $connection
     */
    public function __construct(Connection $connection, LoggerInterface $logger)
    {
        $this->connection = $connection;
        $this->logger = $logger;
    }

    public function createTrackingTables()
    {
        $schema = new \Doctrine\DBAL\Schema\Schema();

        $localUpdate = $schema->createTable('local_update');
        $localUpdate->addColumn('table_name', 'string', ['length' => 100]);
        $localUpdate->addColumn('uid', 'string', ['length' => 36]);
        $localUpdate->addColumn('field_name', 'string', ['length' => 100]);
        $localUpdate->setPrimaryKey(array('table_name', 'uid', 'field_name'));

        $localInsert = $schema->createTable('local_insert');
        $localInsert->addColumn('table_name', 'string', ['length' => 100]);
        $localInsert->addColumn('uid', 'string', ['length' => 36]);
        $localInsert->setPrimaryKey(array('table_name', 'uid'));

        $localDelete = $schema->createTable('local_delete');
        $localDelete->addColumn('table_name', 'string', ['length' => 100]);
        $localDelete->addColumn('uid', 'string', ['length' => 36]);
        $localDelete->addColumn('id', 'string', ['length' => 100,'notnull'=>false]);
        $localDelete->setPrimaryKey(array('table_name', 'uid'));
        $localDelete->addUniqueIndex(['id', 'table_name']);

        $dbalTableDiffService = new DbalTableDiffService($this->connection, $this->logger);
        $dbalTableDiffService->createOrUpdateTable($localUpdate);
        $dbalTableDiffService->createOrUpdateTable($localInsert);
        $dbalTableDiffService->createOrUpdateTable($localDelete);
    }

    public function createUuidInsertTrigger(Table $table)
    {
        $triggerName = sprintf('TRG_%s_SETUUIDBEFOREINSERT', $table->getName());

        //Fix - temporary MySQL 5.7 strict mode
        $sql = sprintf('
            DROP TRIGGER IF EXISTS %s;
            
            CREATE TRIGGER %s BEFORE INSERT ON `%s` 
            FOR EACH ROW
            IF new.uid IS NULL
              THEN
              	SET @uuidmy = uuid();
                SET new.uid = LOWER(CONCAT(
                SUBSTR(HEX(@uuidmy), 1, 8), \'-\',
                SUBSTR(HEX(@uuidmy), 9, 4), \'-\',
                SUBSTR(HEX(@uuidmy), 13, 4), \'-\',
                SUBSTR(HEX(@uuidmy), 17, 4), \'-\',
                SUBSTR(HEX(@uuidmy), 21)
              ));
              END IF;
            ', $triggerName, $triggerName, $table->getName());

        $this->connection->exec($sql);
    }

    public function createInsertTrigger(Table $table)
    {
        $triggerName = sprintf('TRG_%s_ONINSERT', $table->getName());

        $sql = sprintf('
            DROP TRIGGER IF EXISTS %s;
            
            CREATE TRIGGER %s AFTER INSERT ON `%s` 
            FOR EACH ROW
            BEGIN
              IF (NEW.lastActivityTime IS NULL) THEN
                INSERT INTO local_insert VALUES (%s, NEW.uid);
                DELETE FROM local_delete WHERE table_name = %s AND uid = NEW.uid;
                DELETE FROM local_update WHERE table_name = %s AND uid = NEW.uid;
              END IF;
            END;
            
            ', $triggerName, $triggerName, $table->getName(), $this->connection->quote($table->getName()), $this->connection->quote($table->getName()), $this->connection->quote($table->getName()));

        $this->connection->exec($sql);
    }

    public function createDeleteTrigger(Table $table)
    {
        $triggerName = sprintf('TRG_%s_ONDELETE', $table->getName());

        $sql = sprintf('
            DROP TRIGGER IF EXISTS %s;
            
            CREATE TRIGGER %s BEFORE DELETE ON `%s` 
            FOR EACH ROW
            BEGIN
              IF (OLD.id IS NOT NULL) THEN
                INSERT INTO local_delete VALUES (%s, OLD.uid, OLD.id);
              END IF;
              DELETE FROM local_insert WHERE table_name = %s AND uid = OLD.uid;
              DELETE FROM local_update WHERE table_name = %s AND uid = OLD.uid;
            END;
            
            ', $triggerName, $triggerName, $table->getName(), $this->connection->quote($table->getName()), $this->connection->quote($table->getName()), $this->connection->quote($table->getName()));

        $this->connection->exec($sql);
    }

    public function createUpdateTrigger(Table $table)
    {
        $triggerName = sprintf('TRG_%s_ONUPDATE', $table->getName());

        $innerCode = '';

        foreach ($table->getColumns() as $column) {
            if (in_array($column->getName(), ['id', 'uid'])) {
                continue;
            }
            $columnName = $this->connection->quoteIdentifier($column->getName());
            $innerCode .= sprintf('
                IF NOT(NEW.%s <=> OLD.%s) THEN
                  REPLACE INTO local_update VALUES (%s, NEW.uid, %s);
                END IF;
            ', $columnName, $columnName, $this->connection->quote($table->getName()), $this->connection->quote($column->getName()));
        }

        $sql = sprintf('
            DROP TRIGGER IF EXISTS %s;
            
            CREATE TRIGGER %s AFTER UPDATE ON `%s` 
            FOR EACH ROW
            BEGIN
              IF (NEW.lastActivityTime <=> OLD.lastActivityTime) THEN
            %s
              END IF;
            END;
            
            ', $triggerName, $triggerName, $table->getName(), $innerCode);

        $this->connection->exec($sql);
    }
}
