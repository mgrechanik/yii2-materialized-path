<?php
/**
 * This file is part of the mgrechanik/yii2-materialized-path library
 *
 * @copyright Copyright (c) Mikhail Grechanik <mike.grechanik@gmail.com>
 * @license https://github.com/mgrechanik/yii2-materialized-path/blob/master/LICENCE.md
 * @link https://github.com/mgrechanik/yii2-materialized-path
 */

namespace mgrechanik\yiimaterializedpath\tests;

use mgrechanik\yiimaterializedpath\tests\models\Menuitem;

/**
 * Testing MaterializedPathBehavior when it works with Menuitem AR model,
 * model for `menuitem` table which holds many trees
 */
class MenuitemTest extends DbTestCase
{
    
    protected function setUp()
    {
        parent::setUp();
        $this->haveFixture('menuitem', 'mi_basic');
    }
    
    public function testTreeCondition()
    {
        $model = Menuitem::findOne(1);
        $this->assertEquals(['treeid' => 1], $model->getTreeCondition());
        
        $model = Menuitem::findOne(9);
        $this->assertEquals(['treeid' => 2], $model->getTreeCondition());        
    } 
    
    public function testNewRecordsCorrectTreeCondition()
    {
        $model = new Menuitem(['treeid' => 1, 'name' => 'name']);
        $this->assertEquals(['treeid' => 1], $model->getTreeCondition());
    }     

    /**
     * @expectedException LogicException
     */    
    public function testNewRecordsNotFilledTreeCondition()
    {
        $model = new Menuitem(['name' => 'name']);
        $model->getTreeCondition();
    } 
    
    public function testCorrectRootNode()
    {
        $model = Menuitem::findOne(6);
        $root = $model->getRoot();
        $this->assertIsObject($root);
        $this->assertAttributeEquals(Menuitem::class, 'className', $root);
        $this->assertAttributeEquals(['treeid' => 1], 'treeCondition', $root);
        $this->assertEquals(['treeid'], $root->treeIdentityFields);
    }     
    
    public function testSameRootNode()
    {
        $model1 = Menuitem::findOne(1);
        $root1 = $model1->getRoot();
        $model2 = Menuitem::findOne(6);
        $root2 = $model2->getRoot();        
        $this->assertSame($root1, $root2);
    }       
    
    public function testNotSameRootNode()
    {
        $model1 = Menuitem::findOne(1);
        $root1 = $model1->getRoot();
        $model2 = Menuitem::findOne(9);
        $root2 = $model2->getRoot();        
        $this->assertNotSame($root1, $root2);
    } 

    public function testGetQuery()
    {
        if (!(\Yii::$app->db->schema instanceof \yii\db\mysql\Schema)) {
            $this->markTestSkipped();
        }
        $model = Menuitem::findOne(1);
        $root = $model->getRoot();
        $query1 = $model->getQuery();
        $query2 = $root->getQuery();
        $this->assertNotSame($query1, $query2);
        $this->assertSame('SELECT * FROM `menuitem` WHERE `treeid`=1', $query1->createCommand()->getRawSql());
        $this->assertSame('SELECT * FROM `menuitem` WHERE `treeid`=1', $query2->createCommand()->getRawSql());
    } 
    
    // getDescendantsQuery
    
    public function testFirstRootCountDescendantsQuery()
    {
        $model = Menuitem::findOne(1);
        $root = $model->getRoot();
        $query = $root->getDescendantsQuery();
        $this->assertEquals(6, count($query->all()));
    }     
    
    public function testSecondRootCountDescendantsQuery()
    {
        $model = Menuitem::findOne(7);
        $root = $model->getRoot();
        $query = $root->getDescendantsQuery();
        $this->assertEquals(3, count($query->all()));
    } 
    
    public function testNotRootCountDescendantsQuery()
    {
        $model = Menuitem::findOne(1);
        $query = $model->getDescendantsQuery();
        $this->assertEquals(2, count($query->all()));
    }  
    
    // end getDescendantsQuery
    
    public function testRootCountChildrenQuery()
    {
        $model = Menuitem::findOne(7);
        $root = $model->getRoot();
        $query = $root->getChildrenQuery();
        $this->assertEquals(2, count($query->all()));
    } 
    
    public function testNotRootCountChildrenQuery()
    {
        $model = Menuitem::findOne(2);
        $query = $model->getChildrenQuery();
        $this->assertEquals(1, count($query->all()));
    }  
    
    public function testCheckForWrongRootDescendantOf()
    {
        $model1 = Menuitem::findOne(5);
        $root1 = $model1->getRoot();
        $model2 = Menuitem::findOne(9);
        $root2 = $model2->getRoot(); 
        
        $this->assertTrue($model1->isDescendantOf($root1));
        $this->assertTrue($model1->isDescendantOf($root1->getId()));
        $this->assertFalse($model1->isDescendantOf($root2));
        $this->assertFalse($model1->isDescendantOf($root2->getId()));
    }
    
    //// modifications
    
    public function testCorrectTreeWhenNewNodeAppendTo()
    {
        $service = \Yii::createObject(\mgrechanik\yiimaterializedpath\ServiceInterface::class);
        $root = $service->getRoot(Menuitem::class, ['treeid' => 1]);
        
        $model = new Menuitem(['name' => 'new']);
        
        $this->assertTrue($model->appendTo($root));
        $this->assertSame($root, $model->getRoot());
        $this->assertEquals(1, $model->treeid);
        $this->assertSame('', $model->path);
        $this->assertEquals(1, $model->level);
        $this->assertEquals(4, $model->weight);        
    }
    
    public function testCorrectNewTreeWhenNewNodeAppendTo()
    {
        $service = \Yii::createObject(\mgrechanik\yiimaterializedpath\ServiceInterface::class);
        // new tree
        $root = $service->getRoot(Menuitem::class, ['treeid' => 3]);
        
        $model = new Menuitem(['name' => 'new']);
        
        $this->assertTrue($model->appendTo($root));
        $this->assertSame($root, $model->getRoot());
        $this->assertEquals(3, $model->treeid);
        $this->assertSame('', $model->path);
        $this->assertEquals(1, $model->level);
        $this->assertEquals(1, $model->weight);        
    }    
    
    public function testAddNewNodeToNotRoot()
    {
        $parent = Menuitem::findOne(1);
        
        $model = new Menuitem(['name' => 'new']);
        
        $this->assertTrue($model->appendTo($parent));
        $this->assertEquals(1, $model->treeid);
        $this->assertSame('1/', $model->path);
        $this->assertEquals(2, $model->level);
        $this->assertEquals(3, $model->weight);        
    } 
    
    public function testMoveToDifferentTree()
    {
        $model4 = Menuitem::findOne(4);
        $model7 = Menuitem::findOne(7);
        
        $this->expectExceptionMessage('You are not allowed to move node to another tree');
        
        $model4->appendTo($model7);
    }


    //// end modifications

}