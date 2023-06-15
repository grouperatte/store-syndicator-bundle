<?php

namespace TorqIT\StoreSyndicatorBundle\Services\Stores\Models;

class LogRow
{

    private string $label;

    private string $log;

    public function __construct(string $label, string $log)
    {
        $this->label = $label;
        $this->log = $log;
    }

    /**
     * Get the value of label
     *
     * @return string
     */
    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * Get the value of log
     *
     * @return string
     */
    public function getLog(): string
    {
        return $this->log;
    }

    public function generateRow(): array
    {
        return ["comment" => $this->label, "log" => $this->log];
    }
}
