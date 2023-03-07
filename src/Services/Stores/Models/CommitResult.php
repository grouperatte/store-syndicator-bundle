<?php

namespace TorqIT\StoreSyndicatorBundle\Services\Stores\Models;

use Pimcore\Model\DataObject\Concrete;

class CommitResult
{

    /**
     * @var Concrete[] $updated 
     */
    private array $updated;

    /**
     * @var Concrete[] $created 
     */
    private array $created;

    /** @var String[] $errors */
    private array $errors;

    public function __construct()
    {
        $this->updated = array();
        $this->created = array();
        $this->errors = array();
    }

    public function addCreated(Concrete $object)
    {
        $this->created[] = $object;
    }

    public function addUpdated(Concrete $object)
    {
        $this->updated[] = $object;
    }

    public function getCreated()
    {
        return $this->created;
    }

    public function getUpdated()
    {
        return $this->updated;
    }

    /**
     * Set the value of errors
     *
     * @param string $error
     */
    public function addError(string $error)
    {
        $this->errors[] = $error;
    }

    /**
     * Get the value of errors
     *
     * @return array
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
