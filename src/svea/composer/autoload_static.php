<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit34a6ba304e87666b2bd112e79416bc25
{
    public static $prefixLengthsPsr4 = array (
        'S' => 
        array (
            'Svea\\WebPay\\' => 12,
            'Svea\\Checkout\\' => 14,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Svea\\WebPay\\' => 
        array (
            0 => __DIR__ . '/../../..' . '/sveaekonomi/webpay/src',
            1 => __DIR__ . '/..' . '/sveaekonomi/webpay/src',
        ),
        'Svea\\Checkout\\' => 
        array (
            0 => __DIR__ . '/../../..' . '/sveaekonomi/checkout/src',
            1 => __DIR__ . '/..' . '/sveaekonomi/checkout/src',
        ),
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInit34a6ba304e87666b2bd112e79416bc25::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInit34a6ba304e87666b2bd112e79416bc25::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInit34a6ba304e87666b2bd112e79416bc25::$classMap;

        }, null, ClassLoader::class);
    }
}
