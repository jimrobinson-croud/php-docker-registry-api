<?php
namespace CroudTech\DockerRegistryApi\RegistryObjects;

use CroudTech\DockerRegistryApi\Api\Client as ApiClient;
use ArrayAccess;

abstract class Base implements ArrayAccess
{
    public $attributes = [];

    /**
     * Constructor
     *
     * @param array $attributes
     * @param ClientInterface $client
     */
    public function __construct(array $attributes, ApiClient $client)
    {
        $this->attributes = $attributes;
    }

    /**
     * Undocumented function
     *
     * @return void
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     *
     * @param $offset string | int
     */
    public function offsetExists($offset) : boolean
    {
        return isset($this->attributes[$offset]);
    }

    /**
     * Implement offsetGet
     *
     * @param mixed $offset
     * @return mixed
     */
    public function offsetGet($offset)
    {
        return $this->attributes[$offset];
    }

    /**
     * Implement offsetSet
     *
     * @param mixed $offset
     * @param mixed $value
     * @return void
     */
    public function offsetSet($offset, $value)
    {
        $this->attributes[$offset] = $value;
    }

    /**
     * Implement offsetUnset
     *
     * @param mixed $offset
     * @return void
     */
    public function offsetUnset($offset)
    {
        unset($this->attributes[$offset]);
    }

}