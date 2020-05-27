<?php

/*
 * This file is part of the hyn/multi-tenant package.
 *
 * (c) Daniël Klabbers <daniel@klabbers.email>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @see https://tenancy.dev
 * @see https://github.com/hyn/multi-tenant
 */

namespace Hyn\Tenancy\Database;

use Hyn\Tenancy\Contracts\Database\PasswordGenerator;
use Hyn\Tenancy\Exceptions\ConnectionException;
use Hyn\Tenancy\Contracts\Hostname;
use Hyn\Tenancy\Contracts\Website;
use Hyn\Tenancy\Traits\ConvertsEntityToWebsite;
use Hyn\Tenancy\Traits\DispatchesEvents;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseManager;
use Hyn\Tenancy\Events;
use Illuminate\Support\Arr;
use Illuminate\Support\Traits\Macroable;
use Illuminate\Support\Facades\DB;

class Connection
{
    use DispatchesEvents, ConvertsEntityToWebsite, Macroable;

    const DEFAULT_SYSTEM_NAME = 'system';
    const DEFAULT_TENANT_NAME = 'tenant';

    /**
    * @deprecated
    */
    const DEFAULT_MIGRATION_NAME = 'tenant-migration';

    const DIVISION_MODE_SEPARATE_DATABASE = 'database';
    const DIVISION_MODE_SEPARATE_PREFIX = 'prefix';

    /**
     * Allows division by schema. Postges only.
     */
    const DIVISION_MODE_SEPARATE_SCHEMA = 'schema';

    /**
     * Allows manually setting the configuration during event callbacks.
     */
    const DIVISION_MODE_BYPASS = 'bypass';

    const TENANTS_PER_DATABASE = 2;

    const RANDOM_KEY = "1df1dsffds3ds6dfs+5sfd";

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var PasswordGenerator
     */
    protected $passwordGenerator;
    /**
     * @var Dispatcher
     */
    protected $events;
    /**
     * @var ConnectionResolverInterface
     */
    protected $connection;
    /**
     * @var DatabaseManager
     */
    protected $db;
    /**
     * @var Kernel
     */
    protected $artisan;

    /**
     * Connection constructor.
     * @param Config $config
     * @param PasswordGenerator $passwordGenerator
     * @param DatabaseManager $db
     * @param Kernel $artisan
     */
    public function __construct(
        Config $config,
        PasswordGenerator $passwordGenerator,
        DatabaseManager $db,
        Kernel $artisan
    ) {
        $this->config = $config;
        $this->passwordGenerator = $passwordGenerator;
        $this->db = $db;
        $this->artisan = $artisan;

        $this->enforceDefaultConnection();
    }

    protected function enforceDefaultConnection()
    {
        if ($default = $this->config->get('tenancy.db.default')) {
            $this->config->set('database.default', $default);
        }
    }

    /**
     * Gets the currently active tenant connection.
     *
     * @return \Illuminate\Database\Connection
     */
    public function get(): \Illuminate\Database\Connection
    {
        return $this->db->connection($this->tenantName());
    }

    /**
     * Checks whether a connection has been set up.
     *
     * @param string|null $connection
     * @return bool
     */
    public function exists(string $connection = null): bool
    {
        $connection = $connection ?? $this->tenantName();

        return Arr::has($this->db->getConnections(), $connection);
    }

    /**
     * @param Hostname|Website $to
     * @param null $connection
     * @return bool
     * @throws ConnectionException
     */
    public function set($to, $connection = null): bool
    {
        $connection = $connection ?? $this->tenantName();

        $website = $this->convertWebsiteOrHostnameToWebsite($to);

        $existing = $this->configuration($connection);

        if ($website) {
            // Sets current connection settings.
            $this->config->set(
                sprintf('database.connections.%s', $connection),
                $this->generateConfigurationArray($website)
            );
        }

        if (Arr::get($existing, 'uuid') === optional($website)->uuid) {
            $this->emitEvent(
                new Events\Database\ConnectionSet($website, $connection, false)
            );

            return true;
        }
        // Purges the old connection.
        $this->db->purge(
            $connection
        );

        if ($website) {
            $this->db->reconnect(
                $connection
            );
        }

        $this->emitEvent(
            new Events\Database\ConnectionSet($website, $connection)
        );

        return true;
    }

    public function configuration(string $connection = null): array
    {
        $connection = $connection ?? $this->tenantName();

        return $this->config->get(
            sprintf('database.connections.%s', $connection),
            []
        );
    }

    /**
     * Gets the system connection.
     *
     * @param Hostname|Website|null $for The hostname or website for which to retrieve a system connection.
     * @return \Illuminate\Database\Connection
     */
    public function system($for = null): \Illuminate\Database\Connection
    {
        $website = $this->convertWebsiteOrHostnameToWebsite($for);

        return $this->db->connection(
            $website && $website->managed_by_database_connection ?
                $website->managed_by_database_connection :
                $this->systemName()
        );
    }

    /**
     * @return string
     */
    public function systemName(): string
    {
        return $this->config->get('tenancy.db.system-connection-name', static::DEFAULT_SYSTEM_NAME);
    }

    /**
     * @return string
     */
    public function tenantName(): string
    {
        return $this->config->get('tenancy.db.tenant-connection-name', static::DEFAULT_TENANT_NAME);
    }

    /**
     * Purges the current tenant connection.
     * @param null $connection
     */
    public function purge($connection = null)
    {
        $connection = $connection ?? $this->tenantName();

        $this->db->purge(
            $connection
        );

        $this->config->set(
            sprintf('database.connections.%s', $connection),
            []
        );
    }

    /**
     * @param Hostname|Website $for
     * @param string|null $path
     * @return bool
     */
    public function migrate($for, string $path = null): bool
    {
        $website = $this->convertWebsiteOrHostnameToWebsite($for);

        if ($path) {
            $path = realpath($path);
        }

        $options = [
            '--website_id' => [$website->id],
            '-n' => 1,
            '--force' => true
        ];

        if ($path) {
            $options['--path'] = $path;
            $options['--realpath'] = true;
        }

        $code = $this->artisan->call('tenancy:migrate', $options);

        return $code === 0;
    }

    /**
     * @param Website|Hostname $for
     * @param string $class
     * @return bool
     */
    public function seed($for, string $class = null): bool
    {
        $website = $this->convertWebsiteOrHostnameToWebsite($for);

        $options = [
            '--website_id' => [$website->id],
            '-n' => 1,
            '--force' => true
        ];

        if ($class) {
            $options['--class'] = $class;
        }

        $code = $this->artisan->call('tenancy:db:seed', $options);

        return $code === 0;
    }

    /**
     * Verifies if tenant with given uuid already exists
     *
     * @param string $uuid
     * @return boolean
     */
    public static function tenantExists($uuid) {

        config(['database.default' => 'system']);

        $rslt = DB::select("SELECT * FROM websites WHERE uuid = :uuid;", ["uuid" => $uuid]);

        if (count($rslt) > 0) {
            return TRUE;
        }

        return FALSE;
    }

    /**
    * Return actual count of tenant databases storage and their db names
    * @return array
    */
    public static function getTenantDatabasesCount() {

        config(['database.default' => 'system']);

        $currentDatabases = DB::select("SELECT SCHEMA_NAME AS `db` FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME LIKE '%tenants%';");
        $cdbRet = [];

        foreach($currentDatabases as $cdb) {
            $cdbRet[] = $cdb->db;
        }

        //sort fix due to databases are returned in random manner
        sort($cdbRet,SORT_STRING);

        $dbCount = count($currentDatabases);

        config(['database.default' => 'tenant']);

        return array("dbs" => $cdbRet, "count" => $dbCount);
    }

    /**
    * Gets tables contained in last available database
    * @param string $lastDb
    * @return array
    */
    public static function getTablesInLastDatabase() {

        $dbInfo = Connection::getTenantDatabasesCount();

        if (!empty($dbInfo["dbs"])) {
            $lastDb = end($dbInfo["dbs"]);

            config(['database.default' => 'system']);

            $tables = DB::select("SELECT TABLE_NAME AS tbl FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = :lastdb;", ["lastdb" => $lastDb]);

            config(['database.default' => 'tenant']);

            return $tables;
        }

        return FALSE;
    }

    /**
    * Gets password for given database
    * @param string $tenantBase
    * @return boolean|string
    */
    public static function getDatabasePassword($tenantBase) {

        config(['database.default' => 'system']);

        $vars = DB::select("SELECT id,uuid,the_key FROM websites WHERE stored_in_database = :tenants LIMIT 1;", ["tenants" => $tenantBase])[0];

        config(['database.default' => 'tenant']);

        if (empty($vars)) {
            return false;
        }

        $pass = $vars->the_key . "-" . $vars->uuid . "-" . $vars->id . "-" . self::RANDOM_KEY;

        return $pass;
    }

    /**
    * Generates new password for database
    * @param object $website
    * @return string $pass
    */
    public static function generateDatabasePassword($website) {

        $pass =  $website->the_key . "-" . $website->uuid . "-" . $website->id . "-" . self::RANDOM_KEY;

        return $pass;
    }

    /**
     * Checks if last database has full capacity of tenants
     * @return boolean
     */
    public static function isLastDbFull() {
        $tablesInLast = Connection::getTablesInLastDatabase();

        if (!is_array($tablesInLast)) {
            return FALSE;
        }

       // if (is_array($tablesInLast) && !empty($tablesInLast)) {

            $cntOfTenantsInLast =  Connection::exactTenantCount($tablesInLast);

            //var_dump($cntOfTenantsInLast);

            if (self::TENANTS_PER_DATABASE == $cntOfTenantsInLast ) {
                return TRUE;
            }
        //}

        return FALSE;
    }

    /**
    * @return int number of tenants in last available database
    */
    public static function howManyTenants() {

        $tablesInLast = Connection::getTablesInLastDatabase();

        if (!empty($tablesInLast)){

            $cntOfTenantsInLast =  Connection::exactTenantCount($tablesInLast);

        } else {
            $cntOfTenantsInLast = 0;
        }

        return $cntOfTenantsInLast;
    }

    /**
     * Retrieves exact tenant count in last database, gets by prefixes
     * @param array $tablesInLast
     * @return int
     */
    public static function exactTenantCount(array $tablesInLast) {

        $prefixes = [];

        foreach($tablesInLast as $tbl) {
            $pref = substr($tbl->tbl, 0, strpos($tbl->tbl, "_"));

            if (!in_array($pref, $prefixes)){
                $prefixes[] = $pref;
            }
        }

        $cntOfTenantsInLast = count($prefixes);

        return $cntOfTenantsInLast;
    }

    /**
     * Vraci string s prefixem, ktery bude pouzit pro nasledujiciho tenanta
     * @return string
     */
    public static function whatPrefixWillBeUsed() {

        if (Connection::isLastDbFull()) {
            return 1;
        }

        return intval(Connection::howManyTenants() + 1);

    }

    /**
    * Vraci cislo databaze do ktere bude ulozen novy tenanat
    * @return int
    */
    public static function whatDbWillBeUsed() {
        $dbInfo = Connection::getTenantDatabasesCount();
        $dbCount = $dbInfo["count"];

        if (!$dbCount) {
            return 0;
        }

        $lastDb = end($dbInfo["dbs"]);

        $tablesInLast = Connection::getTablesInLastDatabase($lastDb);

        $cntOfTenantsInLast = Connection::howManyTenants($tablesInLast);

        if ($cntOfTenantsInLast !== 0 && $cntOfTenantsInLast % self::TENANTS_PER_DATABASE == 0){
            $dbnum = (int) filter_var($lastDb, FILTER_SANITIZE_NUMBER_INT);
            return  ($dbnum + 1);
        } else {
            $dbnum = (int) filter_var($lastDb, FILTER_SANITIZE_NUMBER_INT);
            return $dbnum;
        }
    }


    /**
     * @param Website $website
     * @return array
     * @throws ConnectionException
     */
    public function generateConfigurationArray(Website $website): array
    {
        $clone = config(sprintf(
            'database.connections.%s',
            $website->managed_by_database_connection ?? $this->systemName()
        ));

        $mode = config('tenancy.db.tenant-division-mode');

        $this->emitEvent(new Events\Database\ConfigurationLoading($mode, $clone, $this, $website));

        // Even though username/password mutate, let's store website UUID so we can match it up.
        $clone['uuid'] = $website->uuid;

        switch ($mode) {
            case static::DIVISION_MODE_SEPARATE_DATABASE:
                //$clone['username'] = $clone['database'] = $website->uuid;
                //$clone['password'] = $this->passwordGenerator->generate($website);

                $tenantDbStorage = $website->stored_in_database;

                $dbInfo = Connection::getTenantDatabasesCount();

                if (!empty($dbInfo["dbs"])){
                    //databaze s tenanty jiz nejake existuji

                    $cntOfTenantsInLast = Connection::howManyTenants();

                    if ($cntOfTenantsInLast == 0 || $cntOfTenantsInLast % self::TENANTS_PER_DATABASE == 0){
                        //vytvoreni nove databaze pro ukladani tenantu


                        $getPw = Connection::getDatabasePassword($tenantDbStorage);

                        $clone['username'] = $clone['database'] = $getPw ? $tenantDbStorage : "tenants" . Connection::whatDbWillBeUsed();
                        $clone['password'] =  $getPw ? $getPw : Connection::generateDatabasePassword($website);
                        $clone['prefix'] = sprintf('%d_', $website->tenant_prefix);

                        $a = \DB::connection()->getDatabaseName();
                       // var_dump($a);
                       // var_dump($website->tenant_prefix);
                    } else {


                        //jinak ukladame do jiz existujici db a getneme si password

                        $clone['username'] = $clone['database'] = $tenantDbStorage;
                        $clone['password'] = Connection::getDatabasePassword($tenantDbStorage);
                        $clone['prefix'] = sprintf('%d_', $website->tenant_prefix);
                    }
                } else {

                    //pripad kdy zadna databaze s tenanty neexistuje

                    $getPw = Connection::getDatabasePassword($tenantDbStorage);

                    $clone['username'] = $clone['database'] = $tenantDbStorage;
                    $clone['password'] = $getPw ? $getPw : $this->generateDatabasePassword($website);
                    $clone['prefix'] = sprintf('%d_', $website->tenant_prefix);

                }

                break;
            case static::DIVISION_MODE_SEPARATE_PREFIX:
                $clone['prefix'] = sprintf('%d_', $website->id);
                break;
            case static::DIVISION_MODE_SEPARATE_SCHEMA:
                $clone['username'] = $clone['schema'] = $website->uuid;
                $clone['password'] = $this->passwordGenerator->generate($website);
                break;
            case static::DIVISION_MODE_BYPASS:

                break;
            default:
                throw new ConnectionException("Division mode '$mode' unknown.");
        }

        $this->emitEvent(new Events\Database\ConfigurationLoaded($clone, $this, $website));

        return $clone;
    }
}
