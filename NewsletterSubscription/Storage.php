<?php
namespace NewsletterSubscription;

use Bolt\Application;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\Table;
//use Silex;
//use Bolt;
use util;
use Doctrine\DBAL\Connection as DoctrineConn;
//use Symfony\Component\EventDispatcher\Event;

class Storage
{
    protected $app;
    protected $prefix;

    public function __construct($app)
    {
        $this->app = $app;

        $this->prefix = isset($this->app['config']['general']['database']['prefix']) ? $this->app['config']['general']['database']['prefix'] : "bolt_";

        if ($this->prefix[ strlen($this->prefix)-1 ] != "_") {
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
        if (!isset($tables[$this->prefix."subscribers"])) {
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

        $schema = new \Doctrine\DBAL\Schema\Schema();
        $subscribersTable = $schema->createTable($this->prefix."subscribers");
        $subscribersTable->addColumn("id", "integer", array("unsigned" => true, 'autoincrement' => true));
        $subscribersTable->setPrimaryKey(array("id"));
        $subscribersTable->addColumn("email", "string", array("length" => 64));
        $subscribersTable->addUniqueIndex( array( "email" ) );
        $subscribersTable->addColumn("confirmkey", "string", array("length" => 64));
        $subscribersTable->addUniqueIndex( array( "confirmkey" ) );
        $subscribersTable->addColumn("datesubscribed", "datetime", array('notNull' => false));
        $subscribersTable->addColumn("confirmed", "boolean");
        $subscribersTable->addColumn("dateconfirmed", "datetime", array('notNull' => false));
        $subscribersTable->addColumn("active", "boolean");
        $subscribersTable->addColumn("dateunsubscribed", "datetime", array('notNull' => false));
        $tables[] = $subscribersTable;

        /** @var $table Table */
        foreach($tables as $table) {
            // Create the table.
            if (!isset($currentTables[$table->getName()])) {

                /** @var $platform AbstractPlatform */
                $platform = $this->app['db']->getDatabasePlatform();
                $queries = $platform->getCreateTableSQL($table);
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
     * @param string $email
     * @return array $data The data row just inserted
     */
    public function insertSubscriber($email)
    {
        $db = $this->app['db'];

        $data = $this->initSubscriber($email);

        $res = $db->insert($this->prefix . 'subscribers', $data);
        $id = $db->lastInsertId();

        $data['id'] = $id;

        return $data;
    }

    /**
     * Creates the data for a new subscriber
     *
     * @param string $email
     * @return array $data
     */
    public function initSubscriber($email)
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
     * Find a subscriber
     *
     * @param string $email
     * @return array The data row found
     */
    public function findSubscriber($email)
    {
        $db = $this->app['db'];

        $query = sprintf("SELECT * FROM %s WHERE email=?",
                         $this->prefix . 'subscribers');

        $row = $db->fetchAssoc($query, array($email));

        return $row;
    }

    public function deleteSubscriber($email)
    {
        $db = $this->app['db'];

        return $db->delete($this->prefix . 'subscribers', array('email' => $email));
    }

    public function updateSubscriber(array $data)
    {
        $db = $this->app['db'];

        return $db->update($this->prefix . 'subscribers', $data, array('email' => $data['email']));
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
            if ( strpos($table->getName(), $this->prefix) == 0 ) {
                foreach ($table->getColumns() as $column) {
                    $tables[ $table->getName() ][ $column->getName() ] = $column->getType();
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
            if ( strpos($table->getName(), $this->prefix) == 0 ) {
                $tables[ $table->getName() ] = $table;
            }
        }

        return $tables;

    }
}
