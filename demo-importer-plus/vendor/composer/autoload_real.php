<?php

// autoload_real.php @generated by Composer

class ComposerAutoloaderInitf035a643c69cde29e6a6ca28f50ffecc
{
    private static $loader;

    public static function loadClassLoader($class)
    {
        if ('Composer\Autoload\ClassLoader' === $class) {
            require __DIR__ . '/ClassLoader.php';
        }
    }

    /**
     * @return \Composer\Autoload\ClassLoader
     */
    public static function getLoader()
    {
        if (null !== self::$loader) {
            return self::$loader;
        }

        require __DIR__ . '/platform_check.php';

        spl_autoload_register(array('ComposerAutoloaderInitf035a643c69cde29e6a6ca28f50ffecc', 'loadClassLoader'), true, true);
        self::$loader = $loader = new \Composer\Autoload\ClassLoader(\dirname(__DIR__));
        spl_autoload_unregister(array('ComposerAutoloaderInitf035a643c69cde29e6a6ca28f50ffecc', 'loadClassLoader'));

        require __DIR__ . '/autoload_static.php';
        call_user_func(\Composer\Autoload\ComposerStaticInitf035a643c69cde29e6a6ca28f50ffecc::getInitializer($loader));

        $loader->register(true);

        return $loader;
    }
}
