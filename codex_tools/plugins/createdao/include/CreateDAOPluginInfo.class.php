<?php


/**
 * Copyright (c) Xerox Corporation, CodeX Team, 2001-2005. All rights reserved
 * 
 * 
 *
 * CreateDAOPluginInfo
 */
require_once('common/plugin/PluginInfo.class.php');
require_once('common/include/PropertyDescriptor.class.php');
require_once('CreateDAOPluginDescriptor.class.php');

class CreateDAOPluginInfo extends PluginInfo {
    
    var $_conf_path;
    
    function CreateDAOPluginInfo(&$plugin) {
        $this->PluginInfo($plugin);
        $this->setPluginDescriptor(new CreateDAOPluginDescriptor());
        $this->_conf_path = dirname(__FILE__)."/CreateDAO.properties";
        $this->loadProperties();
    }
    
    
    function loadProperties() {
        if (file_exists($this->_conf_path)) {
            $props = parse_ini_file($this->_conf_path);
            foreach($props as $prop => $value) {
                $key = $pro;
                $descriptor =& new PropertyDescriptor($key, $value);
                $this->_addPropertyDescriptor($descriptor);
            }
        }
    }
    
    function saveProperties() {
        copy($this->_conf_path, $this->_conf_path.'.old');
        $f = fopen($this->_conf_path, 'w');
        if ($f) {
            $descs =& $this->getPropertyDescriptors();
            $keys  =& $descs->getKeys();
            $iter  =& $keys->iterator();
            while($iter->valid()) {
                $key   =& $iter->current();
                $desc  =& $descs->get($key);
                $desc_name =& $desc->getName();
                fwrite($f, sprintf("%s = %s\n", $desc_name->getInternalString(), $desc->getValue()));
                $iter->next();
            }
            fclose($f);
        }
    }
    
    function getPropertyValueForName($name) {
        $desc =& $this->getPropertyDescriptorForName($name);
        return $desc->getValue();
    }
}
?>