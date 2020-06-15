<?php

namespace Simexis\Mongodb\Session;

use MongoDB\BSON\UTCDateTime;
use SessionHandlerInterface;
use Illuminate\Support\Carbon;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Support\InteractsWithTime;
use Illuminate\Contracts\Container\Container;
use MongoDb\Client;
use Throwable;

class MongoDbSessionHandler implements SessionHandlerInterface
{
    use InteractsWithTime;

    /**
     * The database connection instance.
     *
     * @var Client
     */
    protected $connection;

    /**
     * @var \MongoDB\Collection
     */
    private $collection;

    /**
     * The name of the session table.
     *
     * @var string
     */
    protected $table;

    /**
     * The name of the database.
     *
     * @var string
     */
    protected $database;

    /**
     * The number of minutes the session should be valid.
     *
     * @var int
     */
    protected $minutes;

    /**
     * The container instance.
     *
     * @var \Illuminate\Contracts\Container\Container
     */
    protected $container;

    /**
     * The existence state of the session.
     *
     * @var bool
     */
    protected $exists;

    /**
     * Create a new database session handler instance.
     *
     * @param  Client  $connection
     * @param  string  $database
     * @param  string  $table
     * @param  int  $minutes
     * @param  \Illuminate\Contracts\Container\Container|null  $container
     * @return void
     */
    public function __construct(Client $connection, $database, $table, $minutes, Container $container = null)
    {
        $this->table = $table;
        $this->database = $database;
        $this->minutes = $minutes;
        $this->container = $container;
        $this->connection = $connection;
    }

    /**
     * {@inheritdoc}
     */
    public function open($savePath, $sessionName)
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function close()
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function read($sessionId)
    {
        $session = $this->getCollection()->findOne([
            '_id' => $sessionId
        ]);

        if (null === $session) {
            return '';
        }

        if ($this->expired((object)(array)$session)) {
            return '';
        }

        return (string)$session['payload'];
    }

    /**
     * Determine if the session is expired.
     *
     * @param  \stdClass  $session
     * @return bool
     */
    protected function expired($session)
    {
        return isset($session->last_activity) &&
            (int)(string)$session->last_activity < Carbon::now()->subMinutes($this->minutes)->getTimestamp();
    }

    /**
     * {@inheritdoc}
     */
    public function write($sessionId, $data)
    {
        $fields = $this->getDefaultPayload($data);

        $this->getCollection()->updateOne(
            ['_id' => $sessionId],
            ['$set' => $fields],
            ['upsert' => true]
        );

        return true;
    }

    /**
     * Create expiration index.
     *
     * @return void
     */
    public function createExpireIndex()
    {
        try {
            $this->getCollection()
                ->createIndex(['expire' => 1], ['expireAfterSeconds' => 0]);
        } catch (Throwable $e) {}
    }

    /**
     * Get the default payload for the session.
     *
     * @param  string  $data
     * @return array
     */
    protected function getDefaultPayload($data)
    {
        $last_activity = $this->currentTime();

        $payload = [
            'payload' => $data,
            'last_activity' => new UTCDateTime($last_activity * 1000),
            'expire' => new UTCDateTime(($last_activity + ($this->minutes * 60)) * 1000)
        ];

        if (! $this->container) {
            return $payload;
        }

        return tap($payload, function (&$payload) {
            $this->addUserInformation($payload)
                ->addRequestInformation($payload);
        });
    }

    /**
     * Add the user information to the session payload.
     *
     * @param  array  $payload
     * @return $this
     */
    protected function addUserInformation(&$payload)
    {
        if ($this->container->bound(Guard::class)) {
            $payload['user_id'] = $this->userId();
        }

        return $this;
    }

    /**
     * Get the currently authenticated user's ID.
     *
     * @return mixed
     */
    protected function userId()
    {
        return $this->container->make(Guard::class)->id();
    }

    /**
     * Add the request information to the session payload.
     *
     * @param  array  $payload
     * @return $this
     */
    protected function addRequestInformation(&$payload)
    {
        if ($this->container->bound('request')) {
            $payload = array_merge($payload, [
                'ip_address' => $this->ipAddress(),
                'user_agent' => $this->userAgent(),
            ]);
        }

        return $this;
    }

    /**
     * Get the IP address for the current request.
     *
     * @return string
     */
    protected function ipAddress()
    {
        return $this->container->make('request')->ip();
    }

    /**
     * Get the user agent for the current request.
     *
     * @return string
     */
    protected function userAgent()
    {
        return substr((string) $this->container->make('request')->header('User-Agent'), 0, 500);
    }

    /**
     * {@inheritdoc}
     */
    public function destroy($sessionId)
    {
        $this->getCollection()->deleteOne([
            '_id' => $sessionId,
        ]);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function gc($lifetime)
    {
        /*$this->getCollection()->deleteMany([
            'last_activity' => ['$lt' => new \MongoDB\BSON\UTCDateTime( ($this->currentTime() - $lifetime) * 1000 )],
        ]);*/

        return true;
    }

    /**
     * @return \MongoDB\Collection
     */
    protected function getCollection()
    {
        if (null === $this->collection) {
            $this->collection = $this->getMongo()->selectCollection($this->database, $this->table);
        }

        return $this->collection;
    }

    /**
     * @return \MongoDB\Client
     */
    protected function getMongo()
    {
        return $this->connection;
    }
}
