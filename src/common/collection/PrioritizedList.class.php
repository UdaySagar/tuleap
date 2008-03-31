<?php
require_once('LinkedList.class.php');

/**
 * Copyright (c) Xerox Corporation, CodeX Team, 2001-2005. All rights reserved
 * 
 * 
 *
 * PrioritizedList
 */
class PrioritizedList extends LinkedList{
    var $priorities;
    function PrioritizedList($initial_array = '') {
        $this->LinkedList($initial_array);
        $this->priorities = array();
        if (count($this->elements)) {
            $this->priorities[] = array_keys(array_fill(0, count($this->elements), 0));
        }
    }
    
    /**
     * add the element add the end of the PrioritizedList
     */
    function add($element, $priority = 0) {
        $this->elements[] = $element;
        $this->priorities[$priority][] = count($this->elements) - 1;
    }
    
    function iterator() {
        $tab = array();
        krsort($this->priorities);
        foreach($this->priorities as $elements) {
            foreach($elements as $position) {
                $tab[] = $this->elements[$position];
            }
        }
        $it = new ArrayIterator($tab);
        return $it;
    }
}
?>