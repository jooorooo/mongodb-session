<?php namespace Simexis\Mongodb\Session;

//use Symfony\Component\HttpFoundation\Session\Storage\Handler\MongoDbSessionHandler;

use Illuminate\Session\SessionManager AS IlluminateSessionManager;

class SessionManager extends IlluminateSessionManager
{
    /**
     * Create an instance of the database session driver.
     *
     * @return \Illuminate\Session\Store
     */
    protected function createMongoDBDriver()
    {
        $connection = $this->getMongoDBConnection();

        $collection = $this->app['config']['session.table'];

        $database = (string) $connection->getMongoDB();

        $lifetime = $this->app['config']['session.lifetime'];

        return new MongoDbSessionHandler($connection->getMongoClient(), $database, $collection, $lifetime, $this->app);
    }

    /**
     * Get the database connection for the MongoDB driver.
     *
     * @return Connection
     */
    protected function getMongoDBConnection()
    {
        $connection = $this->app['config']['session.connection'];

        // The default connection may still be mysql, we need to verify if this connection
        // is using the mongodb driver.
        if (is_null($connection)) {
            $default = $this->app['db']->getDefaultConnection();

            $connections = $this->app['config']['database.connections'];

            // If the default database driver is not mongodb, we will loop the available
            // connections and select the first one using the mongodb driver.
            if ($connections[$default]['driver'] != 'mongodb') {
                foreach ($connections as $name => $candidate) {
                    // Check the driver
                    if ($candidate['driver'] == 'mongodb') {
                        $connection = $name;
                        break;
                    }
                }
            }
        }

        return $this->app['db']->connection($connection);
    }

}
