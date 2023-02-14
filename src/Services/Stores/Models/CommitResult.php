<?php

namespace TorqIT\StoreSyndicatorBundle\Services\Stores\Models;

use Pimcore\Model\DataObject\Concrete;

class CommitResult{

    /**
     * @var Concrete[] $updated 
     */
    private array $updated;
    
    /**
     * @var Concrete[] $created 
     */
    private array $created;

    public function __construct(){
        $this->updated = array();
        $this->created = array();
    }

    public function addCreated(Concrete $object){
        $this->created[] = $object;
    }

    public function addUpdated(Concrete $object){
        $this->updated[] = $object;
    }

    public function getCreated(){
        return $this->created;
    }

    public function getUpdated(){
        return $this->updated;
    }
}