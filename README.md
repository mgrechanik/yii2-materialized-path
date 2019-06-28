# Yii2 Materialized Path Extension

This extension allows to organize Active Record models into a tree according to Materialized Path algorithm

[Русская версия](docs/README_ru.md)

## Table of contents

* [Features](#features)
* [Installation](#installing)
* [Migrations](#migration)
* [Settings](#settings)
* [Explanation of data structure](#explaining-data)
* [Extension structure](#explaining-extension-structure)
* [Working with a tree](#work-with-tree)
    * [Root node of the tree](#root)
	* [Queries](#descendants)
    * [Navigation](#navigation)
	* [Node properties](#properties)
	* [Inserting new and moving existed nodes](#modification-edit)
	* [Deleting of a node](#modification-delete)
* [Service to manage trees](#service)
    * [Building trees and their output](#building-trees)
	    * [Hierarchical tree](#hierarchical-tree)
		* [Flat tree](#flat-tree)
	* [Cloning](#service-cloning)
	* [Other opportunities](#service-other)
* [Appendix A: Example of creating a catalog](#appendix-a)
* [Appendix B: Examples of working with API](#appendix-b)

## Features <span id="features"></span>
* Allows to organize ActiveRecord models into a tree
* Every tree has only one [Root node](#root)
* It is possible to keep many independent trees in one database table, for example to keep menu items for many menus
* A lot of ways to navigate among the nodes of the tree and question properties of the node
* Tree modification operations: inserting new nodes, moving existed ones. They run in `transaction` if asked
* Two modes when deleting a node: when descendants are deleted along with it or when they are moved to it's parent
* Service of managing trees allows:
    * Query a tree (or subtree) with one query to database
    * Tree is formed it two formats: nested structure useful to be displayed in a form of `<ul>-<li>` lists
	  or "flat" presentation of a tree - simple `php` array useful to be displayed in `<select>` list or to be used for Data Provider
    * Cloning subtrees
    * Getting `id` ranges of descendants of a node, useful for validation rules



## Installation <span id="installing"></span>

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).:

Either run
```
composer require --prefer-dist mgrechanik/yii2-materialized-path
```

or add
```
"mgrechanik/yii2-materialized-path" : "^1.0"
```
to the require section of your `composer.json`

## Migrations <span id="migration"></span>

This extension expects additional columns in the database table which are responsible to keep a tree.

Example of migration for a table with many trees look [here](https://github.com/mgrechanik/yii2-materialized-path/blob/master/tests/migrations/m170208_094405_create_menuitem_table.php)

And here is example of [migration](https://github.com/mgrechanik/yii2-materialized-path/blob/master/tests/migrations/m170208_094404_create_animal_table.php) for a table with only one tree:

```php
use yii\db\Migration;

/**
 * Handles the creation of table `animal`.
 */
class m170208_094404_create_animal_table extends Migration
{
    /**
     * @inheritdoc
     */
    public function up()
    {
        $this->createTable('animal', [
            'id' => $this->primaryKey(),
            'path' => $this->string(255)->notNull()->defaultValue('')->comment('Path to parent node'),
            'level' => $this->integer(4)->notNull()->defaultValue(1)->comment('Level of the node in the tree'),
            'weight' => $this->integer(11)->notNull()->defaultValue(1)->comment('Weight among siblings'),
            'name' => $this->string()->notNull()->comment('Name'),
        ]);
        
        $this->createIndex('animal_path_index', 'animal', 'path');
        $this->createIndex('animal_level_index', 'animal', 'level');
        $this->createIndex('animal_weight_index', 'animal', 'weight');        
        
    }

    /**
     * @inheritdoc
     */
    public function down()
    {
        $this->dropTable('animal');
    }
}
```


*Important in the code above:*

1. Explanation of field's roles will be given in [explanation of data structure](#explaining-data)
2. For `path` we set field's length in `255` symbols which is optimal for `mysql` and allows to keep the trees 
with huge nesting but you can put any value wanted
3. `defaultValue` for fields `path`, `weight` and `level` was set up just in case so even rows
added not with this extension's `api` but manually (using `phpmyadmin` for example) could take
their starting position in the tree
4. For `SQLite` database take off `->comment()`'s from migration above


## Settings <span id="settings"></span>

To turn Active Record model into a **tree node** you need to set up the next behavior for it:

```php
use mgrechanik\yiimaterializedpath\MaterializedPathBehavior;

class Animal extends \yii\db\ActiveRecord
{
    public static function tableName()
    {
        return 'animal';
    }
	
    public function behaviors()
    {
        return [
            'materializedpath' => [
                'class' => MaterializedPathBehavior::class,
                // behavior settings
            ],
        ];
    } 
	
	// ...
}	
```

This `MaterializedPathBehavior` behavior has next settings:
1. `treeIdentityFields` - array with field names which holds the unique identifier of the tree (when there are many).
2. `mpFieldNames` - a map of field names used in this extension to the ones in your model class.
Use it when your names are different from `id`, `path`, `level`, `weight`
3. `modelScenarioForAffectedModelsForSavingProcess` is explained [here](#modification-common)
4. About `moveChildrenWnenDeletingParent` and `modelScenarioForChildrenNodesWhenTheyDeletedAfterParent` 
look in [deleting of a node](#modification-delete).
5. `maxPathLength` can be arbitrary set to some value of limit for `path` field so when it becomes longer
exception will be thrown


## Explanation of data structure <span id="explaining-data"></span>

Data in the database looks like this:

When table holds only one tree:
![Tree presentation in the database](https://raw.githubusercontent.com/mgrechanik/yii2-materialized-path/master/docs/images/animal.png "Tree in DB")

When table holds many trees: <span id="menuitem-table">
![Tree presentation in the database](https://raw.githubusercontent.com/mgrechanik/yii2-materialized-path/master/docs/images/menuitem.png "Tree in DB")

> For following examples we will use the trees above.  
> For the first table there is [Animal](https://github.com/mgrechanik/yii2-materialized-path/blob/master/tests/models/Animal.php) ActiveRecord model.  
> For the second table there is [Menuitem](https://github.com/mgrechanik/yii2-materialized-path/blob/master/tests/models/Menuitem.php) ActiveRecord model.


Common things we see:
* If table holds many trees, like `Menuitem` table, we use additional column(s) for unique tree identificator.
  Like `treeid` in example. And all future manipulations with the tree will be isolated, they would concern
  only this concrete tree, other trees are out of concern
* Every tree has only one Root node who is not presented in the table, being some virtual node, but you can get it
  and work with it the way you work with any other node. [More details](#root)
* Node `Animal(1) - cat` is the first child of this Root node
* `id` column - it holds unique numeric identifier of the node. This extension demands using only positive integer numbers as `id` of nodes.  
* `path` column - it holds **path to parent** of this node. In the form of node's `id` separated by `/`. Empty string for root's children.
* `level` column - it holds level of this node, `0` for Root and so on
* `weight` column - it holds "weight" of the node among its siblings. Neighbors are sorted by this column

So this architecture guarantees effective and sufficient structure to save trees into database table:
* We keep, if need to, a sign to which tree the node belongs to
* Every node thanks to `path` knows its parent
* Between siblings node is positioned according to `weight`

> Differences from other popular implementations of this algorithm
> 1) In other extensions such nodes as `Animal(1) - cat` are often treated as Root nodes. 
> And it becomes to look like one tree has many root nodes.  
> In our extension every tree has only one root node (which is not saved in the database).  
> This way all tree nodes are organized only in **ONE** tree  
> 2) Also in `path` column **full path** is often saved - the path with the `id` of the very same node at the end.   
> In this extension we save only path to node's parent. This value for sibling nodes will be the same

## Extension structure <span id="explaining-extension-structure"></span>
This extension gives two main things:
* `mgrechanik\yiimaterializedpath\MaterializedPathBehavior` - this behavior connected to ActiveRecord model
turns it into tree node by adding necessary functionality to it
* `mgrechanik\yiimaterializedpath\Service` - this [service](#service) is meant to give additional operations
to manage trees: building and outputting trees (subtrees), getting root nodes, cloning and other.

## Working with a tree <span id="work-with-tree"></span>

### Root node of the tree <span id="root"></span>

#### Common <span id="root-common"></span>

Every tree has only **one** root node (futher just "root") - node who stays at the very top of the tree and has no parent.

We does not save a row in the database for this root node because we already know that every tree has it.
So we do not need to additionally create it in the table to begin fill a tree with nodes.   
We consider root node already exists.  
And in the database we keep only data really added in the tree. Starting with the first level nodes - `cat`, `dog`, `snake`, `bear`.

However with this root node we can work the way we work with any other tree nodes:
* we can ask for it's descendants, getting this way all our tree
* we can add nodes to it, they will become nodes of first level
* but in the situation with the root node only logical things work. We can add nodes to root, 
but we cannot insert before/after root node

>Technically root node is represented by object of `mgrechanik\yiimaterializedpath\tools\RootNode` class - some **virtual**
>AR model which you are not supposed to try to save in the database (exception will be risen) but who also configured with `MaterializedPathBehavior`,
>which allows to work with it the way like with any other node.

#### Id of the root node <span id="root-id"></span>

As we said earlier in `path` field we do not keep `id` of the root (because it does not exist in the table) but still
root node has it's `id` - it is controlled in `RootNode::getId()`. We need it mostly for edition html forms
when we may choose the root node in the form and we need a way to distinguish it from other nodes by identifier.

This `id` of the root is formed according to a simple algorithm: 
* it will be negative integer number
* if there is only one tree in the table it's value will be `-100`
* if there are many trees in the table it's value is calculated by next formula - `-100 * (treeField1 + treeField1 + treeFieldi)`, 
so for `['treeid' => 2]` `id` will be `-200`

#### Work with the root node <span id="root-work"></span>

To work with the root node we need at first to get it's object.  
It could be done in several ways:
1) **By means of AR model name** (and arbitrary tree condition) <span id="get-root"></span>
```php
use mgrechanik\yiimaterializedpath\ServiceInterface;
// AR class
use mgrechanik\yiimaterializedpath\tests\models\Animal;
// service of managing the trees
$service = \Yii::createObject(ServiceInterface::class);
// getting
// the root of the tree
$root = $service->getRoot(Animal::class);  
```
If your table holds many trees to get the root of needed tree you are expected to give tree condition of this tree:
```php
use mgrechanik\yiimaterializedpath\tests\models\Menuitem;
// ...
// the root of the first tree
$root1 = $service->getRoot(Menuitem::class, ['treeid' => 1]);
// the root of the second tree
$root2 = $service->getRoot(Menuitem::class, ['treeid' => 2]);
```

2) **By means of any AR model** (not new record) we can get the root of the tree this node belongs to:
```php
$model7 = Animal::findOne(7); // 'stag'
// the root of the tree to which $model7 belongs to
$root = $model7->getRoot();
```
For all nodes of the tree the cache will be returning the same root object (you can compare them using `===` operator). 

3) **By means of his negative `id`**  <span id="get-modelbyid"></span>
```php
$root = $service->getModelById(Animal::class, -100); 
```
Use this method of getting a node when you get `id` values from html form in which both root node and common nodes could be choosen.

Examples: [1](#example-root), [2](#example-insertaschildatpos).

### Queries <span id="queries"></span>

After you choose any node, including [root](#root), you can ask for the next information:

1) **Getting descendants of the node** <span id="descendants"></span>

Get the query object for descendants of the node:

```php
               $node->getDescendantsQuery($exceptIds = [], $depth = null)
```
- Separately by means of `$exceptIds` you can inform which subtrees you want to exclude from query, it's format is like `[1,  2, 3 => false]`
meaning subtrees of nodes `1`, `2` exclude fully , and leave node `3` but exclude all it's descendants
- `$depth` shows how many level of descendants to query  
`null` means to query for all descendants,  
`1` means to query only `1` level deep, another word query only for children  
and so on.  

Example:
```php
$model = Animal::findOne(1);
$query = $model->getDescendantsQuery();
$result = $query->asArray()->all();
```
The result we get will be sorted like `level ASC, weight ASC` so it will not be ready for any output in the form of a tree.   
For building `php` trees have a look [here](#building-trees).

2) **Getting query object** <span id="common-query"></span>

When you need to build your own query to tree nodes instead of working with the AR model
like `ClassName::find()` you would rather start with this query object:

```php
               $node->getQuery()
```
- This object holds **tree condition** for the tree this `$node` belongs to  
- All queries of this extention start with this query object
- You can get it from [root node](#root) either
- Technically you can see it's implementation in `RootNode::getQuery()`
- Your new conditions you need to start now with `andWhere()`

----

### Navigation <span id="navigation"></span>

1) **Get the Root node of the tree**
```php
               $node->getRoot()
```
It works only for existed in database models because new model does not belong to any tree yet.  
It returns the same object for every tree node because tree has only one [root](#root).

2) **Work with the children of the node**

*Get all node's children:*
```php
               $node->children()
```

We will get the array of AR models -  **direct** children of the node.

Or you can use more common query object:

```php
               $node->getChildrenQuery($sortAsc = true)
``` 
Example:
```php
$query = $model->getChildrenQuery();
$result = $query->asArray()->all();
```

*Get the first child of the node:*
```php
               $node->firstChild()
```

*Get the last child of the node:*
```php
               $node->lastChild()
```

3) **Work with the parents of the node**

*Get the parent:*
```php
               $node->parent()
``` 

*Get the parents:*
```php
               $node->parents($orderFromRootToCurrent = true, $includeRootNode = false, $indexResultBy = false)
```
Here:
- `$orderFromRootToCurrent` - sort parents from root to the current node or vise versa
- `$includeRootNode` - include or not the root node in the result
- `$indexResultBy` - whether result should be indexed by `id` of the models

*Getting parents `id`s:*
```php
               $node->getParentIds($includeRootNode = false)
```			   
Here:
- Tre result will be node's `id`s in the order from root to current node's parent
- `$includeRootNode` - include or not the `id` of the root node in the result

4) **Work with the siblings of the node**

*Get:*

*All siblings:*
```php
               $node->siblings($withCurrent = false, $indexResultBy = false)
```
Here:
- `$withCurrent` - include or not current node in the result
- `$indexResultBy` - whether result should be indexed by `id` of the models

*One next:*
```php
               $node->next()
```
*One previous:*
```php
               $node->prev()
```
*All next:*
```php
               $node->nextAll()
```
*All previous:*
```php
               $node->prevAll()
```	
*Get the position of current node among siblings (starting with `0`):*
```php
               $node->index()	
```			   

### Node properties <span id="properties"></span>

Over node you can perform the next checks:

*Check whether the node is the root one:*
```php
               $node->isRoot()
```	
*Check whether the node is a leaf meaning it does not have any descendants:*
```php
               $node->isLeaf()
```	
*Check whether our node is the descendant of another node:*
```php
               $node->isDescendantOf($node)
```
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;,`$node` argument here might be ActiveRecord object or number - `primary key` of the node. 

*Check whether our node is the child of another node:*
```php
               $node->isChildOf($node)
```
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;, argument `$node` as above

*Check whether our node is the sibling to another one:*
```php
               $node->isSiblingOf($node)
```
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;, argument `$node` could be only ActiveRecord model

About node you can get the next information too:

*The full path to the node:*
```php
               $node->getFullPath()
```
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;, this path includes what we hold in `path` field concatinated with the `id`  
of the current node. For example for `Animal(5)` node this value will be `'1/5'`

*Id of the node:*
```php
               $node->getId()
```
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;, this wrapper is useful because it works for Root node also (gives negative `id`)

*Level of the node:*
```php
               $node->getLevel()
```

*Condition of the tree this node belongs to:*
```php
               $node->getTreeCondition()
```
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;, it will return an array like `['treeid' => 2]` for `Menuitem` table

*Get the name of the fields this extension uses:*
```php
               $node->getIdFieldName()
               $node->getPathFieldName()
               $node->getLevelFieldName()
               $node->getWeightFieldName()
```

### Inserting new and moving existed nodes  <span id="modification-edit"></span>

#### Common information <span id="modification-common"></span>

The common information you need to know about inserting/moving nodes:

1. The same methods work both for inserting new nodes and for moving existed ones
2. The signature of every method of modification is the following:
    - `$node->appendTo($node, $runValidation = true, $runTransaction = true)`
    - These methods physically save the model, so they work as `ActiveRecord::save()`
and it means that there is possibility to validation check 	before saving using `$runValidation`
    - They return `true`/`false` whether operation succeded or not
    - Often the inserting/moving operation has the consequences that some other nodes will need
to be changed and saved and it is handy to run all this operation in one transaction
using `$runTransaction`.
    - Also there is the setting `MaterializedPathBehavior::$modelScenarioForAffectedModelsForSavingProcess`
which allows to set up some `scenario` for these additionally changed models before they are saved
3. In these operations [Root node](#root) could be used only in operations adding/moving nodes to it
4. All operations like `prependTo/insertBefore(After)` need the rebuilding of the `weight` field of all new siblings.
Solving this task this extension does not do the work of searching free intervals of `weight` or anнthing like this, 
it rebuilds "weights" of all new siblings and saves them all
5. Seeing the situation when there are many trees in the table:
    - when creating new nodes *tree condition* for them will be set the same as node relevant to which we add new one
    - you are not allowed to move existed nodes to another tree

---

**So the very operations**

#### Add/Move node to new parent at the position of the last child

```php
               $model->appendTo($node, $runValidation = true, $runTransaction = true)
```

- `$model` will be added/moved to new parent `$node` at the position of the last child
- When moving, all `$model`'s descendants will be moved along with it naturally

There is a "mirror" method to the above one when to the left node we can add child:

```php
               $model->add($child, $runValidation = true, $runTransaction = true)
```	
Examples: [1](#example-root-1), [2](#example-root-2), [3](#example-append)

#### Add/Move node to new parent at the position of the first child

```php
               $model->prependTo($node, $runValidation = true, $runTransaction = true)
```

- `$model` will be added/moved to new parent `$node` at the position of the first child

[Example](#example-prepend)

#### Add/Move node to the position before another node

```php
               $model->insertBefore($node, $runValidation = true, $runTransaction = true)
```	   
- `$model` will be added/moved to the position before `$node`. Their parent will be the same	

[Example](#example-insertbefore).		   

#### Add/Move node to the position after another node

```php
               $model->insertAfter($node, $runValidation = true, $runTransaction = true)
```	   
- $model` will be added/moved to the position after `$node`

[Example](#example-insertafter).		   

#### Add/Move node to new parent at the position specified as a number

```php
               $model->insertAsChildAtPosition($node, $position, $runValidation = true, $runTransaction = true)
```	   
- `$model` will be added/moved to new parent `$node` and among it's children it will occupy `$position` position (counting from zero)
- technically this is a wrapper around the methods above

[Example](#example-insertaschildatpos)

### Deleting of a node  <span id="modification-delete"></span>

Deleting of a node is happening by means of existed in `Yii` method `ActiveRecord::delete()` 
```php
               $model->delete()
```
When deleting of a node the next `MaterializedPathBehavior::$moveChildrenWnenDeletingParent` behavior setting 
gives two options for node's descendants:
1. `true` - descendants will be moved to parent of the node being deleted (by means of `appendTo`). This is default setting.
2. `false` - descendants will be deleted along with it

Because `delete()` method is the framework's inner one if you want to run it in transaction you need to set up this for yourself
following [documentation](https://www.yiiframework.com/doc/guide/2.0/en/db-active-record#transactional-operations).
Have a look at the example of this setting at [catalog model](docs/catalog.md#ar-model)

In the catalog model example deleting operation was wrapped in transaction but if descendants are to be deleted too we are not interested
to run all these additional deletions in nested transactions so by using the setting
`MaterializedPathBehavior::$modelScenarioForChildrenNodesWhenTheyDeletedAfterParent` we can set some `scenario`
 for these descendant nodes before they are deleted ( different from `scenario` set up in `transactions()`).
 
[Example](#example-delete) 
 
## *Service* to manage trees <span id="service"></span> 

### Common
This service gives additional functionality to manage trees when we need to manipulate many nodes.

We can get this service the next way:
```php
use mgrechanik\yiimaterializedpath\ServiceInterface;
// trees managing service
$service = \Yii::createObject(ServiceInterface::class);
```
Or inject it using `DI`.  
Technically singleton for this service is defined in the bootstrap of the extension:
`mgrechanik\yiimaterializedpath\tools\Bootstrap`.

### Building trees and their output <span id="building-trees"></span>

#### Common information

As we saw [above](#descendants) `$node->getDescendantsQuery()` query (works for [root node](#root) also if all tree is needed) 
gets needed amount of descendants of the node (through one database query). But we need to transform this query result array in a form
handy to work with (and to be displayed too) as with a tree. 

So in this service we transform this structure into two types of `php` trees:
- `buildTree`, `buildDescendantsTree` builds [hierarchical](#hierarchical-tree) structure in the form of nodes of special type connected to one another.
This structure is handy for **recursive** output of the tree in the form of nested `<ul>-<li>` lists
- `buildFlatTree` based on the information above builds ["flat" tree](#flat-tree) - simple one-dimensional array of nodes according their positions.
It is handy for output of the tree at admin pages using one `foreach` - for `<select>` list or for Data Provider.

#### Hierarchical tree <span id="hierarchical-tree"></span>

```php
               $service->buildTree($parent, $isArray = true, $exceptIds = [], $depth = null)
               $service->buildDescendantsTree($parent, $isArray = true, $exceptIds = [], $depth = null)
```	
*These methods will build the next tree*:
1. Common algorithm is the next
	- Choose the node from which we will start our descendants tree
	- Build the tree
	- Print it 
2. The result will be the array of objects of `mgrechanik\yiimaterializedpath\tools\TreeNode` type which
are the node referring in `children` property to it's children
3. `buildTree` builds the tree starting from `$parent` node when `buildDescendantsTree` starts the tree from children of the `$parent` node
4. `isArray` - choose the format (array or AR object) in which we put our data into `TreeNode::$node`
5. `$exceptIds` see [above](#descendants)
6. `$depth` see [above](#descendants)
7. This tree could be printed at the page in the form of nested `<ul>-<li>` list using simple widget like -
`mgrechanik\yiimaterializedpath\widgets\TreeToListWidget`. This widget has very basic functionality and
comes mostly as example but still it has the opportunity to create any label for tree item you may need

Example:

```php
use mgrechanik\yiimaterializedpath\ServiceInterface;
use mgrechanik\yiimaterializedpath\tests\models\Animal;
use mgrechanik\yiimaterializedpath\widgets\TreeToListWidget;

$service = \Yii::createObject(ServiceInterface::class);

// 1) choose the model
$model1 = Animal::findOne(1);
```

```php
// 2) build the tree
$tree = $service->buildTree($model1);
```
We will get a structure like this:

```
Array
(
    [0] => TreeNode Object
        (
            [node] => Array ([id] => 1, [name] => cat, ...))    // <---- The very node with id=1
            [parent] => 
            [children] => Array                                 // <---- This is it's children (ARRAY-Z)
                (
                    [0] => TreeNode Object
                        (
                            [node] => Array ([id] => 5, [name] => mouse, ...)
                            [parent] => ...
                            [children] => Array
                                (
                                    [0] => TreeNode Object
                                        (
                                            [node] => Array ([id] => 7, [name] => stag, ...)
                                            [parent] => ...
                                            [children] => Array ()
                                        )
                                )
                        )
                    [1] => TreeNode Object
                        (
                            [node] => Array ( [id] => 6, [name] => fox, ... )
                            [parent] => ...
                            [children] => Array ()
                        )
                )
        )
)
```

> If tree had been built like this -  `$tree = $service->buildDescendantsTree($model1);` the result would have been the **ARRAY-Z** above

```php
// 3) print the tree:
print TreeToListWidget::widget(['tree' => $tree]);
```
We wiil get:
<ul>
<li>cat<ul>
<li>mouse<ul>
<li>stag</li>
</ul></li>
<li>fox</li>
</ul></li>
</ul>

Html of the code above is the next:

```html
<ul>
    <li>cat
        <ul>
            <li>mouse
                <ul>
                    <li>stag</li>
                </ul>
            </li>
            <li>fox</li>
        </ul>
    </li>
</ul>
```

*Example of output of ALL tree:*

Код:
```php
$root = $service->getRoot(Animal::class);
$tree = $service->buildDescendantsTree($root);
print TreeToListWidget::widget(['tree' => $tree]);
```
We will get:
<ul>
<li>cat<ul>
<li>mouse<ul>
<li>stag</li>
</ul></li>
<li>fox</li>
</ul></li>
<li>dog</li>
<li>snake<ul>
<li>lion</li>
<li>hedgehog</li>
</ul></li>
<li>bear</li>
</ul>

*Example of the output of the two first levels of the tree (using `$depth` parameter):*

Code:
```php
$root = $service->getRoot(Animal::class);
$tree = $service->buildDescendantsTree($root, true, [], 2);
print TreeToListWidget::widget(['tree' => $tree]);
```
will print:
<ul>
<li>cat<ul>
<li>mouse</li>
<li>fox</li>
</ul></li>
<li>dog</li>
<li>snake<ul>
<li>lion</li>
<li>hedgehog</li>
</ul></li>
<li>bear</li>
</ul>


-----

#### Flat tree <span id="flat-tree"></span>

By term "flat" we would mean a tree in the form of simple array which could be outputted by one `foreach`
in the form like this:
```
- root             
  - (1) cat        
    -- (5) mouse   
      --- (7) stag 
    -- (6) fox     
  - (2) dog        
  - (3) snake      
    -- (8) lion    
    -- (9) hedgehog
  - (4) bear 
```

This tree is created like this:

```php
           $service->buildFlatTree($parent, $isArray = true, $includeItself = false, $indexBy = false, $exceptIds = [], $depth = null)
```	
*This method will build the next tree*:
1. Common algorithm is the next
	- Choose the node from which we will start our descendants tree
	- Build the tree
	- Print it 
2. The result will be the array of nodes represented like associative arrays (`$isArray = true`) or AR objects
3. `$includeItself` sets up from what we start our tree - from `$parent` when `$includeItself = true` or from it's children
4. `$indexBy` - whether to index result array by model's `id`s. Can be handy to be used together with Data Provider
5. `$exceptIds` see [above](#descendants).
6. `$depth` see [above](#descendants).

Example:

```php
// 1) choose the node
$root = $service->getRoot(Animal::class);
```

```php
// 2) build the tree
$tree = $service->buildFlatTree($root);
```
We will get the next array:
```
Array
(
    [0] => Array               // To make the keys equal to ids look at $indexBy above
        (
            [id] => 1
            [path] => 
            [level] => 1
            [weight] => 1
            [name] => cat
        )
    [1] => Array
        (
            [id] => 5
            [path] => 1/
            [level] => 2
            [weight] => 1
            [name] => mouse
        )
    [2] => Array
        (
            [id] => 7
            [path] => 1/5/
            [level] => 3
            [weight] => 1
            [name] => stag
        )
    // .....
    // .....
    [8] => Array
        (
            [id] => 4
            [path] => 
            [level] => 1
            [weight] => 4
            [name] => bear
        )
)
```

This array is ready (when used with `$indexBy`) to be given to Data Provider, for example have a look
at the catalog view page - `actionIndex` -  in [calalog controller](docs/catalog.md#controller).

To transform this array into `$items` for  `Yii`'s built-in `listBox` 
we would need the next helper:

```php
           $service->buildSelectItems($flatTreeArray, callable $createLabel, $indexKey = 'id', $isArray = true);
```		   
1. `$flatTreeArray` - array we built by `buildFlatTree`
2. `$createLabel` - anonymous function to create item label. 
   This function receives node processed and returns string - the item's label we see in `<select>` list
3. `$indexKey` - what field to use to index select item
4. Result will be the array of options `[id1 => label1, id2 => label2, ...]`


```php
// 3) Build select list
$items = $service->buildSelectItems($tree,
	function($node) {
		return ($node['id'] < 0) ? '- root' : str_repeat('  ', $node['level']) . str_repeat('-', $node['level']) . 
				' (' . $node['id'] . ') ' . $node['name'];
	}
); 
```
which will make the next list:
```
  - (1) cat        
    -- (5) mouse   
      --- (7) stag 
    -- (6) fox     
  - (2) dog        
  - (3) snake      
    -- (8) lion    
    -- (9) hedgehog
  - (4) bear 
```

Example of output of **all** tree including [root](#root):
```php
$root = $service->getRoot(Animal::class);
$tree = $service->buildFlatTree($root, true, true);
$items = $service->buildSelectItems($tree,
	function($node) {
		return ($node['id'] < 0) ? '- root' : str_repeat('  ', $node['level']) . str_repeat('-', $node['level']) . 
				' (' . $node['id'] . ') ' . $node['name'];
	} 
); 
```
We will have:
```
- root             
  - (1) cat        
    -- (5) mouse   
      --- (7) stag 
    -- (6) fox     
  - (2) dog        
  - (3) snake      
    -- (8) lion    
    -- (9) hedgehog
  - (4) bear 
```
You can see example of this code working in the [editing form](docs/catalog.md#form-template) of catalog element.

-----

### Cloning <span id="service-cloning"></span>

```php
           $service->cloneSubtree($sourceNode, $destNode, $withSourceNode = true, $scenario = null)
```	
1. Common:
    - Operation will be performed in `transaction`
	- Cloning is allowed only among models of the same type
	- You can clone into another tree
	- `$sourceNode`, `$destNode` - Active Record models or [root nodes](#root)
2. `$sourceNode` - the root of the subtree we are cloning
3. `$destNode` - the node to which we are cloning
4. `$withSourceNode` - whether we start cloning from `$sourceNode` itself or from it's children.  
For example we need to set it to `false` if `$sourceNode` is the [root](#root). All tree will be cloned
5. `$scenario` - optionally we might set `scenario` to cloned nodes before they are saved

[Cloning examples](#example-cloning)

-----

### Other opportunities <span id="service-other"></span>

#### Get the root of the tree <span id="serice-get-root"></span>

```php
           $service->getRoot($className, $treeCondition = [])
```	
1. `$className` - ActiveRecord model name
2. `$treeCondition` - tree condition in the case when table holds many trees.
It is an array like `['treeid' => 1]`

#### Get any node by it's `id` <span id="serice-get-modelbyid"></span>

```php
           $service->getModelById($className, $id, $treeCondition = [])
```	
1. It is a wrapper over `$className::findOne($id)` which is able to find root node by it's negative `$id`.
It is used when we have a html form and root node could be choosen there along with other nodes
2. `$className` - ActiveRecord model name
3. `$id` - unique identifier of the model or negative number as root's `id`
2. `$treeCondition` - tree condition in the case when table holds many trees.
You need to give it only if tree condition is made of more than one field. For conditions with one field like -
 `['treeid' => 2]` omit this parameter because it will be figured out from `$id`.

#### Get `id`s of the nodes of some subtree <span id="service-get-id-range"></span>

```php
           $service->buildSubtreeIdRange($parent, $includeItself = false, $exceptIds = [], $depth = null)
```		   
1. Allows to get array of node `id`s for certain descendants of `$parent` node
2. `$includeItself` - whether to include `id` of the `$parent`
3. `$exceptIds` see [above](#descendants).
4. `$depth` see [above](#descendants).
5. This functionality is interesting to work together with `yii\validators\RangeValidator`

####  Tree condition of the node <span id="service-get-tree-condition"></span>
```php
           $service->getTreeCondition($model)
```	
1. `$model` - node we are checking
2. It will return array like `['treeid' => 1]` - tree condition by which `$model` node belongs to it's tree

####  Get the parent's `id` from path <span id="service-get-parentid"></span>
```php
           $service->getParentidFromPath($path)
```
1. `$path` - path
2. It will return last `id` from the path or `null` if path is empty

## Appendix A: Building a catalog example <span id="appendix-a"></span>

Example about how to create/edit tree nodes at admin pages and display trees is shown in the
[Creating a catalog at Yii2](docs/catalog.md) article where you can see all this architecture in work.

## Appendix B: Examples of working with API <span id="appendix-b"></span>

### Common

All examples are going to work with `Animal` table at following start state:
```
- root             
  - (1) cat        
    -- (5) mouse   
      --- (7) stag 
    -- (6) fox     
  - (2) dog        
  - (3) snake      
    -- (8) lion    
    -- (9) hedgehog
  - (4) bear 
```

Also implicitly there is the next beginning of all code examples: 
```php
use mgrechanik\yiimaterializedpath\ServiceInterface;
use mgrechanik\yiimaterializedpath\tests\models\Animal;
// tree managing service
$service = \Yii::createObject(ServiceInterface::class);
```

### Work with Root node <span id="example-root"></span>

#### Add new node to Root node using `add()` or `appendTo()` <span id="example-root-1"></span>

Whether this way:
```php
$root = $service->getRoot(Animal::class);
$root->add(new Animal(['name' => 'new']));
```
or another:
```php
$root = $service->getRoot(Animal::class);
$newModel = new Animal(['name' => 'new']);
$newModel->appendTo($root);
```
The next change will happen:
```
- root                        - root
  - (1) cat                     - (1) cat
    -- (5) mouse                  -- (5) mouse
      --- (7) stag                  --- (7) stag
    -- (6) fox                    -- (6) fox
  - (2) dog            ==>      - (2) dog
  - (3) snake                   - (3) snake
    -- (8) lion                   -- (8) lion
    -- (9) hedgehog               -- (9) hedgehog
  - (4) bear                    - (4) bear
                                - (10) new      
```

#### Move existed node to the root using `appendTo()` <span id="example-root-2"></span>

```php
$model7 = Animal::findOne(7);
$root = $model7->getRoot();
$model7->appendTo($root);
```
The next change will happen:
```
- root                        - root
  - (1) cat                     - (1) cat
    -- (5) mouse                  -- (5) mouse
      --- (7) stag                -- (6) fox
    -- (6) fox                  - (2) dog
  - (2) dog            ==>      - (3) snake
  - (3) snake                     -- (8) lion
    -- (8) lion                   -- (9) hedgehog
    -- (9) hedgehog             - (4) bear
  - (4) bear                    - (7) stag
```

### `appendTo()` <span id="example-append"></span>

Moving the subtree into new position:

```php
$model1 = Animal::findOne(1);
$model3 = Animal::findOne(3);
$model1->appendTo($model3);
```
The next change will happen:
```
- root                        - root
  - (1) cat                     - (2) dog
    -- (5) mouse                - (3) snake
      --- (7) stag                -- (8) lion
    -- (6) fox                    -- (9) hedgehog
  - (2) dog            ==>        -- (1) cat
  - (3) snake                       --- (5) mouse
    -- (8) lion                       ---- (7) stag
    -- (9) hedgehog                 --- (6) fox
  - (4) bear                    - (4) bear
```

### `prependTo()` <span id="example-prepend"></span>

Add new node as first child of another node:

```php
$model1 = Animal::findOne(1);
$newModel = new Animal(['name' => 'new']);
$newModel->prependTo($model1);
```
The next change will happen:
```
- root                        - root
  - (1) cat                     - (1) cat
    -- (5) mouse                  -- (12) new
      --- (7) stag                -- (5) mouse
    -- (6) fox                      --- (7) stag
  - (2) dog            ==>        -- (6) fox
  - (3) snake                   - (2) dog
    -- (8) lion                 - (3) snake
    -- (9) hedgehog               -- (8) lion
  - (4) bear                      -- (9) hedgehog
                                - (4) bear
```

### `insertBefore()` <span id="example-insertbefore"></span>

Add new node before another node:

```php
$model3 = Animal::findOne(3);
$newModel = new Animal(['name' => 'new']);
$newModel->insertBefore($model3);
```
The next change will happen:
```
- root                        - root
  - (1) cat                     - (1) cat
    -- (5) mouse                  -- (5) mouse
      --- (7) stag                  --- (7) stag
    -- (6) fox                    -- (6) fox
  - (2) dog            ==>      - (2) dog
  - (3) snake                   - (13) new
    -- (8) lion                 - (3) snake
    -- (9) hedgehog               -- (8) lion
  - (4) bear                      -- (9) hedgehog
                                - (4) bear
```

### `insertAfter()` <span id="example-insertafter"></span>

Move existed node right after another node:

```php
$model7 = Animal::findOne(7);
$model8 = Animal::findOne(8);
$model7->insertAfter($model8);
```
The next change will happen:
```
- root                        - root
  - (1) cat                     - (1) cat
    -- (5) mouse                  -- (5) mouse
      --- (7) stag                -- (6) fox
    -- (6) fox                  - (2) dog
  - (2) dog            ==>      - (3) snake
  - (3) snake                     -- (8) lion
    -- (8) lion                   -- (7) stag
    -- (9) hedgehog               -- (9) hedgehog
  - (4) bear                    - (4) bear
```

### `insertAsChildAtPosition()` <span id="example-insertaschildatpos"></span>

Insert new model as third child of the root (position `2`):

```php
$root = $service->getRoot(Animal::class);
$newModel = new Animal(['name' => 'new']);
$newModel->insertAsChildAtPosition($root, 2);
```
The next change will happen:
```
- root                        - root                        
  - (1) cat                     - (1) cat                   
    -- (5) mouse                  -- (5) mouse
      --- (7) stag                  --- (7) stag
    -- (6) fox                    -- (6) fox
  - (2) dog            ==>      - (2) dog                   
  - (3) snake                   - (14) new                  
    -- (8) lion                 - (3) snake
    -- (9) hedgehog               -- (8) lion
  - (4) bear                      -- (9) hedgehog
                                - (4) bear
```

### `delete()` <span id="example-delete"></span>

Delete existing node with it's descendants moving to it's parent:

```php
$model3 = Animal::findOne(3);
$model3->delete()
```
The next change will happen:
```
- root                        - root
  - (1) cat                     - (1) cat
    -- (5) mouse                  -- (5) mouse
      --- (7) stag                  --- (7) stag
    -- (6) fox                    -- (6) fox
  - (2) dog            ==>      - (2) dog
  - (3) snake                   - (4) bear
    -- (8) lion                 - (8) lion
    -- (9) hedgehog             - (9) hedgehog
  - (4) bear                  
```

### Cloning <span id="example-cloning"></span>

#### Cloning one node <span id="example-cloning-1"></span>

```php
$model7 = Animal::findOne(7);
$model8 = Animal::findOne(8);
$service->cloneSubtree($model7, $model8);
```
The next change will happen:
```
- root                        - root
  - (1) cat                     - (1) cat
    -- (5) mouse                  -- (5) mouse
      --- (7) stag                  --- (7) stag
    -- (6) fox                    -- (6) fox
  - (2) dog            ==>      - (2) dog
  - (3) snake                   - (3) snake
    -- (8) lion                   -- (8) lion
    -- (9) hedgehog                 --- (197) stag
  - (4) bear                      -- (9) hedgehog
                                - (4) bear                
```

#### Cloning all subtree <span id="example-cloning-2"></span>

Cloning **all** subtree (default mode):

```php
$model1 = Animal::findOne(1);
$model8 = Animal::findOne(8);
$service->cloneSubtree($model1, $model8);
```
The next change will happen:
```
- root                        - root
  - (1) cat                     - (1) cat
    -- (5) mouse                  -- (5) mouse
      --- (7) stag                  --- (7) stag
    -- (6) fox                    -- (6) fox
  - (2) dog            ==>      - (2) dog
  - (3) snake                   - (3) snake
    -- (8) lion                   -- (8) lion
    -- (9) hedgehog                 --- (198) cat
  - (4) bear                          ---- (199) mouse
                                        ----- (200) stag
                                      ---- (201) fox
                                  -- (9) hedgehog
                                - (4) bear               
```

#### Cloning subtree without it's root <span id="example-cloning-3"></span>

Cloning subtree starting with the children of the source node:

```php
$model1 = Animal::findOne(1);
$model8 = Animal::findOne(8);
$service->cloneSubtree($model1, $model8, false);
```
The next change will happen:
```
- root                        - root
  - (1) cat                     - (1) cat
    -- (5) mouse                  -- (5) mouse
      --- (7) stag                  --- (7) stag
    -- (6) fox                    -- (6) fox
  - (2) dog            ==>      - (2) dog
  - (3) snake                   - (3) snake
    -- (8) lion                   -- (8) lion
    -- (9) hedgehog                 --- (202) mouse
  - (4) bear                          ---- (203) stag
                                    --- (204) fox
                                  -- (9) hedgehog
                                - (4) bear              
```

#### Dublicate descendants of the node <span id="example-cloning-4"></span>

```php
$model1 = Animal::findOne(1);
$service->cloneSubtree($model1, $model1, false);
```
The next change will happen:
```
- root                        - root
  - (1) cat                     - (1) cat
    -- (5) mouse                  -- (5) mouse
      --- (7) stag                  --- (7) stag
    -- (6) fox                    -- (6) fox
  - (2) dog            ==>        -- (205) mouse
  - (3) snake                       --- (206) stag
    -- (8) lion                   -- (207) fox
    -- (9) hedgehog             - (2) dog
  - (4) bear                    - (3) snake
                                  -- (8) lion
                                  -- (9) hedgehog
                                - (4) bear             
```

#### Cloning all tree into a new tree <span id="example-cloning-5"></span>

Original state of `Menuitem` table is shown [here](#menuitem-table).  
Cloning all tree nodes into a new tree:

```php
// root of not empty tree
$root1 = $service->getRoot(Menuitem::class, ['treeid' => 1]);
// root of new and empty tree
$root5 = $service->getRoot(Menuitem::class, ['treeid' => 5]);
// cloning by starting from root1's children
$service->cloneSubtree($root1, $root5, false);
```
The next change will happen:
```
- root1                     
  - (1) red                   
    -- (4) black              
    -- (5) yellow    ==>      ...   
  - (2) green                
    -- (6) blue              
  - (3) brown                
  
- root5                     - root5
                              - (32) red
                                -- (33) black
                     ==>        -- (34) yellow
                              - (35) green
                                -- (36) blue
                              - (37) brown 

```

