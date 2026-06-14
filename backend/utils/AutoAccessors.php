<?php

trait AutoAccessors {
    public function __call($method, $args) {
        // Handle getters
        if (strpos($method, 'get') === 0) {
            $property = lcfirst(substr($method, 3));
            if (property_exists($this, $property)) {
                return $this->$property;
            }
        }

        // Handle setters
        if (strpos($method, 'set') === 0) {
            $property = lcfirst(substr($method, 3));
            if (property_exists($this, $property)) {
                $this->$property = $args[0];
                return $this; // allow chaining
            }
        }

        throw new BadMethodCallException("Method $method does not exist");
    }
}
