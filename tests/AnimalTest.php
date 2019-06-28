<?php
/**
 * This file is part of the mgrechanik/yii2-materialized-path library
 *
 * @copyright Copyright (c) Mikhail Grechanik <mike.grechanik@gmail.com>
 * @license https://github.com/mgrechanik/yii2-materialized-path/blob/master/LICENCE.md
 * @link https://github.com/mgrechanik/yii2-materialized-path
 */

namespace mgrechanik\yiimaterializedpath\tests;

use mgrechanik\yiimaterializedpath\tests\models\Animal;
use mgrechanik\yiimaterializedpath\tools\RootNode;

/**
 * Testing MaterializedPathBehavior when it works with Animal AR model,
 * model for `animal` table which holds only one tree
 */
class AnimalTest extends DbTestCase
{
    /**
     * {@inheritdoc}
     */    
    protected function setUp()
    {
        parent::setUp();
        $this->haveFixture('animal', 'an_basic');
    }
    
    /**
     * @expectedException LogicException
     */
    public function testNewRecordDoesNotHaveRootNode()
    {
        $model = new Animal();
        $root = $model->getRoot();
    }
    
    public function testTreeCondition()
    {
        $model = Animal::findOne(1);
        $this->assertEquals([], $model->getTreeCondition());
    }    
    
    public function testCorrectRootNode()
    {
        $model = Animal::findOne(1);
        $root = $model->getRoot();
        $this->assertIsObject($root);
        $this->assertAttributeEquals(Animal::class, 'className', $root);
        $this->assertAttributeEquals([], 'treeCondition', $root);
    }   
    
    public function testSameRootNode()
    {
        $model1 = Animal::findOne(1);
        $root1 = $model1->getRoot();
        $model2 = Animal::findOne(9);
        $root2 = $model2->getRoot();        
        $this->assertSame($root1, $root2);
    }         
    
    public function testIsRootForNewModels()
    {
        $model = new Animal();
        $this->assertFalse($model->isRoot());
    }     

    public function testIsRootForNotRootModels()
    {
        $model = Animal::findOne(1);
        $this->assertFalse($model->isRoot());
    }      
    
    public function testIsRootForRootNode()
    {
        $model = Animal::findOne(1);
        $root = $model->getRoot();
        $this->assertTrue($root->isRoot());
    }      
    
    public function testSameGetRootForRootNode()
    {
        $model = Animal::findOne(1);
        $root = $model->getRoot();
        $this->assertSame($root, $root->getRoot());
    } 

    public function testFullPath()
    {
        $model = Animal::findOne(1);
        $root = $model->getRoot();
        $model2 = Animal::findOne(7);
        
        $this->assertSame('1/', $model->getFullPath());
        $this->assertSame('', $root->getFullPath());
        $this->assertSame('1/5/7/', $model2->getFullPath());
    }     
    
    public function testLevel()
    {
        $model = Animal::findOne(1);
        $root = $model->getRoot();
        $model2 = Animal::findOne(7);
        
        $this->assertSame(1, $model->getLevel());
        $this->assertSame(0, $root->getLevel());
        $this->assertSame(3, $model2->getLevel());
    }  
    
    public function testGetQuery()
    {
        if (!(\Yii::$app->db->schema instanceof \yii\db\mysql\Schema)) {
            $this->markTestSkipped();
        }        
        $model = Animal::findOne(1);
        $root = $model->getRoot();
        $query1 = $model->getQuery();
        $query2 = $root->getQuery();
        $this->assertNotSame($query1, $query2);
        $this->assertSame('SELECT * FROM `animal`', $query1->createCommand()->getRawSql());
        $this->assertSame('SELECT * FROM `animal`', $query2->createCommand()->getRawSql());
    }
    
    // getDescendantsQuery
    
    public function testRootGetDescendantsQuery()
    {
        if (!(\Yii::$app->db->schema instanceof \yii\db\mysql\Schema)) {
            $this->markTestSkipped();
        }         
        $model = Animal::findOne(1);
        $root = $model->getRoot();
        $query = $root->getDescendantsQuery();
        $this->assertSame(
            'SELECT * FROM `animal` ORDER BY `level`, `weight`', 
            $query->createCommand()->getRawSql()
        );
    }
    
    public function testRootCountDescendantsQuery()
    {
        $model = Animal::findOne(1);
        $root = $model->getRoot();
        $query = $root->getDescendantsQuery();
        $this->assertEquals(9, count($query->all()));
    } 
    
    public function testNotRootGetDescendantsQuery()
    {
        if (!(\Yii::$app->db->schema instanceof \yii\db\mysql\Schema)) {
            $this->markTestSkipped();
        }         
        $model = Animal::findOne(3);
        $query = $model->getDescendantsQuery();
        $this->assertSame(
            "SELECT * FROM `animal` WHERE `path` LIKE '3/%' ORDER BY `level`, `weight`", 
            $query->createCommand()->getRawSql()
        );
    }  
    
    public function testNotRootCountDescendantsQuery()
    {
        $model = Animal::findOne(3);
        $query = $model->getDescendantsQuery();
        $this->assertCount(2, $query->all());
    } 
    
    public function testGetDescendantsQueryExcludeIdsRootWithoutFirstLevelNode()
    {
        if (!(\Yii::$app->db->schema instanceof \yii\db\mysql\Schema)) {
            $this->markTestSkipped();
        }         
        $model1 = Animal::findOne(1);
        $root = $model1->getRoot();
        $query = $root->getDescendantsQuery([1]);
        
        $this->assertSame(
            "SELECT * FROM `animal` WHERE (`id` != 1) AND (`path` NOT LIKE '1/%') ORDER BY `level`, `weight`", 
            $query->createCommand()->getRawSql()
        );
        $this->assertCount(5, $query->all());       
    } 
    
    public function testGetDescendantsQueryExcludeIdsRootWithoutNotFirstLevelNode()
    {
        if (!(\Yii::$app->db->schema instanceof \yii\db\mysql\Schema)) {
            $this->markTestSkipped();
        }         
        $model5 = Animal::findOne(5);
        $root = $model5->getRoot();
        $query = $root->getDescendantsQuery([5]);

        $this->assertSame(
            "SELECT * FROM `animal` WHERE (`id` != 5) AND (`path` NOT LIKE '1/5/%') ORDER BY `level`, `weight`", 
            $query->createCommand()->getRawSql()
        );
        $this->assertCount(7, $query->all());
    }   
    
    public function testGetDescendantsQueryExcludeIdsNotRootWithoutNode()
    {
        if (!(\Yii::$app->db->schema instanceof \yii\db\mysql\Schema)) {
            $this->markTestSkipped();
        }         
        $model1 = Animal::findOne(1);
        $query = $model1->getDescendantsQuery([5]);
        
        $this->assertSame(
            "SELECT * FROM `animal` WHERE (`path` LIKE '1/%') AND (`id` != 5) AND (`path` NOT LIKE '1/5/%') ORDER BY `level`, `weight`", 
            $query->createCommand()->getRawSql()
        );
        $this->assertCount(1, $query->all());
    } 

    public function testGetDescendantsQueryExcludeOnlyDescendants()
    {
        if (!(\Yii::$app->db->schema instanceof \yii\db\mysql\Schema)) {
            $this->markTestSkipped();
        }         
        $model5 = Animal::findOne(5);
        $root = $model5->getRoot();
        $query = $root->getDescendantsQuery([4, 3 => false]);

        $this->assertSame(
            "SELECT * FROM `animal` WHERE (`id` != 4) AND (`path` NOT LIKE '4/%') AND (`path` NOT LIKE '3/%') ORDER BY `level`, `weight`", 
            $query->createCommand()->getRawSql()
        );
        $this->assertCount(6, $query->all());
    }    
    
    public function testGetDescendantsQueryDepthOne()
    {
        $model1 = Animal::findOne(1);
        $root = $model1->getRoot();
        
        $query1 = $root->getDescendantsQuery([], 1);
        $query2 = $model1->getDescendantsQuery([], 1);

        $this->assertCount(4, $query1->all());
        $this->assertCount(2, $query2->all());
    } 
    
    public function testGetDescendantsQueryDepthTwo()
    {
        $model1 = Animal::findOne(1);
        $root = $model1->getRoot();
        
        $query1 = $root->getDescendantsQuery([], 2);
        $query2 = $model1->getDescendantsQuery([], 2);

        $this->assertCount(8, $query1->all());
        $this->assertCount(3, $query2->all());
    } 
    
    // end getDescendantsQuery

    public function testRootgetChildrenQuery()
    {
        if (!(\Yii::$app->db->schema instanceof \yii\db\mysql\Schema)) {
            $this->markTestSkipped();
        }         
        $model = Animal::findOne(1);
        $root = $model->getRoot();
        $query = $root->getChildrenQuery();
        
        $this->assertSame(
            "SELECT * FROM `animal` WHERE `path`='' ORDER BY `weight`", 
            $query->createCommand()->getRawSql()
        );        
        $this->assertCount(4, $query->all());
    }
    
    public function testRootgetChildrenQueryDesc()
    {
        if (!(\Yii::$app->db->schema instanceof \yii\db\mysql\Schema)) {
            $this->markTestSkipped();
        }         
        $model = Animal::findOne(1);
        $root = $model->getRoot();
        $query = $root->getChildrenQuery(false);
        
        $this->assertSame(
            "SELECT * FROM `animal` WHERE `path`='' ORDER BY `weight` DESC", 
            $query->createCommand()->getRawSql()
        );        
        $this->assertCount(4, $query->all());
    }    
    

    public function testNotRootGetChildrenQuery()
    {
        if (!(\Yii::$app->db->schema instanceof \yii\db\mysql\Schema)) {
            $this->markTestSkipped();
        }         
        $model = Animal::findOne(3);
        $query = $model->getChildrenQuery();
        $this->assertSame(
            "SELECT * FROM `animal` WHERE `path`='3/' ORDER BY `weight`", 
            $query->createCommand()->getRawSql()
        );
    }  
    
    public function testNotRootCountChildrenQuery()
    {
        $model = Animal::findOne(1);
        $query = $model->getChildrenQuery();
        
        $this->assertCount(2, $query->all());
    }     
    
    public function testCheckChildren()
    {
        $model = Animal::findOne(3);
        $children = $model->children();
        $this->assertEquals(8, $children[0]->id);
        $this->assertEquals(9, $children[1]->id);
    }      
    
    public function testRootParent()
    {
        $model = Animal::findOne(3);
        $root = $model->getRoot();
        $this->assertNull($root->parent());
    }     
    
    public function testRootChildParent()
    {
        $model = Animal::findOne(3);
        $parent = $model->parent();
        $this->assertInstanceOf(RootNode::class, $parent);
    }      
    
    public function testDeepChildParent()
    {
        $model = Animal::findOne(7);
        $parent = $model->parent();
        $this->assertEquals(5, $parent->id);
    }   
    
    // parents()
    
    public function testRootParents()
    {
        $model = Animal::findOne(7);
        $root = $model->getRoot();
        $this->assertNull($root->parents());
    }    
    
    public function testRootChildParents()
    {
        $model = Animal::findOne(1);
        $parents1 = $model->parents();
        $parents2 = $model->parents(true, true);
        
        $this->assertEquals([], $parents1);
        $this->assertInstanceOf(RootNode::class, $parents2[0]);
    }   
    
    public function testNotDeepChildParents()
    {
        $model = Animal::findOne(7);
        // without root
        $parents1 = $model->parents();
        // with root
        $parents2 = $model->parents(true, true);
        
        // without root
        $this->assertEquals(2, count($parents1));
        $this->assertEquals(1, $parents1[0]->id);
        $this->assertEquals(5, $parents1[1]->id);

        //with root
        $this->assertEquals(3, count($parents2));
        $this->assertInstanceOf(RootNode::class, $parents2[0]);
        $this->assertEquals(1, $parents2[1]->id);
        $this->assertEquals(5, $parents2[2]->id);        
    } 
    
    public function testOrderChildren()
    {
        $model = Animal::findOne(7);        
        // inverse order without root
        $parents = $model->parents(false);        
        
        // inverse order without root 
        $this->assertEquals(5, $parents[0]->id);
        $this->assertEquals(1, $parents[1]->id);                
    }
    
    public function testIndexResultByChildren()
    {
        $model = Animal::findOne(7);        
        // inverse order without root
        $parents = $model->parents(true, false, true);        
        
        // inverse order without root 
        $this->assertEquals(5, $parents[5]->id);
        $this->assertEquals(1, $parents[1]->id);                
    }    

    // end parents()
    
    // getParentIds()
    
    public function testParentIdsForRoot()
    {
        $model7 = Animal::findOne(7);
        $root = $model7->getRoot();
        
        $this->assertNull($root->getParentIds());
    }
    
    public function testParentIdsForNotRoot()
    {
        $model7 = Animal::findOne(7);
        
        $this->assertEquals([1,5], $model7->getParentIds());
    }    
    
    public function testParentIdsForNotFirstLevelNode()
    {
        $model1 = Animal::findOne(1);
        
        $this->assertEquals([], $model1->getParentIds());
    }    
    
    public function testParentIdsForNotRootWitRootId()
    {
        $model7 = Animal::findOne(7);
        
        $this->assertEquals([-100,1,5], $model7->getParentIds(true));
    }    
    
    public function testParentIdsForNotFirstLevelNodeWitRootId()
    {
        $model1 = Animal::findOne(1);
        
        $this->assertEquals([-100], $model1->getParentIds(true));
    }     

    // end getParentIds()
    
    public function testGetId()
    {
        $model7 = Animal::findOne(7);
        $root = $model7->getRoot();
        
        $this->assertEquals(7, $model7->getId());
        $this->assertEquals(-100, $root->getId());
        // equals as previous one because of yii getters
        $this->assertEquals(-100, $root->id);
    }    
    
    // siblings()
    
    public function testRootSiblings()
    {
        $model = Animal::findOne(7);
        $root = $model->getRoot();
        $this->assertNull($root->siblings());
    }    
    
    public function testNotRootCountSiblings()
    {
        $model = Animal::findOne(2);
        $siblings1 = $model->siblings();
        // with current
        $siblings2 = $model->siblings(true);
        
        $this->assertEquals(3, count($siblings1));
        $this->assertEquals(4, count($siblings2));
        //order
        $this->assertEquals(1, $siblings1[0]->id);
        $this->assertEquals(3, $siblings1[1]->id);
        $this->assertEquals(4, $siblings1[2]->id);
    }  
    
    public function testIndexResultBySiblings()
    {
        $model = Animal::findOne(2);
        $siblings = $model->siblings(false, true);
        
        $this->assertEquals(1, $siblings[1]->id);
        $this->assertEquals(3, $siblings[3]->id);
        $this->assertEquals(4, $siblings[4]->id);
    }     

    // end siblings()
    
    public function testRootFirstChild()
    {
        $model = Animal::findOne(7);
        $root = $model->getRoot();
        $this->assertEquals(1, $root->firstChild()->id);
    } 
    
    public function testNotRootFirstChild()
    {
        $model = Animal::findOne(1);
        $this->assertEquals(5, $model->firstChild()->id);
    }     
    
    public function testNotRootNoFirstChild()
    {
        $model = Animal::findOne(6);
        $this->assertNull($model->firstChild());
    } 
    
    public function testNotRootLastChild()
    {
        $model = Animal::findOne(1);
        $this->assertEquals(6, $model->lastChild()->id);
    }     
    
    public function testNotLeafIsLeaf()
    {
        $model = Animal::findOne(5);
        $this->assertFalse($model->isLeaf());
    }      
    
    public function testLeafIsLeaf()
    {
        $model = Animal::findOne(4);
        $this->assertTrue($model->isLeaf());
    }    
    
    // isDescendantOf
    
    public function testCheckisDescendantOf()
    {
        $model = Animal::findOne(1);
        $root = $model->getRoot();
        $model2 = Animal::findOne(7);
        
        $this->assertFalse($root->isDescendantOf(1));
        $this->assertTrue($model->isDescendantOf($root));
        
        $this->assertTrue($model2->isDescendantOf($model));
        $this->assertTrue($model2->isDescendantOf(1));
        $this->assertTrue($model2->isDescendantOf(5));
        
        $this->assertFalse($model2->isDescendantOf(2));
    }
    
    public function testCheckisDescendantOfNumberRoot()
    {
        $model = Animal::findOne(1);
        $root = $model->getRoot();
        $rootId = $root->getId();
        $this->assertTrue($model->isDescendantOf($rootId));
    }    

    // end isDescendantOf
    
    public function testCheckIsChildOf()
    {
        $model1 = Animal::findOne(1);
        $root = $model1->getRoot();
        $model5 = Animal::findOne(5);
        $model7 = Animal::findOne(7);
        
        $this->assertFalse($root->isChildOf(1));
        $this->assertTrue($model1->isChildOf($root));
        
        $this->assertTrue($model5->isChildOf($model1));
        $this->assertTrue($model5->isChildOf(1));
                
        $this->assertFalse($model7->isChildOf($root));
        $this->assertFalse($model7->isChildOf($model1));
        $this->assertFalse($model7->isChildOf(2));
    }   
    
    public function testCheckisChildOfNumberRoot()
    {
        $model = Animal::findOne(1);
        $root = $model->getRoot();
        $rootId = $root->getId();
        $this->assertTrue($model->isChildOf($rootId));
    }    
    
    public function testCheckIsSiblingOf()
    {
        $model1 = Animal::findOne(1);
        $root = $model1->getRoot();
        $model5 = Animal::findOne(5);
        $model4 = Animal::findOne(4);
        
        $this->assertFalse($root->isSiblingOf($model1));
        $this->assertFalse($model1->isSiblingOf($root));
        
        $this->assertFalse($model1->isSiblingOf($model5));
        $this->assertTrue($model1->isSiblingOf($model4));
        
        $this->assertTrue($root->isSiblingOf($root));
    }        
    
    public function testNext()
    {
        $model1 = Animal::findOne(2);
        $root = $model1->getRoot();
        $model6 = Animal::findOne(6);
        
        $this->assertNull($root->next());
        $this->assertNull($model6->next());
        $this->assertEquals(3, $model1->next()->id);
    }
    
    public function testPrev()
    {
        $model1 = Animal::findOne(2);
        $root = $model1->getRoot();
        $model5 = Animal::findOne(5);
        
        $this->assertNull($root->prev());
        $this->assertNull($model5->prev());
        $this->assertEquals(1, $model1->prev()->id);
    }  
    
    public function testPrevAllForFirst()
    {
        $model5 = Animal::findOne(5);
        $root = $model5->getRoot();
        
        $this->assertEquals([], $model5->prevAll());
        $this->assertEquals([], $root->prevAll());
    }  
    
    public function testPrevAllForNotFirst()
    {
        $model3 = Animal::findOne(3);
        $prevAll = $model3->prevAll();
        $this->assertEquals(2, count($prevAll));
        $this->assertEquals(1, $prevAll[0]->id);
        $this->assertEquals(2, $prevAll[1]->id);
    }     
    
    public function testNextAllForLast()
    {
        $model6 = Animal::findOne(6);
        $root = $model6->getRoot();
        
        $this->assertEquals([], $model6->nextAll());
        $this->assertEquals([], $root->nextAll());
    }

    public function testNextAllForNotLast()
    {
        $model2 = Animal::findOne(2);
        $nextAll = $model2->nextAll();
        $this->assertEquals(2, count($nextAll));
        $this->assertEquals(3, $nextAll[0]->id);
        $this->assertEquals(4, $nextAll[1]->id);
    }  

    public function testIndex()
    {
        $model1 = Animal::findOne(1);
        $root = $model1->getRoot();
        $model4 = Animal::findOne(4);
        
        $this->assertEquals(0, $root->index());
        $this->assertEquals(0, $model1->index());
        $this->assertEquals(3, $model4->index());
    }     

    //// modifications
    
    public function testRootAppendToNode()
    {
        $model0 = Animal::findOne(2);
        $root = $model0->getRoot();
        
        $this->expectExceptionMessage('This operation could not be performed on root node');
        $root->appendTo($model0);
    } 
    
    public function testRootInsertBeforeNode()
    {
        $model0 = Animal::findOne(2);
        $root = $model0->getRoot();
        
        $this->expectExceptionMessage('This operation could not be performed on root node');
        $root->insertBefore($model0);
    }  
    
    public function testInsertBeforeRoot()
    {
        $model0 = Animal::findOne(2);
        $root = $model0->getRoot();
        
        $this->expectExceptionMessage('You cannot insert nodes after or before root node');
        $model0->insertBefore($root);
    }     
    
    public function testInsertAfterRoot()
    {
        $model0 = Animal::findOne(2);
        $root = $model0->getRoot();
        
        $this->expectExceptionMessage('You cannot insert nodes after or before root node');
        $model0->insertAfter($root);
    } 

    public function testPathLenghtRestriction()
    {
        $model7 = Animal::findOne(7);
        
        $model = new Animal(['name' => 'new']);
        $model->getBehavior('materializedpath')->maxPathLength = 4;
        
        $this->expectExceptionMessage('The length of path field is longer than allowed');
        $model->appendTo($model7);
    }

    public function testNewNodeAppendToNewModel()
    {
        $model0 = new Animal(['name' => 'new']);
        
        $model = new Animal();
        
        $this->expectExceptionMessage('You cannot add nodes to a new node');
        $model0->appendTo($model);
    } 
    
    //  new node appendTo
    
    public function testNewNodeNotAddInvalidModel()
    {
        $model0 = Animal::findOne(2);
        $root = $model0->getRoot();
        
        $model = new Animal();
        
        $this->assertFalse($model->appendTo($root));
    }
    
    public function testNewNodeAddToRoot()
    {
        $model0 = Animal::findOne(2);
        $root = $model0->getRoot();
        
        $model = new Animal();
        $model->name = 'new';
        
        $this->assertTrue($model->appendTo($root));
        $this->assertSame($root, $model->getRoot());
        $this->assertSame('', $model->path);
        $this->assertEquals(1, $model->level);
        $this->assertEquals(5, $model->weight);
    }
    
    public function testNewNodeAddToNotRoot()
    {
        $model0 = Animal::findOne(3);
        $root = $model0->getRoot();
        
        $model = new Animal();
        $model->name = 'new';
        
        $this->assertTrue($model->appendTo($model0));
        $this->assertSame($root, $model->getRoot());
        $this->assertEquals(3, $model->parent()->id);
        $this->assertSame('3/', $model->path);
        $this->assertEquals(2, $model->level);
        $this->assertEquals(3, $model->weight);
    } 
    
    public function testNewNodeAppendToLeaf()
    {
        $model6 = Animal::findOne(6);
        
        $model = new Animal();
        $model->name = 'new';
        
        $this->assertTrue($model->appendTo($model6));
        $this->assertSame('1/6/', $model->path);
        $this->assertEquals(3, $model->level);
        $this->assertEquals(1, $model->weight);
        
    }    
    
    // end  new node appendTo
    
    // node add node
    
    public function testRootAddNewNode()
    {
        $model6 = Animal::findOne(6);
        $root = $model6->getRoot();
        
        $model = new Animal();
        $model->name = 'new';
        
        $this->assertTrue($root->add($model));
        $this->assertSame('', $model->path);
        $this->assertEquals(1, $model->level);
        $this->assertEquals(5, $model->weight);
        
    }  

    public function testRootAddNode()
    {
        $model6 = Animal::findOne(6);
        $root = $model6->getRoot();
        
        $this->assertTrue($root->add($model6));
        $model6->refresh();
        $this->assertSame('', $model6->path);
        $this->assertEquals(1, $model6->level);
        $this->assertEquals(5, $model6->weight);
        
    }  
    
    public function testNotRootAddNewNode()
    {
        $model6 = Animal::findOne(6);
        
        $model = new Animal();
        $model->name = 'new';
        
        $this->assertTrue($model6->add($model));
        $this->assertSame('1/6/', $model->path);
        $this->assertEquals(3, $model->level);
        $this->assertEquals(1, $model->weight);
    }     
    
    // end node add node
    
    // new node prependTo
    
    public function testNewNodePrependToRoot()
    {
        $model2 = Animal::findOne(2);
        $root = $model2->getRoot();
        $model4 = Animal::findOne(4);
        
        $model = new Animal();
        $model->name = 'new';
        
        $this->assertTrue($model->prependTo($root));
        $this->assertSame($root, $model->getRoot());
        $this->assertSame('', $model->path);
        $this->assertEquals(1, $model->level);
        $this->assertEquals(1, $model->weight);
        
        $model2->refresh();
        $this->assertEquals(3, $model2->weight);
        $model4->refresh();
        $this->assertEquals(5, $model4->weight);
        
    }
    
    public function testNewNodePrependToNotRoot()
    {
        $model1 = Animal::findOne(1);
        $model5 = Animal::findOne(5);
        $model6 = Animal::findOne(6);
        
        $model = new Animal();
        $model->name = 'new';
        
        $this->assertTrue($model->prependTo($model1));
        $this->assertSame('1/', $model->path);
        $this->assertEquals(2, $model->level);
        $this->assertEquals(1, $model->weight);
        
        $model5->refresh();
        $this->assertEquals(2, $model5->weight);
        $model6->refresh();
        $this->assertEquals(3, $model6->weight);
        
    }  
    
    public function testNewNodePrependToNotRootWithoutTransaction()
    {
        $model1 = Animal::findOne(1);
        $model5 = Animal::findOne(5);
        $model6 = Animal::findOne(6);
        
        $model = new Animal();
        $model->name = 'new';
        
        $this->assertTrue($model->prependTo($model1, true, false));
        $this->assertSame('1/', $model->path);
        $this->assertEquals(2, $model->level);
        $this->assertEquals(1, $model->weight);
        
        $model5->refresh();
        $this->assertEquals(2, $model5->weight);
        $model6->refresh();
        $this->assertEquals(3, $model6->weight);
        
    }     
    
    public function testNewNodePrependToLeaf()
    {
        $model6 = Animal::findOne(6);
        
        $model = new Animal();
        $model->name = 'new';
        
        $this->assertTrue($model->prependTo($model6));
        $this->assertSame('1/6/', $model->path);
        $this->assertEquals(3, $model->level);
        $this->assertEquals(1, $model->weight);
        
    }     
        
    // end  new node prependTo
    
    // new node insertBefore
    
    public function testNewNodeInsertBeforeFirstChild()
    {
        $model1 = Animal::findOne(1);
        $model4 = Animal::findOne(4);
        
        $model = new Animal();
        $model->name = 'new';        
        
        $this->assertTrue($model->insertBefore($model1));
        $model1->refresh();
        $model4->refresh();
        
        $this->assertSame('', $model->path);
        $this->assertEquals(1, $model->level);
        $this->assertEquals(1, $model->weight);        
        
        $this->assertEquals(2, $model1->weight);        
        $this->assertEquals(5, $model4->weight);        
    }
    
    public function testNewNodeInsertBeforeMiddleChild()
    {
        $model3 = Animal::findOne(3);
        $model4 = Animal::findOne(4);
        
        $model = new Animal();
        $model->name = 'new';        
        
        $this->assertTrue($model->insertBefore($model3));
        $model3->refresh();
        $model4->refresh();
        
        $this->assertSame('', $model->path);
        $this->assertEquals(1, $model->level);
        $this->assertEquals(3, $model->weight);        
        
        $this->assertEquals(4, $model3->weight);        
        $this->assertEquals(5, $model4->weight);        
    }    
    
    public function testNewNodeInsertBeforeLastChild()
    {
        $model9 = Animal::findOne(9);
        
        $model = new Animal();
        $model->name = 'new';        
        
        $this->assertTrue($model->insertBefore($model9));
        $model9->refresh();
        
        $this->assertSame('3/', $model->path);
        $this->assertEquals(2, $model->level);
        $this->assertEquals(2, $model->weight);        
        
        $this->assertEquals(3, $model9->weight);        
    }    
    
    public function testMoveNodeToAnotherNodeOfSameLevel()
    {
        $model8 = Animal::findOne(8);
        $model5 = Animal::findOne(5);
        
        $this->assertTrue($model8->insertBefore($model5));
        $this->assertEquals('1/', $model8->path);
        $this->assertEquals(2, $model8->level);
        $this->assertEquals(1, $model8->weight);        
        
        $model5->refresh();
        $this->assertEquals(2, $model5->weight);        
    }

    // end new node insertBefore
    
    // new node insertAfter
    
    public function testNewNodeInsertAfterLastChild()
    {
        $model4 = Animal::findOne(4);
        
        $model = new Animal();
        $model->name = 'new';        
        
        $this->assertTrue($model->insertAfter($model4));
        $model4->refresh();
        
        $this->assertSame('', $model->path);
        $this->assertEquals(1, $model->level);
        $this->assertEquals(5, $model->weight);        
        
        $this->assertEquals(4, $model4->weight);        
    }
    
    public function testNewNodeInsertAfterMiddleChild()
    {
        $model3 = Animal::findOne(3);
        $model4 = Animal::findOne(4);
        
        $model = new Animal();
        $model->name = 'new';        
        
        $this->assertTrue($model->insertAfter($model3));
        $model3->refresh();
        $model4->refresh();
        
        $this->assertSame('', $model->path);
        $this->assertEquals(1, $model->level);
        $this->assertEquals(4, $model->weight);        
        
        $this->assertEquals(3, $model3->weight);        
        $this->assertEquals(5, $model4->weight);        
    }    
    
    public function testNewNodeInsertAfterFirstChild()
    {
        $model8 = Animal::findOne(8);
        $model9 = Animal::findOne(9);
        
        $model = new Animal();
        $model->name = 'new';        
        
        $this->assertTrue($model->insertAfter($model8));
        $model9->refresh();
        
        $this->assertSame('3/', $model->path);
        $this->assertEquals(2, $model->level);
        $this->assertEquals(2, $model->weight);        
        
        $this->assertEquals(3, $model9->weight);        
    }    
    
    // end new node insertAfter   
    
    // move common
    
    public function testExistedNodeMoveToNew()
    {
        $model8 = Animal::findOne(8);
        
        $model = new Animal(['name' => 'new']);
        
        $this->expectExceptionMessage('You cannot move nodes to a new node');
        $model8->appendTo($model);
    }


    // end move common

    // existed node appendTo
    
    public function testExistedNodeSiblingsMoveAppendTo()
    {
        $model8 = Animal::findOne(8);
        
        $this->assertTrue($model8->appendTo($model8->parent()));
        $this->assertEquals(3, $model8->weight);
    }    
    
    public function testExistedNodeRootSiblingsMoveAppendTo()
    {
        $model2 = Animal::findOne(2);
        
        $this->assertTrue($model2->appendTo($model2->parent()));
        $this->assertEquals(5, $model2->weight);
    }       
    
    public function testExistedNodeLastSiblingsMoveAppendTo()
    {
        $model9 = Animal::findOne(9);
        
        $this->assertTrue($model9->appendTo($model9->parent()));
        $this->assertEquals(2, $model9->weight);
    }      
    
    // end existed node appendTo
    
    // existed node prependTo
    
    public function testExistedNodeFirstSiblingsMovePrependTo()
    {
        $model8 = Animal::findOne(8);
        $model9 = Animal::findOne(9);
        
        $this->assertTrue($model8->prependTo($model8->parent()));
        $this->assertEquals(1, $model8->weight);
        $model9->refresh();
        $this->assertEquals(2, $model9->weight);
    }     
    
    public function testExistedNodeLastSiblingsMovePrependTo()
    {
        $model8 = Animal::findOne(8);
        $model9 = Animal::findOne(9);
        
        $this->assertTrue($model9->prependTo($model9->parent()));
        $this->assertEquals(1, $model9->weight);
        $model8->refresh();
        $this->assertEquals(2, $model8->weight);
    }      
    
    // end existed node prependTo    
    
    // existed node insertBefore
    
    public function testOldNodeInsertBeforeItself()
    {
        $this->haveFixture('animal', 'an_addon', false);
        
        $model13 = Animal::findOne(13);
        
        $this->expectExceptionMessage('You cannot insert after of before yourself');
        $model13->insertBefore($model13);
    }
    
    public function testExistedNodeSiblingsMoveinsertBeforeFirst()
    {
        $this->haveFixture('animal', 'an_addon', false);
        
        $model11 = Animal::findOne(11);
        $model13 = Animal::findOne(13);
        $model15 = Animal::findOne(15);
        
        
        $this->assertTrue($model13->insertBefore($model11));
        
        $model11->refresh();
        $model15->refresh();
        
        $this->assertEquals(1, $model13->weight);
        $this->assertEquals(2, $model11->weight);
        $this->assertEquals(5, $model15->weight);
    }      
    
    
    // end existed node insertBefore
    
    // existed node insertAfter
    
    public function testExistedNodeSiblingsMoveinsertAfterMiddle()
    {
        $this->haveFixture('animal', 'an_addon', false);
        
        $model11 = Animal::findOne(11);
        $model13 = Animal::findOne(13);
        $model15 = Animal::findOne(15);
        
        
        $this->assertTrue($model11->insertAfter($model13));
        
        $model13->refresh();
        $model15->refresh();
        
        $this->assertEquals(2, $model13->weight);
        $this->assertEquals(3, $model11->weight);
        $this->assertEquals(5, $model15->weight);
    }    
    
    public function testExistedNodeSiblingsMoveinsertAfterLast()
    {
        $this->haveFixture('animal', 'an_addon', false);
        
        $model13 = Animal::findOne(13);
        $model15 = Animal::findOne(15);
        
        
        $this->assertTrue($model13->insertAfter($model15));
        
        $model15->refresh();
        
        $this->assertEquals(5, $model13->weight);
        $this->assertEquals(4, $model15->weight);
    }    
    
    public function testExistedNodeSiblingsMoveinsertAfterHisPrevious()
    {
        $this->haveFixture('animal', 'an_addon', false);
        
        $model13 = Animal::findOne(13);
        $model14 = Animal::findOne(14);
        
        
        $this->assertTrue($model14->insertAfter($model13));
        
        $model13->refresh();
        
        $this->assertEquals(3, $model13->weight);
        $this->assertEquals(4, $model14->weight);
    } 
    // end old node insertAfter  
    
    // insertAsChildAtPosition

    public function testNodeinsertAsChildAtPositionToNewNode()
    {
        $this->haveFixture('animal', 'an_addon', false);
        
        $model13 = Animal::findOne(13);
        $model = new Animal(['name' => 'new']);
        
        $this->expectExceptionMessage('You cannot add nodes to a new node');
        $model13->insertAsChildAtPosition($model, 3);
    } 
    
    public function testNewNodeinsertAsChildAtPosition0()
    {
        $this->haveFixture('animal', 'an_addon', false);
        
        $model10 = Animal::findOne(10);
        $model11 = Animal::findOne(11);
        $model = new Animal(['name' => 'new']);
        
        $this->assertTrue($model->insertAsChildAtPosition($model10, 0));
        $this->assertEquals(1, $model->weight);
        $model11->refresh();
        $this->assertEquals(2, $model11->weight);
    } 
    
    public function testNewNodeinsertAsChildAtPositionToLeaf()
    {
        $this->haveFixture('animal', 'an_addon', false);
        
        $model11 = Animal::findOne(11);
        $model = new Animal(['name' => 'new']);
        
        $this->assertTrue($model->insertAsChildAtPosition($model11, 5));
        $this->assertEquals(1, $model->weight);
        $this->assertEquals('10/11/', $model->path);
    }     
    
    public function testNewNodeinsertAsChildAtPosition2()
    {
        $this->haveFixture('animal', 'an_addon', false);
        
        $model10 = Animal::findOne(10);
        $model11 = Animal::findOne(11);
        $model13 = Animal::findOne(13);
        $model = new Animal(['name' => 'new']);
        
        $this->assertTrue($model->insertAsChildAtPosition($model10, 2));
        $this->assertEquals(3, $model->weight);
        $model11->refresh();
        $model13->refresh();
        $this->assertEquals(1, $model11->weight);
        $this->assertEquals(4, $model13->weight);
    }     
    
    public function testNewNodeinsertAsChildAtPositionLast()
    {
        $this->haveFixture('animal', 'an_addon', false);
        
        $model10 = Animal::findOne(10);
        $model15 = Animal::findOne(15);
        $model = new Animal(['name' => 'new']);
        
        $this->assertTrue($model->insertAsChildAtPosition($model10, 4));
        $this->assertEquals(5, $model->weight);
        $model15->refresh();
        $this->assertEquals(6, $model15->weight);
    }  
    
    public function testNewNodeinsertAsChildAtPositionAfterLast()
    {
        $this->haveFixture('animal', 'an_addon', false);
        
        $model10 = Animal::findOne(10);
        $model15 = Animal::findOne(15);
        $model = new Animal(['name' => 'new']);
        
        $this->assertTrue($model->insertAsChildAtPosition($model10, 5));
        $this->assertEquals(6, $model->weight);
        $model15->refresh();
        $this->assertEquals(5, $model15->weight);
    }  
    
    public function testExistedNodeSiblinginsertAsChildAtPosition2()
    {
        $this->haveFixture('animal', 'an_addon', false);
        
        $model10 = Animal::findOne(10);
        $model12 = Animal::findOne(12);
        $model13 = Animal::findOne(13);
        $model14 = Animal::findOne(14);
        
        $this->assertTrue($model12->insertAsChildAtPosition($model10, 2));
        $this->assertEquals(3, $model12->weight);
        $model14->refresh();
        $model13->refresh();
        $this->assertEquals(4, $model14->weight);
        $this->assertEquals(2, $model13->weight);
    }  

    public function testExistedNodeSiblinginsertAsChildAtPosition1()
    {
        $this->haveFixture('animal', 'an_addon', false);
        
        $model10 = Animal::findOne(10);
        $model12 = Animal::findOne(12);
        $model11 = Animal::findOne(11);
        
        $this->assertTrue($model11->insertAsChildAtPosition($model10, 1));
        $this->assertEquals(2, $model11->weight);
        $model12->refresh();
        $this->assertEquals(1, $model12->weight);
    }     
    
    
    // end insertAsChildAtPosition  
    
    // move not among siblings
    
    public function testAppendToItself()
    {
        $model1 = Animal::findOne(1);
        
        $this->expectExceptionMessage('You cannot move node relevant or under itself');
        
        $model1->appendTo($model1);
    }
    
    public function testMoveUnderItself()
    {
        $model1 = Animal::findOne(1);
        $model5 = Animal::findOne(5);
        
        $this->expectExceptionMessage('You cannot move node relevant or under itself');
        
        $model1->appendTo($model5);
    }    
    
    public function testMoveLeafToLeaf()
    {
        $model7 = Animal::findOne(7);
        $model9 = Animal::findOne(9);
        
        $this->assertTrue($model7->appendTo($model9));
        $this->assertEquals('3/9/', $model7->path);
        $this->assertEquals(3, $model7->level);
    }        
    
    public function testMoveLeafToRoot()
    {
        $model7 = Animal::findOne(7);
        
        $this->assertTrue($model7->appendTo($model7->getRoot()));
        $this->assertEquals('', $model7->path);
        $this->assertEquals(1, $model7->level);        
    }  
    
    public function testMoveLeafDeeper()
    {
        $model4 = Animal::findOne(4);
        $model7 = Animal::findOne(7);
        
        $this->assertTrue($model4->appendTo($model7));
        $this->assertEquals('1/5/7/', $model4->path);
        $this->assertEquals(4, $model4->level);        
    }     

    public function testMoveLeafInTheMiddle()
    {
        $model4 = Animal::findOne(4);
        $model8 = Animal::findOne(8);
        
        $this->assertTrue($model4->insertAfter($model8));
        $this->assertEquals('3/', $model4->path);
        $this->assertEquals(2, $model4->level);        
        $this->assertEquals(2, $model4->weight); 
    }  
    
    public function testMoveSubtree()
    {
        $model3 = Animal::findOne(3);
        $model8 = Animal::findOne(8);
        $model4 = Animal::findOne(4);
        
        $this->assertTrue($model3->appendTo($model4));
        $model8->refresh();
        $this->assertEquals('4/', $model3->path);
        $this->assertEquals(2, $model3->level);        
        $this->assertEquals('4/3/', $model8->path);
        $this->assertEquals(3, $model8->level);                
    }     
    
    public function testMoveSubtreeFromSecondLevel()
    {
        $model5 = Animal::findOne(5);
        $model7 = Animal::findOne(7);
        $model9 = Animal::findOne(9);        
        
        $this->assertTrue($model5->appendTo($model9));
        $model7->refresh();
        $this->assertEquals('3/9/', $model5->path);
        $this->assertEquals(3, $model5->level);        
        $this->assertEquals('3/9/5/', $model7->path);
        $this->assertEquals(4, $model7->level);                        
    }
    
    public function testMoveSubtreeFromSecondLevelToRoot()
    {
        $model5 = Animal::findOne(5);
        $model7 = Animal::findOne(7);
        
        $this->assertTrue($model5->appendTo($model5->getRoot()));
        $model7->refresh();
        $this->assertEquals('', $model5->path);
        $this->assertEquals(1, $model5->level);        
        $this->assertEquals('5/', $model7->path);
        $this->assertEquals(2, $model7->level);                        
    }    

    // end move not among siblings
    
    // deleting
    
    public function testDeleteFirstLevelNodeWithoutChildren()
    {
        $model4 = Animal::findOne(4);
        $model4->delete();
        $model4 = Animal::findOne(4);
        $this->assertNull($model4);
    }     
    
    public function testDeleteFirstLevelNodeWithChildrenWithMove()
    {
        $model3 = Animal::findOne(3);
        $model3->delete();
        $model3 = Animal::findOne(3);
        $model8 = Animal::findOne(8);
        $model9 = Animal::findOne(9);
        
        $this->assertNull($model3);
        $this->assertEquals('', $model8->path);
        $this->assertEquals('', $model9->path);
        $this->assertEquals(1, $model8->level);
        $this->assertEquals(1, $model8->level);
        $this->assertEquals(5, $model8->weight);
        $this->assertEquals(6, $model9->weight);
    } 
    
    public function testDeleteFirstLevelNodeWithChildrenWithDelete()
    {
        $model3 = Animal::findOne(3);
        $model3->getBehavior('materializedpath')->moveChildrenWnenDeletingParent = false;
        $model3->delete();
        $model3 = Animal::findOne(3);
        $model8 = Animal::findOne(8);
        $model9 = Animal::findOne(9);
        
        $this->assertNull($model3);
        $this->assertNull($model8);
        $this->assertNull($model9);
    }     
    
    public function testDeleteNotFirstLevelNodeWithChildrenWithDelete()
    {
        $model5 = Animal::findOne(5);
        $model5->getBehavior('materializedpath')->moveChildrenWnenDeletingParent = false;
        $model5->delete();
        $model5 = Animal::findOne(5);
        $model7 = Animal::findOne(7);
        
        $this->assertNull($model5);
        $this->assertNull($model7);
    }
    
    public function testDeleteNotFirstLevelNodeWithChildrenWithMove()
    {
        $model5 = Animal::findOne(5);
        $model5->delete();
        $model5 = Animal::findOne(5);
        $model7 = Animal::findOne(7);
        
        $this->assertNull($model5);
        $this->assertEquals('1/', $model7->path);
        $this->assertEquals(2, $model7->level);
        $this->assertEquals(3, $model7->weight);
    }     
    
    public function testDeleteNodeWithTwoDescendantsWithDelete()
    {
        $model1 = Animal::findOne(1);
        $model1->getBehavior('materializedpath')->moveChildrenWnenDeletingParent = false;
        $model1->delete();
        $model1 = Animal::findOne(1);
        $model5 = Animal::findOne(5);
        $model7 = Animal::findOne(7);
        
        $this->assertNull($model1);
        $this->assertNull($model5);
        $this->assertNull($model7);
    }
    
    // end deleting
    
    //// end modifications
}