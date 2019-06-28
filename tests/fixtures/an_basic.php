<?php
/**
 * This file is part of the mgrechanik/yii2-materialized-path library
 *
 * @copyright Copyright (c) Mikhail Grechanik <mike.grechanik@gmail.com>
 * @license https://github.com/mgrechanik/yii2-materialized-path/blob/master/LICENCE.md
 * @link https://github.com/mgrechanik/yii2-materialized-path
 */

/**
 * This is the basic fixture for all examples.
 * It represents the next tree:
 * 
 * ROOT
 *   --- 1
 *       --- 5
 *           --- 7
 *       --- 6
 *   --- 2
 *   --- 3
 *       --- 8
 *       --- 9
 *   --- 4
 */
return [
    [
        'id' => 1,
        'path' => '',
        'level' => 1,
        'weight' => 1,
        'name' => 'cat',
    ],
    [
        'id' => 2,
        'path' => '',
        'level' => 1,
        'weight' => 2,
        'name' => 'dog',
    ],
    [
        'id' => 3,
        'path' => '',
        'level' => 1,
        'weight' => 3,
        'name' => 'snake',
    ],
    [
        'id' => 4,
        'path' => '',
        'level' => 1,
        'weight' => 4,
        'name' => 'bear',
    ],
    [
        'id' => 5,
        'path' => '1/',
        'level' => 2,
        'weight' => 1,
        'name' => 'mouse',
    ],
    [
        'id' => 6,
        'path' => '1/',
        'level' => 2,
        'weight' => 2,
        'name' => 'fox',
    ],
    [
        'id' => 7,
        'path' => '1/5/',
        'level' => 3,
        'weight' => 1,
        'name' => 'stag',
    ],
    [
        'id' => 8,
        'path' => '3/',
        'level' => 2,
        'weight' => 1,
        'name' => 'lion',
    ],
    [
        'id' => 9,
        'path' => '3/',
        'level' => 2,
        'weight' => 2,
        'name' => 'hedgehog',
    ],    
];

