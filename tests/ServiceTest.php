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
use mgrechanik\yiimaterializedpath\tests\models\Menuitem;

/**
 * Testing Service for managing trees
 */
class ServiceTest extends DbTestCase
{
    public $service;
    
    protected function setUp()
    {
        parent::setUp();
        $this->service = \Yii::createObject(\mgrechanik\yiimaterializedpath\ServiceInterface::class);
    }
    
    public function testReturnsNullFromEmptyPath()
    {
        $path = '';
        $this->assertNull($this->service->getParentidFromPath($path));
    }
    
    public function testReturnsValidValueFromOneLevelPath()
    {
        $path = '1/';
        $this->assertSame(1, $this->service->getParentidFromPath($path));
    }    
    
    public function testReturnsValidValueFromTwoLevelPath()
    {
        $path = '1/2/';
        $this->assertSame(2, $this->service->getParentidFromPath($path));
    }        
    
    public function testCorrectIdentityFieldsForAnimal()
    {
        $this->assertEquals([], $this->service->getTreeIdentityFields(Animal::class));
    }     
    
    public function testCorrectIdentityFieldsForMenuitem()
    {
        $this->assertEquals(['treeid'], $this->service->getTreeIdentityFields(Menuitem::class));
    } 
    
    public function testCreateSuffixForEmptyTreeCondition()
    {
        $this->assertEquals('', $this->service->createSuffixForTreeCondition([]));
    }    
    
    public function testCreateSuffixForNotEmptyTreeCondition()
    {
        $this->assertEquals('_treeid:2', $this->service->createSuffixForTreeCondition(['treeid' => 2]));
    }        
    
    public function testGetRootForAnimalClass()
    {
        $this->assertIsObject($this->service->getRoot(Animal::class));
    }    
    
    /**
     * @expectedException LogicException
     */
    public function testGetRootForMenuitemClassWithoutTreeCondition()
    {
        $root = $this->service->getRoot(Menuitem::class);
    }   
    
    /**
     * @expectedException LogicException
     */
    public function testGetRootForMenuitemClassWithWrongTreeCondition()
    {
        $root = $this->service->getRoot(Menuitem::class, ['wrongname' => 1]);
    }      
    
    /**
     * @expectedException LogicException
     */
    public function testGetRootForMenuitemClassWithAliensInTreeCondition()
    {
        $root = $this->service->getRoot(Menuitem::class, ['treeid' => 1, 'alien' => 9]);
    }      
    
    public function testGetRootFoMenuitemClassWithTreeCondition()
    {
        $root1 = $this->service->getRoot(Menuitem::class, ['treeid' => 1]);
        $root2 = $this->service->getRoot(Menuitem::class, ['treeid' => 2]);
        
        $this->assertIsObject($root1);
        $this->assertIsObject($root2);
        
        $this->assertNotSame($root1, $root2);
    } 
    
    public function testRootGetId()
    {
        $root0 = $this->service->getRoot(Animal::class);
        $root1 = $this->service->getRoot(Menuitem::class, ['treeid' => 1]);
        $root2 = $this->service->getRoot(Menuitem::class, ['treeid' => 2]);        
        $root3 = $this->service->getRoot(Menuitem::class, ['treeid' => 3]);        
        
        $this->assertEquals(-100, $root0->getId());
        $this->assertEquals(-100, $root1->getId());
        $this->assertEquals(-200, $root2->getId());
        $this->assertEquals(-300, $root3->getId());
    }
    
    public function testGetModelByIdForRoot()
    {
        $this->haveFixture('animal', 'an_basic');
        $root = $this->service->getModelById(Animal::class, -100);
        $this->assertTrue($root->isRoot());
    }    
    
    public function testGetModelByIdForNotRoot()
    {
        $this->haveFixture('animal', 'an_basic');
        $model = $this->service->getModelById(Animal::class, 5);
        $this->assertFalse($model->isRoot());
        $this->assertEquals(5, $model->id);
    }        

    public function testGetModelWithTreeConditionFromIdByGuessFirstTree()
    {
        $this->haveFixture('menuitem', 'mi_basic');
        $model = $this->service->getModelById(Menuitem::class, -100);
        $this->assertTrue($model->isRoot());
        $this->assertEquals(['treeid' => 1], $model->getTreeCondition());
    } 
    
    public function testGetModelWithTreeConditionFromIdByGuessSecondTree()
    {
        $this->haveFixture('menuitem', 'mi_basic');
        $model = $this->service->getModelById(Menuitem::class, -200);
        $this->assertTrue($model->isRoot());
        $this->assertEquals(['treeid' => 2], $model->getTreeCondition());
    }     
    
    /**
     * @expectedException LogicException
     */    
    public function testRootNodeCannotBeSaved()
    {
        $root = $this->service->getRoot(Animal::class);
        $root->save(false);
    }  
    
    // buildDescendantsTree
    
    public function testBuildDescendantsTreeEmpty()
    {
        $this->haveFixture('animal', 'an_basic');
        $model9 = Animal::findOne(9);
        $tree = $this->service->buildDescendantsTree($model9);
        
        $this->assertEquals([], $tree);
    }
    
    public function testBuildDescendantsTreeNotRoot()
    {
        $this->haveFixture('animal', 'an_basic');
        $model1 = Animal::findOne(1);
        $tree = $this->service->buildDescendantsTree($model1);
        $node5 = $tree[0];
        $node6 = $tree[1];
        $node7 = $node5->children[0];
        
        $this->assertCount(2, $tree);
        $this->assertCount(1, $node5->children);
        $this->assertEquals(5, $node5->node['id']);
        $this->assertEquals(6, $node6->node['id']);
        $this->assertEquals(7, $node7->node['id']);
        $this->assertSame($node5, $node7->parent);
    }    
    
    public function testBuildDescendantsTreeRoot()
    {
        $this->haveFixture('animal', 'an_basic');
        $model1 = Animal::findOne(1);
        $root = $model1->getRoot();
        $tree = $this->service->buildDescendantsTree($root);
        $node1 = $tree[0];
        $node4 = $tree[3];
        
        $this->assertCount(4, $tree);
        $this->assertEquals(1, $node1->node['id']);
        $this->assertEquals(4, $node4->node['id']);
        $this->assertNull($node4->parent);
    }        
    
    // end buildDescendantsTree
    
    // buildTree
    
    public function testBuildTreeForLeaf()
    {
        $this->haveFixture('animal', 'an_basic');
        $model9 = Animal::findOne(9);
        $tree = $this->service->buildTree($model9);
        
        $this->assertCount(1, $tree);
        $this->assertEquals(9, $tree[0]->node['id']);
        $this->assertEquals([], $tree[0]->children);
        $this->assertNull($tree[0]->parent);
    }  
    
    public function testBuildTreeForNotRoot()
    {
        $this->haveFixture('animal', 'an_basic');
        $model1 = Animal::findOne(1);
        $tree = $this->service->buildTree($model1);
        $node1 = $tree[0];
        $node5 = $node1->children[0];
        $node6 = $node1->children[1];
        
        $this->assertCount(1, $tree);
        $this->assertNull($node1->parent);
        $this->assertCount(2, $node1->children);
        $this->assertEquals(1, $node1->node['id']);
        $this->assertEquals(5, $node5->node['id']);
        $this->assertEquals(6, $node6->node['id']);
        $this->assertSame($node1, $node5->parent);
    }    
    
    public function testBuildTreeForRoot()
    {
        $this->haveFixture('animal', 'an_basic');
        $model1 = Animal::findOne(1);
        $root = $model1->getRoot();
        $tree = $this->service->buildTree($root);
        $node0 = $tree[0]; // root
        $node1 = $node0->children[0];
        $node4 = $node0->children[3];
        
        $this->assertCount(1, $tree);
        $this->assertCount(4, $node0->children);
        $this->assertEquals(-100, $node0->node['id']);
        $this->assertEquals(1, $node1->node['id']);
        $this->assertEquals(4, $node4->node['id']);
        $this->assertNull($node0->parent);
        $this->assertSame($node0, $node1->parent);
    }      
    
    // end buildTree
    
    
    // buildFlatTree
    
    public function testBuildFlatTreeForRootWithoutRoot()
    {
        $this->haveFixture('animal', 'an_basic');
        $model1 = Animal::findOne(1);
        $root = $model1->getRoot();
        $tree = $this->service->buildFlatTree($root);      
        
        $this->assertCount(9, $tree);
        $this->assertEquals(1, $tree[0]['id']);
        $this->assertEquals(5, $tree[1]['id']);
        $this->assertEquals(7, $tree[2]['id']);
        $this->assertEquals(6, $tree[3]['id']);
        $this->assertEquals(2, $tree[4]['id']);
        $this->assertEquals(3, $tree[5]['id']);
        $this->assertEquals(8, $tree[6]['id']);
        $this->assertEquals(9, $tree[7]['id']);
        $this->assertEquals(4, $tree[8]['id']);
    }
    
    public function testBuildFlatTreeForRootWithoutRootIndexBy()
    {
        $this->haveFixture('animal', 'an_basic');
        $model1 = Animal::findOne(1);
        $root = $model1->getRoot();
        $tree = $this->service->buildFlatTree($root, true, false, true, []);      
        
        $this->assertCount(9, $tree);
        $this->assertEquals(1, $tree[1]['id']);
        $this->assertEquals(5, $tree[5]['id']);
        $this->assertEquals(7, $tree[7]['id']);
        $this->assertEquals(6, $tree[6]['id']);
        $this->assertEquals(2, $tree[2]['id']);
        $this->assertEquals(3, $tree[3]['id']);
        $this->assertEquals(8, $tree[8]['id']);
        $this->assertEquals(9, $tree[9]['id']);
        $this->assertEquals(4, $tree[4]['id']);
    }    
    
    public function testBuildFlatTreeForRootWithRoot()
    {
        $this->haveFixture('animal', 'an_basic');
        $model1 = Animal::findOne(1);
        $root = $model1->getRoot();
        $tree = $this->service->buildFlatTree($root, true, true);      
        
        $this->assertCount(10, $tree);
        $this->assertEquals(-100, $tree[0]['id']);
        $this->assertEquals(1, $tree[1]['id']);
        $this->assertEquals(5, $tree[2]['id']);
        $this->assertEquals(7, $tree[3]['id']);
        $this->assertEquals(6, $tree[4]['id']);
        $this->assertEquals(2, $tree[5]['id']);
        $this->assertEquals(3, $tree[6]['id']);
        $this->assertEquals(8, $tree[7]['id']);
        $this->assertEquals(9, $tree[8]['id']);
        $this->assertEquals(4, $tree[9]['id']);
    } 

    public function testBuildFlatTreeForRootWithRootIndexBy()
    {
        $this->haveFixture('animal', 'an_basic');
        $model1 = Animal::findOne(1);
        $root = $model1->getRoot();
        $tree = $this->service->buildFlatTree($root, true, true, true, []);      
        
        $this->assertCount(10, $tree);
        $this->assertEquals(-100, $tree[-100]['id']);
        $this->assertEquals(1, $tree[1]['id']);
        $this->assertEquals(5, $tree[5]['id']);
        $this->assertEquals(7, $tree[7]['id']);
        $this->assertEquals(6, $tree[6]['id']);
        $this->assertEquals(2, $tree[2]['id']);
        $this->assertEquals(3, $tree[3]['id']);
        $this->assertEquals(8, $tree[8]['id']);
        $this->assertEquals(9, $tree[9]['id']);
        $this->assertEquals(4, $tree[4]['id']);
    }     
    
    public function testBuildFlatTreeForEmptyResult()
    {
        $this->haveFixture('animal', 'an_basic');
        $model4 = Animal::findOne(4);
        $tree = $this->service->buildFlatTree($model4);      
        
        $this->assertCount(0, $tree);
    }    

    public function testBuildFlatTreeForNotRootWithoutParent()
    {
        $this->haveFixture('animal', 'an_basic');
        $model3 = Animal::findOne(3);
        $tree = $this->service->buildFlatTree($model3);      
        
        $this->assertCount(2, $tree);
        $this->assertEquals(8, $tree[0]['id']);
        $this->assertEquals(9, $tree[1]['id']);
    } 
    
    public function testBuildFlatTreeForNotRootWithParent()
    {
        $this->haveFixture('animal', 'an_basic');
        $model3 = Animal::findOne(3);
        $tree = $this->service->buildFlatTree($model3, true, true);      
        
        $this->assertCount(3, $tree);
        $this->assertEquals(3, $tree[0]['id']);
        $this->assertEquals(8, $tree[1]['id']);
        $this->assertEquals(9, $tree[2]['id']);
    }     
    
    public function testBuildFlatTreeExceptLeaf()
    {
        $this->haveFixture('animal', 'an_basic');
        $model1 = Animal::findOne(1);
        $root = $model1->getRoot();
        $tree = $this->service->buildFlatTree($root, true, false, false, [7]);      
        
        $this->assertCount(8, $tree);
        $this->assertEquals(1, $tree[0]['id']);
        $this->assertEquals(5, $tree[1]['id']);
        $this->assertEquals(6, $tree[2]['id']);
    }   
    
    public function testBuildFlatTreeExceptSubtree()
    {
        $this->haveFixture('animal', 'an_basic');
        $model1 = Animal::findOne(1);
        $root = $model1->getRoot();
        $tree = $this->service->buildFlatTree($root, true, false, false, [1]);      
        
        $this->assertCount(5, $tree);
        $this->assertEquals(2, $tree[0]['id']);
        $this->assertEquals(3, $tree[1]['id']);
        $this->assertEquals(8, $tree[2]['id']);
    }     
    
    public function testBuildFlatTreeExceptTwoSubtree()
    {
        $this->haveFixture('animal', 'an_basic');
        $model1 = Animal::findOne(1);
        $root = $model1->getRoot();
        $tree = $this->service->buildFlatTree($root, true, false, false, [1, 3]);      
        
        $this->assertCount(2, $tree);
        $this->assertEquals(2, $tree[0]['id']);
        $this->assertEquals(4, $tree[1]['id']);
    }       
    
    public function testBuildFlatTreeExceptSomeDescendants()
    {
        $this->haveFixture('animal', 'an_basic');
        $model1 = Animal::findOne(1);
        $root = $model1->getRoot();
        $tree = $this->service->buildFlatTree($root, true, false, false, [1 => false]);      
        
        $this->assertCount(6, $tree);
        $this->assertEquals(1, $tree[0]['id']);
        $this->assertEquals(2, $tree[1]['id']);
    }     
    
    public function testBuildFlatTreeWithDepth()
    {
        $this->haveFixture('animal', 'an_basic');
        $model1 = Animal::findOne(1);
        $root = $model1->getRoot();
        $tree = $this->service->buildFlatTree($root, true, false, false, [], 2);      
        
        $this->assertCount(8, $tree);
        $this->assertEquals(1, $tree[0]['id']);
        $this->assertEquals(5, $tree[1]['id']);
        $this->assertEquals(6, $tree[2]['id']);
    }     

    // end buildFlatTree
    
    // buildSubtreeIdRange
    
    public function testbuildSubtreeIdRangeRootWithoutItself()
    {
        $this->haveFixture('animal', 'an_basic');
        $model1 = Animal::findOne(1);
        $root = $model1->getRoot();
        $ids = $this->service->buildSubtreeIdRange($root);      

        $this->assertEquals([1,2,3,4,5,6,7,8,9], $ids);
    }         
    
    public function testbuildSubtreeIdRangeRootWithItself()
    {
        $this->haveFixture('animal', 'an_basic');
        $model1 = Animal::findOne(1);
        $root = $model1->getRoot();
        $ids = $this->service->buildSubtreeIdRange($root, true);      
        
        $this->assertEquals([-100, 1,2,3,4,5,6,7,8,9], $ids);
    }             
    
    public function testbuildSubtreeIdRangeRootWithItselfWithoutSubtree()
    {
        $this->haveFixture('animal', 'an_basic');
        $model1 = Animal::findOne(1);
        $root = $model1->getRoot();
        $ids = $this->service->buildSubtreeIdRange($root, true, [1]);      
        
        $this->assertEquals([-100,2,3,4,8,9], $ids);
    }      
    
    public function testbuildSubtreeIdRangeNotRootWithoutItself()
    {
        $this->haveFixture('animal', 'an_basic');
        $model1 = Animal::findOne(1);
        $ids = $this->service->buildSubtreeIdRange($model1);      
        
        $this->assertEquals([5,6,7], $ids);
    }      
    
    public function testbuildSubtreeIdRangeNotRootWithItself()
    {
        $this->haveFixture('animal', 'an_basic');
        $model1 = Animal::findOne(1);
        $ids = $this->service->buildSubtreeIdRange($model1, true);      
        
        $this->assertEquals([1,5,6,7], $ids);
    }          
    
    // end buildSubtreeIdRange
    
    
    // cloneSubtree 
    
    public function testCloneSubtreeCloningRoot()
    {
        $this->haveFixture('animal', 'an_basic');
        $model7 = Animal::findOne(7);
        $root = $model7->getRoot();
        
        $this->expectExceptionMessage('You cannot clone root node');
        
        $this->service->cloneSubtree($root, $model7);      
    } 
    
    public function testCloneSubtreeDifferentTypes()
    {
        $this->haveFixture('animal', 'an_basic');
        $model7 = Animal::findOne(7);
        $model1 = Menuitem::findOne(1);
        
        $this->expectExceptionMessage('You cannot clone between different types');
        
        $this->service->cloneSubtree($model7, $model1);      
    } 

    public function testCloneSubtreeCloningNewSource()
    {
        $this->haveFixture('animal', 'an_basic');
        $model7 = Animal::findOne(7);
        $new = new Animal();
        
        $this->expectExceptionMessage('You cannot clone new nodes');
        
        $this->service->cloneSubtree($new, $model7);      
    }     
    
    public function testCloneSubtreeCloningNewDest()
    {
        $this->haveFixture('animal', 'an_basic');
        $model7 = Animal::findOne(7);
        $new = new Animal();
        
        $this->expectExceptionMessage('You cannot clone new nodes');
        
        $this->service->cloneSubtree($model7, $new);      
    }    
    
    public function testCloneSubtreeToSameNode()
    {
        $this->haveFixture('animal', 'an_basic');
        $model7 = Animal::findOne(7);
        
        $this->expectExceptionMessage('You cannot clone node to itself or it\'s descendants');
        
        $this->service->cloneSubtree($model7, $model7);      
    }     
    
    public function testCloneSubtreeToSameNodeOnlyChildren()
    {
        $this->haveFixture('animal', 'an_basic');
        $model1 = Animal::findOne(1);
        
        $this->service->cloneSubtree($model1, $model1, false);  
        
        $count = $model1->getDescendantsQuery()->count();
        
        $this->assertEquals(6, $count);
    }     
    
    public function testCloneSubtreeToDescendant()
    {
        $this->haveFixture('animal', 'an_basic');
        $model1 = Animal::findOne(1);
        $model7 = Animal::findOne(7);
        
        $this->expectExceptionMessage('You cannot clone node to itself or it\'s descendants');
        
        $this->service->cloneSubtree($model1, $model7);      
    }         
    
    public function testCloneSubtreeLeafToLeaf()
    {
        $this->haveFixture('animal', 'an_basic');
        $model7 = Animal::findOne(7);
        $model8 = Animal::findOne(8);
        $this->service->cloneSubtree($model7, $model8);      
        
        $new = $model8->firstChild();
        
        $this->assertNotNull($new);
        $this->assertEquals('stag', $new->name);
        $this->assertEquals('3/8/', $new->path);
        $this->assertEquals(1, $new->weight);
        $this->assertEquals(3, $new->level);
    }              
    
    public function testCloneSubtreeTreeToLeaf()
    {
        $this->haveFixture('animal', 'an_basic');
        $model1 = Animal::findOne(1);
        $model8 = Animal::findOne(8);
        $this->service->cloneSubtree($model1, $model8);      
        
        $count = $model8->getDescendantsQuery()->count();
        $cat = $model8->firstChild();
        $mouse = $cat->firstChild();
        $stag = $mouse->firstChild();
        
        $this->assertEquals(4, $count);
        // first node
        $this->assertNotNull($cat);
        $this->assertEquals('cat', $cat->name);
        $this->assertEquals('3/8/', $cat->path);
        $this->assertEquals(1, $cat->weight);
        $this->assertEquals(3, $cat->level);
        // last node
        $this->assertNotNull($stag);
        $this->assertEquals('stag', $stag->name);
        $this->assertEquals('3/8/' . $cat->getId() . '/' . $mouse->getId() . '/', $stag->path);
        $this->assertEquals(1, $stag->weight);
        $this->assertEquals(5, $stag->level);        
    }  
    
    public function testCloneSubtreeTreeToLeafNoSourceNode()
    {
        $this->haveFixture('animal', 'an_basic');
        $model1 = Animal::findOne(1);
        $model8 = Animal::findOne(8);
        $this->service->cloneSubtree($model1, $model8, false);      
        
        $count = $model8->getDescendantsQuery()->count();
        $mouse = $model8->firstChild();
        $stag = $mouse->firstChild();
        
        $this->assertEquals(3, $count);
        // first node
        $this->assertNotNull($mouse);
        $this->assertEquals('mouse', $mouse->name);
        $this->assertEquals('3/8/', $mouse->path);
        $this->assertEquals(1, $mouse->weight);
        $this->assertEquals(3, $mouse->level);
        // last node
        $this->assertNotNull($stag);
        $this->assertEquals('stag', $stag->name);
        $this->assertEquals('3/8/' . $mouse->getId() . '/', $stag->path);
        $this->assertEquals(1, $stag->weight);
        $this->assertEquals(4, $stag->level);        
    }   
    
    public function testCloneSubtreeToAnotherTree()
    {
        $this->haveFixture('menuitem', 'mi_basic');
        $model4 = Menuitem::findOne(4);
        $model9 = Menuitem::findOne(9);
        
        $this->service->cloneSubtree($model4, $model9);
        
        $new = $model9->firstChild();
        
        $this->assertNotNull($new);
        $this->assertEquals('black', $new->name);
        $this->assertEquals('7/9/', $new->path);
        $this->assertEquals(1, $new->weight);
        $this->assertEquals(3, $new->level);        
        $this->assertEquals(['treeid' => 2], $new->getTreeCondition());        
    }
    
    public function testCloneSubtreeCloneAllTreeToNewTree()
    {
        $this->haveFixture('menuitem', 'mi_basic');
        $model4 = Menuitem::findOne(4);
        $root = $model4->getRoot();
        
        // new tree
        $root5 = $this->service->getRoot(Menuitem::class, ['treeid' => 5]);
        
        $this->service->cloneSubtree($root, $root5, false);
        
        $count = $root5->getDescendantsQuery()->count();
        $new = $root5->firstChild();
        
        $this->assertEquals(6, $count);
        $this->assertNotNull($new);
        $this->assertEquals('red', $new->name);
        $this->assertEquals('', $new->path);
        $this->assertEquals(1, $new->weight);
        $this->assertEquals(1, $new->level);        
        $this->assertEquals(['treeid' => 5], $new->getTreeCondition());        
    }    
    // end cloneSubtree
    
}