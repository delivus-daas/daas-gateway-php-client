<?php namespace delivus\clients\utils;

abstract class Singleton {
    private static array $instances;

    protected function __construct() {}

    protected function __clone() {}

    public function __wakeup() {
        throw new \Exception("Cannot deserialize a Singleton.");
    }

    public static function getInstance(): Singleton {
        $cls = static::class;
        if (!isset(self::$instances[$cls])) {
            self::$instances[$cls] = new static();
        }
        return self::$instances[$cls];
    }
}
