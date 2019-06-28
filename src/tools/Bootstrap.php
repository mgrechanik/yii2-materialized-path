<?php
/**
 * This file is part of the mgrechanik/yii2-materialized-path library
 *
 * @copyright Copyright (c) Mikhail Grechanik <mike.grechanik@gmail.com>
 * @license https://github.com/mgrechanik/yii2-materialized-path/blob/master/LICENCE.md
 * @link https://github.com/mgrechanik/yii2-materialized-path
 */

namespace mgrechanik\yiimaterializedpath\tools;

use yii\base\BootstrapInterface;

/**
 * Bootstrap class for yii2 materialized path extension
 */
class Bootstrap implements BootstrapInterface
{
    public function bootstrap($app)
    {
        \Yii::$container->setSingleton(
            mgrechanik\yiimaterializedpath\ServiceInterface::class, 
            mgrechanik\yiimaterializedpath\Service::class
        );
    }
}

