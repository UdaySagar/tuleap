<?php
/**
* Server
* 
* A server is a physical box that serves CodeX.
* 
* Copyright (c) Xerox Corporation, CodeX Team, 2001-2007. All rights reserved
*
* @author  N. Terray
*/
class Server {
    
    var $id;
	var $name;
    var $description;
    var $http;
    var $https;
    /**
    * Constructor
    */
    function Server($data = array()) {
        $this->id          = isset($data['id'])          ? $data['id']          : null;
        $this->name        = isset($data['name'])        ? $data['name']        : '';
        $this->description = isset($data['description']) ? $data['description'] : '';
        $this->http        = isset($data['http'])        ? $data['http']        : '';
        $this->https       = isset($data['https'])       ? $data['https']       : '';
    }
    function getId() {
        return $this->id;
    }
    function getName() {
        return $this->name;
    }
    function getDescription() {
        return $this->description;
    }
    function getHttp() {
        return $this->http;
    }
    function getHttps() {
        return $this->https;
    }
    function getUrl($secure) {
        return $this->https && ($secure || !$this->http) ? $this->https : $this->http;
    }
}
?>
