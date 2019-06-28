<?php
/**
 * This file is part of the mgrechanik/yii2-materialized-path library
 *
 * @copyright Copyright (c) Mikhail Grechanik <mike.grechanik@gmail.com>
 * @license https://github.com/mgrechanik/yii2-materialized-path/blob/master/LICENCE.md
 * @link https://github.com/mgrechanik/yii2-materialized-path
 */

namespace mgrechanik\yiimaterializedpath\tests;

/**
 * DbTestCase helps to create tests which need database
 * 
 * It also adds functionality to set fixtures in the database
 */
abstract class DbTestCase extends TestCase
{
    /**
     * Delete all rows from table
     * 
     * @param string $table The name of the table to purge
     */
    protected function cleanTable($table)
    {
        $db = \Yii::$app->db;
        
        $db->createCommand()->delete($table)->execute();        
    }
    
    /**
     * Populating table with a fixture
     * 
     * Format of the fixture file must be an array of arrays(rows), like this:
     *   return [
     *       [
     *           'column1' => 'row1-value1',
     *           'column2' => 'row1-value2',
     *       ],
     *       [
     *           'column1' => 'row2-value1',
     *           'column2' => 'row2-value2',
     *       ],    
     *   ];
     * 
     * @param string $table The name of the table
     * @param string $fixture The name of the fixture.
     * It is the name of fixture file (from fixture/ directory) without extension
     * @param boolean $doCleaning Clean the table before filling with fixture
     */
    protected function haveFixture($table, $fixture, $doCleaning = true)
    {
        $fixtureFile = __DIR__ . '/fixtures/' . $fixture . '.php';
        if (!file_exists($fixtureFile)) {
            throw new \Exception('fixture' . $fixture . 'does not exist');
        }
        
        if ($doCleaning) {
            $this->cleanTable($table);
        }
        
        $db = \Yii::$app->db;
        
        $data = require($fixtureFile);
        
        if (!is_array($data)) {
            throw new \Exception('fixture' . $fixture . 'has a wrong format');
        }
        
        foreach ($data as $row) {
            $db->createCommand()->insert($table, $row)->execute();
        }
    }
    
    /**
     * {@inheritdoc}
     */    
    protected function tearDown()
    {
        // We need this so it will not fall with Error - "Too many connections"
        \Yii::$app->db->close();
        parent::tearDown();
    }    
}