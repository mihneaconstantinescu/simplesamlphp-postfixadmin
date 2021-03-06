<?php

namespace SimpleSAML\Module\postfixadmin\Auth\Source;

/**
 * Simple SQL authentication source
 *
 * This class is an example authentication source which authenticates an user
 * against a SQL database.
 *
 * @package SimpleSAMLphp
 */

require_once 'functions.inc.php';

class SQL extends \SimpleSAML\Module\core\Auth\UserPassBase
{
    /**
     * The DSN we should connect to.
     */
    private $dsn;

    /**
     * The username we should connect to the database with.
     */
    private $username;

    /**
     * The password we should connect to the database with.
     */
    private $password;

    /**
     * The options that we should connect to the database with.
     */
    private $options;

    /**
     * The query we should use to retrieve the attributes for the user.
     *
     * The username and password will be available as :username and :password.
     */
//  private $query;
    private $append_domain;

    /**
     * Constructor for this authentication source.
     *
     * @param array $info  Information about this authentication source.
     * @param array $config  Configuration.
     */
    public function __construct($info, $config)
    {
        assert(is_array($info));
        assert(is_array($config));

        // Call the parent constructor first, as required by the interface
        parent::__construct($info, $config);

        // Make sure that all required parameters are present.
        foreach (['dsn', 'username', 'password'] as $param) {
            if (!array_key_exists($param, $config)) {
                throw new \Exception('Missing required attribute \''.$param.
                    '\' for authentication source '.$this->authId);
            }

            if (!is_string($config[$param])) {
                throw new \Exception('Expected parameter \''.$param.
                    '\' for authentication source '.$this->authId.
                    ' to be a string. Instead it was: '.
                    var_export($config[$param], true));
            }
        }

        $this->dsn = $config['dsn'];
        $this->username = $config['username'];
        $this->password = $config['password'];
        if (isset($config['options'])) {
            $this->options = $config['options'];
        }
        if (isset($config['append_domain'])) {
    	    $this->append_domain = $config['append_domain'];
        }
        
//        require_once 'functions.inc.php';
    }


    /**
     * Create a database connection.
     *
     * @return \PDO  The database connection.
     */
    private function connect()
    {
        try {
            $db = new \PDO($this->dsn, $this->username, $this->password, $this->options);
        } catch (\PDOException $e) {
            throw new \Exception('sqlauth:'.$this->authId.': - Failed to connect to \''.
                $this->dsn.'\': '.$e->getMessage());
        }

        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $driver = explode(':', $this->dsn, 2);
        $driver = strtolower($driver[0]);

        // Driver specific initialization
        switch ($driver) {
            case 'mysql':
                // Use UTF-8
                $db->exec("SET NAMES 'utf8mb4'");
                break;
            case 'pgsql':
                // Use UTF-8
                $db->exec("SET NAMES 'UTF8'");
                break;
        }

        return $db;
    }


    /**
     * Attempt to log in using the given username and password.
     *
     * On a successful login, this function should return the users attributes. On failure,
     * it should throw an exception. If the error was caused by the user entering the wrong
     * username or password, a \SimpleSAML\Error\Error('WRONGUSERPASS') should be thrown.
     *
     * Note that both the username and the password are UTF-8 encoded.
     *
     * @param string $username  The username the user wrote.
     * @param string $password  The password the user wrote.
     * @return array  Associative array with the users attributes.
     */

    private function check_password($pw, $pw_db) {
	$pwHash = \pacrypt_md5crypt($pw, $pw_db);

	return $pwHash === $pw_db;
    }


    protected function login($username, $password)
    {
        assert(is_string($username));
        assert(is_string($password));

        $db = $this->connect();

        try {
            $sth = $db->prepare('SELECT username, password, name, domain, local_part FROM users WHERE username=:username');
        } catch (\PDOException $e) {
            throw new \Exception('sqlauth:'.$this->authId.
                ': - Failed to prepare query: '.$e->getMessage());
        }
        
        // if username is not specified as full email, append domain
        if ( !\isEmail($username) && isset($this->append_domain) ) {
    	    $username = $username.'@'.$this->append_domain;
        }

        try {
            $sth->execute(['username' => $username]);
        } catch (\PDOException $e) {
            throw new \Exception('sqlauth:'.$this->authId.
                ': - Failed to execute query: '.$e->getMessage());
        }

/*        try {
            $data = $sth->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            throw new \Exception('sqlauth:'.$this->authId.
                ': - Failed to fetch result set: '.$e->getMessage());
        }

        \SimpleSAML\Logger::info('sqlauth:'.$this->authId.': Got '.count($data).
            ' rows from database');

        if (count($data) === 0) {
            // No rows returned - invalid username/password
            \SimpleSAML\Logger::error('sqlauth:'.$this->authId.
                ': No rows in result set. Probably wrong username/password.');
            throw new \SimpleSAML\Error\Error('WRONGUSERPASS');
        }     */

        $row = $sth->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
	    // user not found
	    \SimpleSAML\Logger::error('sqlauth:'.$this->authId.
                ': No rows in result set. Probably wrong username/password.');
            throw new \SimpleSAML\Error\Error('WRONGUSERPASS');
        }
        
        // check password
        if (!$this->check_password($password, $row['password'])) {
    	    // Wrong pass
	    \SimpleSAML\Logger::error('sqlauth:'.$this->authId.
                ': No rows in result set. Probably wrong username/password.');
            throw new \SimpleSAML\Error\Error('WRONGUSERPASS');
        }

        /* Extract attributes. We allow the resultset to consist of multiple rows. Attributes
         * which are present in more than one row will become multivalued. null values and
         * duplicate values will be skipped. All values will be converted to strings.
         */

         // ignore this attributes from database
	$ignore_attributes = [
	    'password',
	];


        $attributes = [];
            foreach ($row as $name => $value) {
                if ($value === null) {
                    continue;
                }
                if (in_array($name, $ignore_attributes)) {
        	    continue;
                }

                $value = (string) $value;

                if (!array_key_exists($name, $attributes)) {
                    $attributes[$name] = [];
                }

                if (in_array($value, $attributes[$name], true)) {
                    // Value already exists in attribute
                    continue;
                }

                $attributes[$name][] = $value;
            }
            
        $attributes['userPrincipalName'][] =  (string) $row['username'];
/*        $attributes = [
	    'user' => [$username],
	    'name' => $row['name'],
	    'local_part' => $row['local_part'],
	    'domain' => $row['domain'],
	];*/

        \SimpleSAML\Logger::info('sqlauth:'.$this->authId.': Attributes: '.
            implode(',', array_keys($attributes)));

        return $attributes;
    }
}
