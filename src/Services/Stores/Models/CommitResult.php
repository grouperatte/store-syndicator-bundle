<?php

namespace TorqIT\StoreSyndicatorBundle\Services\Stores\Models;

use Pimcore\Model\DataObject\Concrete;
use TorqIT\StoreSyndicatorBundle\Services\Stores\Models\LogRow;

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

    /** @var LogRow[] $errors */
    private array $errors;

    /** @var LogRow[] $logs */
    private array $logs;

    public function __construct()
    {
        $this->updated = [];
        $this->created = [];
        $this->errors = [];
        $this->logs = [];
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
     * Add a error
     *
     * @param LogRow $error
     */
    public function addError(LogRow $error)
    {
        $this->errors[] = $error;
    }

    /**
     * Get the value of errors
     *
     * @return LogRow[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Add a logs
     *
     * @param LogRow $logs
     */
    public function addLog(LogRow $log)
    {
        $this->logs[] = $log;
    }

    /**
     * Get the value of logs
     *
     * @return LogRow[]
     */
    public function getLogs(): array
    {
        return $this->logs;
    }
}
