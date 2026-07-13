<?php

namespace App\Cache;

use Illuminate\Contracts\Cache\Store;
use Illuminate\Contracts\Cache\LockProvider;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\BSON\UTCDateTime;
use MongoDB\BSON\ObjectId;

class MongoDbStore implements Store
{
    /**
     * @var Collection
     */
    protected $collection;

    /**
     * @var string
     */
    protected $prefix;

    /**
     * MongoDbStore constructor.
     *
     * @param string $uri
     * @param string $database
     * @param string $collectionName
     * @param array $options
     * @param string $prefix
     */
    public function __construct(string $uri, string $database = 'jikan', string $collectionName = 'cache', array $options = [], string $prefix = '')
    {
        $client = new Client($uri, $options);
        $this->collection = $client->selectCollection($database, $collectionName);

        // Create TTL index for automatic expiry (60 days max to be safe)
        try {
            $this->collection->createIndex(
                ['expires_at' => 1],
                ['expireAfterSeconds' => 0, 'background' => true]
            );
            // Also index by key for faster lookups
            $this->collection->createIndex(
                ['key' => 1],
                ['background' => true]
            );
        } catch (\Exception $e) {
            // Index may already exist, ignore
        }

        $this->prefix = $prefix;
    }

    /**
     * Retrieve an item from the cache by key.
     *
     * @param  string  $key
     * @return mixed
     */
    public function get($key)
    {
        $prefixedKey = $this->prefix . $key;
        $document = $this->collection->findOne(
            ['key' => $prefixedKey],
            ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]
        );

        if ($document === null) {
            return null;
        }

        // Check if expired (TTL index handles this, but double-check for edge cases)
        if (isset($document['expires_at']) && $document['expires_at'] instanceof UTCDateTime) {
            if ($document['expires_at']->toDateTime() < new \DateTime()) {
                $this->forget($key);
                return null;
            }
        }

        return $document['value'] ?? null;
    }

    /**
     * Store an item in the cache for a given number of seconds.
     *
     * @param  string  $key
     * @param  mixed   $value
     * @param  int     $seconds
     * @return bool
     */
    public function put($key, $value, $seconds)
    {
        $prefixedKey = $this->prefix . $key;

        $expiresAt = null;
        if ($seconds > 0) {
            $expiresAt = new UTCDateTime((time() + $seconds) * 1000);
        }

        $this->collection->updateOne(
            ['key' => $prefixedKey],
            ['$set' => [
                'key' => $prefixedKey,
                'value' => $value,
                'expires_at' => $expiresAt,
                'updated_at' => new UTCDateTime(time() * 1000),
            ]],
            ['upsert' => true]
        );

        return true;
    }

    /**
     * Increment the value of an item in the cache.
     *
     * @param  string  $key
     * @param  mixed   $value
     * @return int|bool
     */
    public function increment($key, $value = 1)
    {
        $prefixedKey = $this->prefix . $key;
        $current = $this->get($key);

        if ($current === null) {
            $this->put($key, $value, 0);
            return $value;
        }

        $newValue = (int) $current + $value;
        $this->put($key, $newValue, 0);

        return $newValue;
    }

    /**
     * Decrement the value of an item in the cache.
     *
     * @param  string  $key
     * @param  mixed   $value
     * @return int|bool
     */
    public function decrement($key, $value = 1)
    {
        return $this->increment($key, -$value);
    }

    /**
     * Store an item in the cache indefinitely.
     *
     * @param  string  $key
     * @param  mixed   $value
     * @return bool
     */
    public function forever($key, $value)
    {
        $prefixedKey = $this->prefix . $key;

        // Store with far future expiry (10 years)
        $expiresAt = new UTCDateTime((time() + 315360000) * 1000);

        $this->collection->updateOne(
            ['key' => $prefixedKey],
            ['$set' => [
                'key' => $prefixedKey,
                'value' => $value,
                'expires_at' => $expiresAt,
                'updated_at' => new UTCDateTime(time() * 1000),
            ]],
            ['upsert' => true]
        );

        return true;
    }

    /**
     * Remove an item from the cache.
     *
     * @param  string  $key
     * @return bool
     */
    public function forget($key)
    {
        $prefixedKey = $this->prefix . $key;
        $result = $this->collection->deleteOne(['key' => $prefixedKey]);

        return $result->getDeletedCount() > 0;
    }

    /**
     * Remove all items from the cache.
     *
     * @return bool
     */
    public function flush()
    {
        if ($this->prefix) {
            $this->collection->deleteMany(['key' => ['$regex' => '^' . preg_quote($this->prefix, '/')]]);
        } else {
            $this->collection->deleteMany([]);
        }

        return true;
    }

    /**
     * Get the cache key prefix.
     *
     * @return string
     */
    public function getPrefix()
    {
        return $this->prefix;
    }

    /**
     * Check if an item exists in the cache.
     *
     * @param  string  $key
     * @return bool
     */
    public function has($key)
    {
        $prefixedKey = $this->prefix . $key;
        $count = $this->collection->countDocuments(
            [
                'key' => $prefixedKey,
                '$or' => [
                    ['expires_at' => null],
                    ['expires_at' => ['$gte' => new UTCDateTime(time() * 1000)]],
                ]
            ]
        );

        return $count > 0;
    }

    /**
     * Retrieve multiple items from the cache by key.
     *
     * @param  array  $keys
     * @return array
     */
    public function many(array $keys)
    {
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->get($key);
        }
        return $results;
    }

    /**
     * Store multiple items in the cache for a given number of seconds.
     *
     * @param  array  $values
     * @param  int  $seconds
     * @return bool
     */
    public function putMany(array $values, $seconds)
    {
        foreach ($values as $key => $value) {
            $this->put($key, $value, $seconds);
        }
        return true;
    }
}