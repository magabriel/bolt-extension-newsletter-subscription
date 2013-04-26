<?php
namespace NewsletterSubscription;

use Bolt\Application;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\Table;
use util;

/**
 * Manages the database interaction
 *
 * @author Miguel Angel Gabriel (magabriel@gmail.com)
 * @see Bolt\Storage
 *
 */
class Storage
{
    protected $app;
    protected $prefix;

    public function __construct($app)
    {
        $this->app = $app;

        $this->prefix = isset($this->app['config']['general']['database']['prefix']) ?
                              $this->app['config']['general']['database']['prefix'] : "bolt_";

        if ($this->prefix[strlen($this->prefix) - 1] != "_") {
            $this->prefix .= "_";
        }

        $this->prefix = $this->prefix . 'nl_';
    }

    /**
     * Check if just the subscribers table is present.
     *
     * @return boolean
     */
    public function checkSubscribersTableIntegrity()
    {

        $tables = $this->getTables();

        // Check the newsletter table..
        if (!isset($tables[$this->prefix . "subscribers"])) {
            return false;
        }

        return true;

    }

    public function repairTables()
    {

        $output = array();

        $currentTables = $this->getTableObjects();

        $dboptions = getDBOptions($this->app['config']);
        /** @var $schemaManager AbstractSchemaManager */
        $schemaManager = $this->app['db']->getSchemaManager();

        $comparator = new Comparator();

        $tables = array();

        // Define table subscribers
        $schema = new \Doctrine\DBAL\Schema\Schema();
        $subscribersTable = $schema->createTable($this->prefix . 'subscribers');

        $subscribersTable->addColumn('id', 'integer', array(
                         'autoincrement' => true
            ));
        $subscribersTable->addColumn('email', 'string', array(
                         'length' => 64
            ));
        $subscribersTable->addColumn('confirmkey', 'string', array(
                         'length' => 64
            ));
        $subscribersTable->addColumn('datesubscribed', 'datetime', array(
                         'notNull' => false
            ));
        $subscribersTable->addColumn('confirmed', 'boolean');
        $subscribersTable->addColumn('dateconfirmed', 'datetime', array(
                         'notNull' => false
            ));
        $subscribersTable->addColumn('active', 'boolean');
        $subscribersTable->addColumn('dateunsubscribed', 'datetime', array(
                         'notNull' => false
            ));

        $subscribersTable->setPrimaryKey(array(
                         'id'
            ));
        $subscribersTable->addUniqueIndex(array(
                         'email'
            ));
        $subscribersTable->addUniqueIndex(array(
                         'confirmkey'
            ));

        $tables[] = $subscribersTable;

        // Define table extra_fields
        $schema = new \Doctrine\DBAL\Schema\Schema();
        $extraFieldsTable = $schema->createTable($this->prefix . 'extra_fields');

        $extraFieldsTable->addColumn('id', 'integer', array(
                         'autoincrement' => true
            ));
        $extraFieldsTable->addColumn('subscribers_id', 'integer', array(
                         'unsigned' => true
            ));
        $extraFieldsTable->addColumn('name', 'string', array(
                         'length' => 30
            ));
        $extraFieldsTable->addColumn('value', 'string', array(
                         'length' => 255,
                         'notNull' => false
            ));

        $extraFieldsTable->setPrimaryKey(array(
                         'id'
            ));
        $extraFieldsTable
            ->addForeignKeyConstraint($subscribersTable, array(
                    'subscribers_id'
            ), array(
                    'id'
            ), array(
                    "onDelete" => "CASCADE"
            ));
        $tables[] = $extraFieldsTable;

        /** @var $table Table */
        foreach ($tables as $table) {
            // Create the tables
            if (!isset($currentTables[$table->getName()])) {

                /** @var $platform AbstractPlatform */
                $platform = $this->app['db']->getDatabasePlatform();
                $queries = $platform
                    ->getCreateTableSQL($table, AbstractPlatform::CREATE_INDEXES + AbstractPlatform::CREATE_FOREIGNKEYS);
                $queries = implode("; ", $queries);
                $this->app['db']->query($queries);

                $output[] = "Created table <tt>" . $table->getName() . "</tt>.";
            }
        }

        return $output;
    }

    /**
     * Insert a subscriber
     *
     * @param array $data
     * @return array The data just inserted
     */
    public function subscriberInsert(array $data)
    {
        $db = $this->app['db'];

        // Create and insert subscriber row
        $newData = $this->subscriberInit($data['email']);
        $res = $db->insert($this->prefix . 'subscribers', $newData);
        $newData['id'] = $db->lastInsertId();

        // Create and insert extra_fields rows
        $extraFields = $data;
        unset($extraFields['email']);
        unset($extraFields['agree']);
        $extraFieldsRows = $this->extraFieldsInsert($extraFields, $newData['id']);

        $data = $newData;
        $data['extra_fields'] = $extraFieldsRows;

        return $data;
    }

    /**
     * Creates the data for a new subscriber, ready to be inserted
     *
     * @param string $email
     * @return array $data
     */
    public function subscriberInit($email)
    {
        $confirmKey = sha1(microtime());

        $data = array(
                'email' => $email,
                'confirmkey' => $confirmKey,
                'datesubscribed' => date('Y-m-d H:i:s'),
                'confirmed' => false,
                'dateconfirmed' => null,
                'active' => true,
                'dateunsubscribed' => null
        );

        return $data;
    }

    /**
     * Retrieve all subscribers
     * @return array[subscriber]
     */
    public function subscriberFindAll()
    {
        $db = $this->app['db'];

        $query = sprintf('SELECT * FROM %s', $this->prefix . 'subscribers');

        $rows = $db->fetchAll($query);

        $data = array();
        foreach ($rows as $row) {
            $row['extra_fields'] = $this->extraFieldsFind($row['id']);
            $data[] = $row;
        }

        return $data;

    }

    /**
     * Retrieve statistics
     * @return array[stats]
     */
    public function subscriberStats()
    {
        $db = $this->app['db'];

        $stats = array();

        $query = sprintf('(SELECT count(*)
                           FROM %s', $this->prefix . 'subscribers
                          WHERE confirmed = 1 AND active = 1)');
        $stats['confirmed'] = $db->fetchColumn($query);

        $query = sprintf('(SELECT count(*)
                           FROM %s', $this->prefix . 'subscribers
                          WHERE confirmed <> 1 AND active = 1)');
        $stats['unconfirmed'] = $db->fetchColumn($query);

        $query = sprintf('(SELECT count(*)
                           FROM %s', $this->prefix . 'subscribers
                          WHERE active <> 1)');
        $stats['unsubscribed'] = $db->fetchColumn($query);


        $stats['total'] = $stats['confirmed'] + $stats['unconfirmed'];

        return $stats;
    }

    /**
     * Find a subscriber
     *
     * @param string $email
     * @param boolen $fetchExtraFields Retrieve also extra fields
     * @return array The data row found
     */
    public function subscriberFind($email, $fetchExtraFields = true)
    {
        $db = $this->app['db'];

        $query = sprintf("SELECT * FROM %s WHERE email = ?", $this->prefix . 'subscribers');

        $row = $db->fetchAssoc($query, array(
                    $email
            ));

        if ($row && $fetchExtraFields) {
            // Addd the extra fields
            $row['extra_fields'] = $this->extraFieldsFind($row['id']);
        }

        return $row;
    }

    /**
     * @param string $email
     * @return integer The number of affected rows.
     */
    public function subscriberDelete($email)
    {
        $db = $this->app['db'];

        return $db->delete($this->prefix . 'subscribers', array(
                    'email' => $email
            ));
    }

    /**
     * @param array $data
     * @return array Inserted rows
     */
    public function subscriberUpdate(array $data)
    {
        $db = $this->app['db'];

        if (isset($data['extra_fields'])) {
            unset($data['extra_fields']);
        }

        return $db->update($this->prefix . 'subscribers', $data, array(
                    'email' => $data['email']
            ));
    }

    /**
     * Find all extra fields for a subscribers_id
     *
     * @param int $subscribersId
     * @return array
     */
    protected function extraFieldsFind($subscribersId)
    {
        $db = $this->app['db'];

        $query = sprintf("SELECT * FROM %s WHERE subscribers_id = ?", $this->prefix . 'extra_fields');

        $data = $db->fetchAll($query, array(
                    $subscribersId
            ));

        return $data;
    }

    /**
     * Insert the extra fields for a subscribers_id
     *
     * @param array $extraFields
     * @param int $subscriberId
     * @return array The extra fields inserted
     */
    protected function extraFieldsInsert(array $extraFields, $subscriberId)
    {
        $db = $this->app['db'];

        $rows = array();
        foreach ($extraFields as $field => $value) {
            $data = array(
                    'subscribers_id' => $subscriberId,
                    'name' => $field,
                    'value' => $value
            );
            $res = $db->insert($this->prefix . 'extra_fields', $data);
            $data['id'] = $db->lastInsertId();

            $rows[] = $data;
        }

        return $rows;
    }

    /**
     * Get an associative array with the bolt_tables tables and columns in the DB.
     *
     * @return array
     */
    protected function getTables()
    {
        $sm = $this->app['db']->getSchemaManager();

        $tables = array();

        foreach ($sm->listTables() as $table) {
            if (strpos($table->getName(), $this->prefix) == 0) {
                foreach ($table->getColumns() as $column) {
                    $tables[$table->getName()][$column->getName()] = $column->getType();
                }
            }
        }

        return $tables;

    }

    /**
     * Get an associative array with the bolt_tables tables as Doctrine\DBAL\Schema\Table objects
     *
     * @return array
     */
    protected function getTableObjects()
    {

        $sm = $this->app['db']->getSchemaManager();

        $tables = array();

        foreach ($sm->listTables() as $table) {
            if (strpos($table->getName(), $this->prefix) == 0) {
                $tables[$table->getName()] = $table;
            }
        }

        return $tables;

    }
}
