<?php
/**
 * This file is part of the mgrechanik/yii2-materialized-path library
 *
 * @copyright Copyright (c) Mikhail Grechanik <mike.grechanik@gmail.com>
 * @license https://github.com/mgrechanik/yii2-materialized-path/blob/master/LICENCE.md
 * @link https://github.com/mgrechanik/yii2-materialized-path
 */

namespace mgrechanik\yiimaterializedpath;

use yii\db\ActiveRecord;
use mgrechanik\yiimaterializedpath\tools\RootNode;
use yii\db\ActiveQuery;

/**
 * Materialized Path Behavior to turn ActiveRecord model into node of the tree
 * 
 * @author Mikhail Grechanik <mike.grechanik@gmail.com>
 * @since 1.0.0
 */
class MaterializedPathBehavior extends \yii\base\Behavior
{
    /**
     * @var array Array of column names to identify a tree
     * Leave it empty if table holds only one tree
     */
    public $treeIdentityFields = [];
    
    /**
     * @var array Field names this behavior works with
     */
    public $mpFieldNames = [
        /* id - field which is unique identifier for node in the tree
         * it is used in the path field and supposed to be of INTEGER type (and positive value)
         */
        'id' => 'id',
        /* path - preserves path to parent node. It consists of parent ids separated by '/'
         * For nodes of first level path will be '' because treir parent is RootNode who
         * does not exist in the database       
         */
        'path' => 'path',
        /*  level of the node.
         *  RootNode has level 0 and it's children have level 1 and so on
         */
        'level' => 'level',
        /* weight of node among siblings.
         * Nodes are ordered according the value of this field
         * It is intended to be integer positive value (`weight` = 1, 2, 3, ...)
         */
        'weight' => 'weight',
    ];
    
    /**
     * @var boolean Whether to move node's children to node's parent when node is deleted. 
     * If `false` children of the node will be deleted along with it
     */
    public $moveChildrenWnenDeletingParent = true;
    
    /**
     * @var string Scenario we want to put affected models to before saving.
     * Affected models are the ones saved together with the main model.
     * Say we do $model->insertBefore($model2), It would mean that other model's weights need 
     * to be changed and then they saved. 
     * Or when model moves to another parent, it's descendants change their paths too.
     */
    public $modelScenarioForAffectedModelsForSavingProcess;
    
    /**
     * @var string Scenario to set child models to before they are being deleted after
     * their parent deletion. Say in  YourActiveRecord::transactions() you put
     * 
     *   return [
     *       self::SCENARIO_DEFAULT => self::OP_DELETE,
     *   ];
     * 
     * It will start transaction around parent model deletion.
     * But child models will be deleted in EVENT_AFTER_DELETE inside this transaction 
     * and you do not want new transactions inside outer one. 
     * So you change this SCENARIO for child models
     * and the code above will not start transaction for this scenario
     */
    public $modelScenarioForChildrenNodesWhenTheyDeletedAfterParent;
    
    /**
     * @var int|null If set will rise an exception if path value tries to become longer than this one.
     * You hardly need to set up it because you can easily chose varchar(500) for mysql, it will
     * hold tree with very many levels. But if you are concerned about long paths use it.
     */
    public $maxPathLength;

    /**
     * @var ServiceInterface Service for managing trees 
     */
    private $service;
    
    /**
     * @inheritdoc
     */      
    public function __construct(ServiceInterface $service, $config = [])
    {
        parent::__construct($config);
        $this->service = $service;
    }
    
    /**
     * @inheritdoc
     */  
    public function events()
    {
        return [
            ActiveRecord::EVENT_AFTER_DELETE => 'handlerAfterDelete',
        ];
    }    
    
    /**
     * Returns the tree condition for this node
     * 
     * @return array Like ['treeid' => 1]
     */
    public function getTreeCondition()
    {
        $owner = $this->owner;
        /* @var $owner ActiveRecord */
        return $this->service->getTreeCondition($owner);        
    }    
    
    /**
     * Returns the root node of the tree.
     * 
     * For one tree and all it's nodes there is only one Root Node which is a virtual object
     * because it is not associated with any Active Record models
     * @return RootNode The root node
     * @throws \LogicException
     */
    public function getRoot()
    {
        $owner = $this->owner;
        if ($owner->isNewRecord) {
            throw new \LogicException('New records is not the part of the tree yet. So they does not have root node');
        }        
        if ($this->isRoot()) {
            return $owner;
        }        
        $treeCondition = $this->getTreeCondition();
        return $this->service->getRoot(get_class($owner), $treeCondition);
    }
    
    /**
     * Returns basic query for the tree
     * 
     * We are expected to use this basic query because it manages tree condition
     * For any Query who use this one you need to use addWhere() to add another conditions
     * @return ActiveQuery
     */
    public function getQuery()
    {
        $this->checkNotNewModel();
        return $this->getRoot()->getQuery();
    }
    
    /**
     * Returns Query object for descendants of this node
     * 
     * The result will not include current node, only all nodes under it.
     * If you need all tree nodes use RootNode->getDescendantsQuery()
     * 
     * @param array  $exceptIds Array of ids of nodes you want to exclude from result.
     * What is excluded is controlled by format: [1,  2, 3 => false] , meaning:
     *  - exclude  nodes 1 and 2 and all their descendants
     *  - leave node 3, but exclude all it's descendants
     * @param integer|null $depth How many levels of descendant nodes to fetch. 
     * Null means all descendants. 1 - only children of the current node and so on.
     * 
     * @return ActiveQuery 
     */
    public function getDescendantsQuery($exceptIds = [], $depth = null)
    {
        $this->checkNotNewModel();
        $owner = $this->owner;
        $query = $this->getQuery();
        if (!$this->isRoot()) {
            $path = $owner->getFullPath();
            $query->andWhere(['like', $this->getPathFieldName(), "$path%", false]);
        } 
        if (is_int($depth) && ($depth > 0)) {
            $levelField = $this->getLevelFieldName();
            $query->andWhere(['<=', $levelField , $owner->$levelField + $depth]);
        }        
        $query->orderBy($this->getLevelFieldName() . ',' . $this->getWeightFieldName());
        
        if (!empty($exceptIds)) {
            foreach ($exceptIds as $key => $val) {
                $onlyDescendants = $val === false;
                $nid = $onlyDescendants ? $key : $val;
                $this->adjustQueryExcludeNode($query, $nid, $onlyDescendants);
            }
        }
        
        return $query;
    } 
    
    /**
     * Adds to query object restrinctions to exclude some node
     * 
     * @param ActiveQuery $query
     * @param integer $nid
     * @param boolean $onlyDescendants Exclude only node's descendanta but not node itself
     */
    public function adjustQueryExcludeNode($query, $nid, $onlyDescendants = false)
    {
        if ($node = $this->getNodeById($nid)) {
            if (!$onlyDescendants) {
                $query->andWhere(['!=', $this->getIdFieldName(), $node->{$this->getIdFieldName()}]);
            }
            $path = $node->getFullPath();
            $query->andWhere(['not like', $this->getPathFieldName(), "$path%", false]);        
        }
    }

    /**
     * Returns Query object for children of this node
     * 
     * Children are the ones who immediately stand under current node. Their `path` is the same. 
     * @param boolean $sortAsc Whether to sort them in ASC order
     * @return \yii\db\Query
     */
    public function getChildrenQuery($sortAsc = true)
    {
        $this->checkNotNewModel();        
        $path = $this->getFullPath();
        $order = $sortAsc ? SORT_ASC : SORT_DESC;
        return $this->getQuery()
                ->andWhere([$this->getPathFieldName() => $path])
                ->orderBy([$this->getWeightFieldName() => $order]);
    } 
    
    /**
     * Returns all children of the node
     * 
     * @return ActiveRecord[]
     */
    public function children()
    {
        $this->checkNotNewModel(); 
        return $this->getChildrenQuery()->all();
    }
    
    /**
     * Returns first child of current node
     * 
     * @return ActiveRecord[]|null
     */
    public function firstChild()
    {
        $this->checkNotNewModel();
        return $this->getChildrenQuery()->limit(1)->one();
    }  
    
    /**
     * Returns last child of current node
     * 
     * @return ActiveRecord[]|null
     */
    public function lastChild()
    {
        $this->checkNotNewModel();
        return $this->getChildrenQuery(false)->limit(1)->one();
    }     
    
    /**
     * Returns parent node of this one
     * 
     * @return ActiveRecord|null
     */
    public function parent()
    {
        $this->checkNotNewModel(); 
        if ($this->isRoot()) {
            return null;
        }
        $owner = $this->owner;
        $id = $this->service->getParentidFromPath($owner->{$this->getPathFieldName()});
        if (is_null($id)) {
            return $this->getRoot();
        } else {
            return $this->getQuery()->andWhere([$this->getIdFieldName() => $id])->limit(1)->one();
        }
    } 
    
    /**
     * Returns parent nodes of this node
     * 
     * @param boolean $orderFromRootToCurrent Whether include parents from root to this one
     * @param boolean $includeRootNode Whether to include virtual Root Node
     * @param boolean  $indexResultBy Whether to index result keys with ids of parent nodes
     * @return ActiveRecord[]|null
     */
    public function parents($orderFromRootToCurrent = true, $includeRootNode = false, $indexResultBy = false)
    {
        $result = [];
        $ids = $this->getParentIds();
        if (is_null($ids)) {
            return null;
        }
        if ($includeRootNode) {
            $result[0] = $this->getRoot();
        }
        if (!empty($ids)) {
            $models = $this->getQuery()
                    ->andWhere([$this->getIdFieldName() => $ids])
                    ->indexBy($this->getIdFieldName())
                    ->all();
            foreach ($ids as $id) {
                $model = $models[$id];
                if ($indexResultBy) {
                    $result[$id] = $model;
                } else {
                    $result[] = $model;
                }
            }
            if (!$orderFromRootToCurrent) {
                $result = array_reverse($result, $indexResultBy);
            }
        }
        return $result;
    }
    
    /**
     * Returns the identifiers of the parent nodes.
     * 
     * @param boolean $includeRootNode Whether to include virtual Root Node
     * @return integer[]|null
     */
    public function getParentIds($includeRootNode = false)
    {
        $this->checkNotNewModel(); 
        if ($this->isRoot()) {
            return null;
        }        
        $ids = [];        
        $owner = $this->owner;
        $path = $owner->{$this->getPathFieldName()};
        if (!empty($path)) {
            $path = rtrim($path, '/');
            $ids = explode('/', $path); 
            $ids = array_map('intval', $ids);
        }
        if ($includeRootNode) {
            array_unshift($ids, $owner->getRoot()->getId());
        }
        return $ids;
    }  
    
    /**
     * Returns unique identifier of the node
     * 
     * @return integer
     */
    public function getId()
    {
        $this->checkNotNewModel(); 
        $owner = $this->owner;
        return $owner->{$this->getIdFieldName()};
    }


    /**
     * Returns all siblings (brothers and sisters) of current node
     * 
     * @param boolean  $withCurrent Whether to include current node into result
     * @param boolean  $indexResultBy Whether to index result keys with ids of sibling nodes
     * @return ActiveRecord[]|null
     */
    public function siblings($withCurrent = false, $indexResultBy = false)
    {
        $this->checkNotNewModel(); 
        if ($this->isRoot()) {
            return null;
        }   
        $owner = $this->owner;
        $path = $owner->{$this->getPathFieldName()};        
        $query = $this->getSiblingsQuery($path);
        if (!$withCurrent) {
            $query->andWhere(['!=', $this->getIdFieldName(), $owner->{$this->getIdFieldName()}]);
        }
        if ($indexResultBy) {
            $query->indexBy($this->getIdFieldName());
        }        
        
        return $query->orderBy($this->getWeightFieldName())->all();        
    }
    
    /**
     * Returns next sibling of the current node
     * 
     * @return ActiveRecord|null
     */
    public function next()
    {
        $this->checkNotNewModel(); 
        if ($this->isRoot()) {
            return null;
        }   
        $owner = $this->owner;
        $path = $owner->{$this->getPathFieldName()};        
        $query = $this->getSiblingsQuery($path)
            ->andWhere(['>', $this->getWeightFieldName(), $owner->{$this->getWeightFieldName()}])
            ->limit(1);
        return $query->one();
    }
    
    /**
     * Returns all next siblings of the current node
     * 
     * Result will be ordered by `weight` field
     * 
     * @return ActiveRecord[]
     */
    public function nextAll()
    {
        $this->checkNotNewModel(); 
        if ($this->isRoot()) {
            return [];
        }   
        $owner = $this->owner;
        $path = $owner->{$this->getPathFieldName()};        
        $query = $this->getSiblingsQuery($path)->orderBy($this->getWeightFieldName())
            ->andWhere(['>', $this->getWeightFieldName(), $owner->{$this->getWeightFieldName()}]);
        return $query->all();
    }    
    
    /**
     * Returns previous sibling of the current node
     * 
     * @return ActiveRecord|null
     */
    public function prev()
    {
        $this->checkNotNewModel(); 
        if ($this->isRoot()) {
            return null;
        }   
        $owner = $this->owner;
        $path = $owner->{$this->getPathFieldName()};        
        $query = $this->getSiblingsQuery($path)
            ->andWhere(['<', $this->getWeightFieldName(), $owner->{$this->getWeightFieldName()}])
            ->limit(1);
        return $query->one();
    } 
    
    /**
     * Returns all previous siblings of the current node
     * 
     * Result will be ordered by `weight` field
     * 
     * @return ActiveRecord[]
     */
    public function prevAll()
    {
        $this->checkNotNewModel(); 
        if ($this->isRoot()) {
            return [];
        }   
        $owner = $this->owner;
        $path = $owner->{$this->getPathFieldName()};        
        $query = $this->getSiblingsQuery($path)->orderBy($this->getWeightFieldName())
            ->andWhere(['<', $this->getWeightFieldName(), $owner->{$this->getWeightFieldName()}]);
        return $query->all();
    }     
    
    /**
     * Returns index of the current node among siblings. It starts with 0.
     * 
     * @return integer
     */
    public function index()
    {
        $this->checkNotNewModel(); 
        if ($this->isRoot()) {
            return 0;
        }   
        $owner = $this->owner;
        $path = $owner->{$this->getPathFieldName()};        
        $query = $this->getSiblingsQuery($path)
            ->andWhere(['<', $this->getWeightFieldName(), $owner->{$this->getWeightFieldName()}]);
        return $query->count();
    }     

    /**
     * Checks whether this node is the Root Node
     * 
     * Have in mind that only wirtual nodes(which is not saved in the database) could be root nodes
     * @return bool Whether this is the root node
     */
    public function isRoot()
    {
        $owner = $this->owner;
        return $owner instanceof RootNode;
    }    

    /**
     * Checks whether the current node is a leaf, meaning that it has no children
     * 
     * @return boolean
     */
    public function isLeaf()
    {
        $this->checkNotNewModel(); 
        return is_null($this->firstChild());
    }
    
    
    /**
     * Checks whether current node is any descendant of the $node
     * 
     * Meaning that current node is anywhere in subtree of the $node
     * 
     * @param ActiveRecord|integer $node
     * @return boolean
     */
    public function isDescendantOf($node)
    {
        $this->checkNotNewModel(); 
        if ($this->isRoot()) {
            return false;
        }
        if (is_int($node) && ($node < 0)) {
            $node = $this->service->getModelById(get_class($this->owner), $node);
        }
        if ($node instanceof RootNode) {
            $root = $this->getRoot();
            return $root === $node;
        }
        if ($node instanceof ActiveRecord) {
            $node = (int) $node->{$this->getIdFieldName()};
        }        
        if (is_int($node)) {
            $ids = $this->getParentIds();
            return in_array($node, $ids);
        }
        return false;
    }
    
    /**
     * Checks whether current node is the child of the $node
     * 
     * Meaning that current node is right under the $node
     * 
     * @param ActiveRecord|integer $node
     * @return boolean
     */
    public function isChildOf($node)
    {
        $this->checkNotNewModel(); 
        if ($this->isRoot()) {
            return false;
        }
        if (is_int($node) && ($node < 0)) {
            $node = $this->service->getModelById(get_class($this->owner), $node);
        }        
        if ($node instanceof RootNode) {
            return $this->parent() === $node;
        }
        if ($node instanceof ActiveRecord) {
            $node = (int) $node->{$this->getIdFieldName()};
        }        
        if (is_int($node)) {
            $ids = $this->getParentIds();
            if (!empty($ids)) {
                $parentId = array_pop($ids);
                return $node === $parentId;
            }
        }
        return false;
    }   
    
    /**
     * Checks whether current node is the sibling of the $node
     * 
     * @param ActiveRecord $node
     * @return boolean
     */
    public function isSiblingOf($node)
    {
        $this->checkNotNewModel(); 
        $owner = $this->owner;
        if ($this->isRoot() || $node->isRoot()) {
            return $owner === $node;
        }
        return $owner->{$this->getPathFieldName()} == $node->{$this->getPathFieldName()};
    }     
    
    /**
     * Returns full path to a current node, which fully identifies it in the tree
     * 
     * It's format: parentPath/IdOfTheCurrentNode/
     * 
     * @return string Full path
     */
    public function getFullPath()
    {
        $this->checkNotNewModel();
        $owner = $this->owner;
        if ($owner->isRoot()) {
            return '';
        } else {
            return $owner->{$this->getPathFieldName()} . $owner->{$this->getIdFieldName()} . '/';
        }
    }
    
    /**
     * Returns level of the node
     * 
     * @return int
     */
    public function getLevel()
    {
        $this->checkNotNewModel();
        $owner = $this->owner;
        if ($owner->isRoot()) {
            return 0;
        } else {
            return (int) $owner->{$this->getLevelFieldName()};
        }
    } 

    /**
     * Add child node to the current one
     * 
     * @param ActiveRecord $child Node we are appening to the current one
     * @param boolean $runValidation Whether to run validation for model
     * If it turned on and model is not valid operation will not proceed
     * @param boolean $runTransaction Whether to wrap this operation in transaction
     * @return boolean Whether operation succeeded
     */
    public function add($child, $runValidation = true, $runTransaction = true)
    {
        return $child->appendTo($this->owner, $runValidation, $runTransaction);
    }

    /**
     * Appends a new or existed node as last child of another node
     * 
     * @param ActiveRecord $node Parent node to which we will add current one
     * @param boolean $runValidation Whether to run validation for model
     * If it turned on and model is not valid operation will not proceed
     * @param boolean $runTransaction Whether to wrap this operation in transaction
     * @return boolean Whether operation succeeded
     */
    public function appendTo($node, $runValidation = true, $runTransaction = true)
    {
        $this->checkNotRootNode();
        $owner = $this->owner;
        $method = $owner->isNewRecord ? 
            'internalAddNewNodeToParentAsLastChild' : 'internalMoveExistedNodeToAnotherParentAsLastChild';
        return $this->runWithMethod($method, $node, $runValidation, $runTransaction);
    }
    
    /**
     * Appends a new or existed node as first child of another node
     * 
     * @param ActiveRecord $node Parent node to which we will add current one
     * @param boolean $runValidation Whether to run validation for model
     * If it turned on and model is not valid operation will not proceed
     * @param boolean $runTransaction Whether to wrap this operation in transaction
     * @return boolean Whether operation succeeded
     */
    public function prependTo($node, $runValidation = true, $runTransaction = true)
    {
        $this->checkNotRootNode();
        $owner = $this->owner;
        $method = $owner->isNewRecord ? 
            'internalAddNewNodeToParentAsFirstChild' : 'internalMoveExistedNodeToAnotherParentAsFirstChild';
        return $this->runWithMethod($method, $node, $runValidation, $runTransaction);
    } 
    
    /**
     * Inserts a new or existed node as previous of another node
     * 
     * @param ActiveRecord $node node before which we will add current one
     * @param boolean $runValidation Whether to run validation for model
     * If it turned on and model is not valid operation will not proceed
     * @param boolean $runTransaction Whether to wrap this operation in transaction
     * @return boolean Whether operation succeeded
     * @throws \LogicException
     */
    public function insertBefore($node, $runValidation = true, $runTransaction = true)
    {
        $this->checkNotRootNode();
        if ($node->isRoot()) {
            throw new \LogicException('You cannot insert nodes after or before root node');
        }
        $owner = $this->owner;
        $method = $owner->isNewRecord ? 
            'internalNewNodeInsertBeforeNode' : 'internalExistedNodeInsertBeforeNode';
        return $this->runWithMethod($method, $node, $runValidation, $runTransaction);
    }  
    
    /**
     * Inserts a new or existed node as next of another node
     * 
     * @param ActiveRecord $node node after which we will add current one
     * @param boolean $runValidation Whether to run validation for model
     * If it turned on and model is not valid operation will not proceed
     * @param boolean $runTransaction Whether to wrap this operation in transaction
     * @return boolean Whether operation succeeded
     * @throws \LogicException
     */
    public function insertAfter($node, $runValidation = true, $runTransaction = true)
    {
        $this->checkNotRootNode();
        if ($node->isRoot()) {
            throw new \LogicException('You cannot insert nodes after or before root node');
        }        
        $owner = $this->owner;
        $method = $owner->isNewRecord ? 
            'internalNewNodeInserAfterNode' : 'internalExistedNodeInsertAfterNode';
        return $this->runWithMethod($method, $node, $runValidation, $runTransaction);
    } 

    /**
     * Inserts current node to a certain position among children of $node
     * 
     * First child's position - 0
     * Second child's position - 1
     * 
     * @param ActiveRecord $node
     * @param integer $position
     * @param boolean $runValidation Whether to run validation for model
     * If it turned on and model is not valid operation will not proceed
     * @param boolean $runTransaction Whether to wrap this operation in transaction
     * @return boolean Whether operation succeeded
     * @throws \LogicException
     */
    public function insertAsChildAtPosition($node, $position, $runValidation = true, $runTransaction = true)
    {
        if ($node->isNewRecord) {
            throw new \LogicException('You cannot add nodes to a new node');
        }        
        $idField = $this->getIdFieldName();
        $position = intval($position);
        $owner = $this->owner;
        if (!$position) {
            return $this->prependTo($node, $runValidation, $runTransaction);
        }
        $skipId = ($owner->isNewRecord) ? null : $owner->$idField;
        $i = 0;
        $siblingAfter = null;
        foreach ($node->children() as $child) {
            if ($skipId && ($skipId == $child->$idField)) {
                // ignore the node itself if it is among children of the target parent node
                continue;
            }
            if ($i == ($position - 1)) {
                $siblingAfter = $child;
                break;
            }
            $i++;
        }
        if ($siblingAfter) {
            return $this->insertAfter($siblingAfter, $runValidation, $runTransaction);
        } else {
            return $this->appendTo($node, $runValidation, $runTransaction);
        }
    }    
    
    /**
     * Event handler
     * 
     * Here we delete or move children of the node when it is deleted
     * 
     * @param \yii\base\Event $event
     */
    public function handlerAfterDelete($event)
    {
        $deletedModel = $event->sender;
        // do not want to be interpreted as NewRecord
        $deletedModel->setOldAttributes(false);
        $parent = null;
        if ($this->moveChildrenWnenDeletingParent) {
            $parent = $deletedModel->parent();
        } 
        foreach ($deletedModel->children() as $child) {
            if ($parent) {
                //moveToParent
                $child->appendTo($parent);
            } else {
                // deleting
                if ($scenario = $this->modelScenarioForChildrenNodesWhenTheyDeletedAfterParent) {
                    $child->scenario = $scenario;
                }
                $child->delete();
            }            
        }
        // return it is to be NewRecord (that is framework behavior)
        $deletedModel->setOldAttributes(null);
    }
    
    /**
     * The name of the field with id 
     * 
     * @return string
     */
    public function getIdFieldName()
    {
        return $this->mpFieldNames['id'];
    }
    
    /**
     * The name of the field with path 
     * 
     * @return string
     */
    public function getPathFieldName()
    {
        return $this->mpFieldNames['path'];
    }

    /**
     * The name of the field with level 
     * 
     * @return string
     */
    public function getLevelFieldName()
    {
        return $this->mpFieldNames['level'];
    }
    
    /**
     * The name of the field with weight 
     * 
     * @return string
     */
    public function getWeightFieldName()
    {
        return $this->mpFieldNames['weight'];
    }    
    
    
    /**
     * Run operation
     * 
     * Handles validation and running operation
     * 
     * @param string $method
     * @param ActiveRecord $node
     * @param boolean $runValidation
     * @param boolean $runTransaction
     * @return boolean
     */
    protected function runWithMethod($method, $node, $runValidation = true, $runTransaction = true)
    {
        $owner = $this->owner;
        if ($runValidation) {
            if (!$owner->validate()) {
                return false;
            }
        }
        if ($runTransaction) {
            return $this->runWithTransaction($method, $node);
        } else {
            return $this->$method($node);
        }
    }    
    
    /**
     * Run operation in a transaction
     * 
     * @param string $method
     * @param ActiveRecord $node
     * @return boolean
     * @throws \Exception
     */
    protected function runWithTransaction($method, $node)
    {
        $result = false;
        $owner = $this->owner;
        $class = get_class($owner);
        $transaction = $class::getDb()->beginTransaction(); 
        try {
            $result = $this->$method($node);
            $transaction->commit();
        } catch(\Exception $e) {
            $transaction->rollBack();
            throw $e;
        } catch(\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }               
        return $result;
    }

    /**
     * Adds new node as last child of parent node
     * 
     * @param ActiveRecord $parent
     * @return boolean
     */
    protected function internalAddNewNodeToParentAsLastChild($parent)
    {
        return $this->internalAddNewNodeToParent($parent);
    }
    
    /**
     * Adds new node as first child of parent node
     * 
     * @param ActiveRecord $parent
     * @return boolean
     */
    protected function internalAddNewNodeToParentAsFirstChild($parent)
    {
        return $this->internalAddNewNodeToParent($parent, null, false);
    }  
    
    /**
     * Inserts new node before other
     * 
     * @param ActiveRecord $brother
     * @return boolean
     */
    protected function internalNewNodeInsertBeforeNode($brother)
    {
        return $this->internalAddNewNodeToParent($brother->parent(), $brother, false);
    }      
    
    /**
     * Inserts new node before other
     * 
     * @param ActiveRecord $brother
     * @return boolean
     */
    protected function internalNewNodeInserAfterNode($brother)
    {
        return $this->internalAddNewNodeToParent($brother->parent(), $brother);
    }          
    
    /**
     * Adds new node to a parent node to a certain position among siblings
     * 
     * @param ActiveRecord $parent
     * @param ActiveRecord|null $sibling
     * @param boolean $after
     * @return boolean
     * @throws \LogicException
     */
    protected function internalAddNewNodeToParent($parent, $sibling = null, $after = true)
    {
        if ($parent->isNewRecord) {
            throw new \LogicException('You cannot add nodes to a new node');
        }        
        $owner = $this->owner;
        $this->adjustNodeAsChildToParent($parent);
        $this->adjustNodeWeight($parent, $sibling, $after);
        return $owner->save(false);        
    }

    
    /**
     * Moves node as last child to parent
     * 
     * @param ActiveRecord $parent
     * @return boolean
     */
    protected function internalMoveExistedNodeToAnotherParentAsLastChild($parent)
    {
        return $this->internalMoveNodeToParent($parent);
    }
    
    /**
     * Moves node as first child to parent
     * 
     * @param ActiveRecord $parent
     * @return boolean
     */    
    protected function internalMoveExistedNodeToAnotherParentAsFirstChild($parent)
    {
        return $this->internalMoveNodeToParent($parent, null, false);
    }

    /**
     * Moves node before other node
     * 
     * @param ActiveRecord $brother
     * @return boolean
     */    
    protected function internalExistedNodeInsertBeforeNode($brother)
    {
        return $this->internalMoveNodeToParent($brother->parent(), $brother, false);
    }

    /**
     * Moves node after other node
     * 
     * @param ActiveRecord $brother
     * @return boolean
     */    
    protected function internalExistedNodeInsertAfterNode($brother)
    {
        return $this->internalMoveNodeToParent($brother->parent(), $brother);
    }
    
    /**
     * Moves existed node to a parent node to a certain position among siblings
     * 
     * @param ActiveRecord $parent
     * @param ActiveRecord|null $sibling
     * @param boolean $after
     * @return boolean
     * @throws \LogicException
     */
    protected function internalMoveNodeToParent($parent, $sibling = null, $after = true)
    {
        $owner = $this->owner;  
        $idField = $this->getIdFieldName(); 
        $pathField = $this->getPathFieldName(); 
        $levelField = $this->getLevelFieldName(); 
        if ($parent->isNewRecord) {
            throw new \LogicException('You cannot move nodes to a new node');
        }        
        if ($owner->isNewRecord) {
            throw new \LogicException('Move method is not supposed to move new node');
        } 
        if ($owner->getRoot() !== $parent->getRoot()) {
            throw new \LogicException('You are not allowed to move node to another tree');
        }         
        if ((!$parent->isRoot()) && 
            (($owner->$idField == $parent->$idField) || $parent->isDescendantOf($owner))) {
            throw new \LogicException('You cannot move node relevant or under itself');
        }                 
        $isSiblings = false;
        if ($ownParent = $owner->parent()) {
            $isSiblings = $ownParent->getId() == $parent->getId();
        }
        if (!$isSiblings) {
            // need to move
            $oldFullPath = $owner->getFullPath();
            $oldPathLength = strlen($oldFullPath);
            $oldLevel = $owner->getLevel();
            $descendants = $owner->getDescendantsQuery()->all();
            $this->adjustNodeAsChildToParent($parent);
            $newFullPath = $owner->getFullPath();
            $newLevel = $owner->getLevel(); 
            $levelDiff = $newLevel - $oldLevel;
            foreach ($descendants as $descendant) {
                $this->safeSetPathToNode($descendant, $newFullPath . substr($descendant->$pathField, $oldPathLength));
                $descendant->$levelField = $descendant->getLevel() + $levelDiff;
                $this->saveAsAffected($descendant);
            }
        }
        $this->adjustNodeWeight($parent, $sibling, $after);
        return $owner->save(false);        
    }    
    
    /**
     * Sets the node's data to fit to new parent. Including tree condition for new nodes.
     * 
     * Weight is not set.
     * 
     * @param ActiveRecord $parent
     */
    protected function adjustNodeAsChildToParent($parent)
    {
        $owner = $this->owner;
        if ($owner->isNewRecord) {
            $treeCondition = $parent->getTreeCondition();
            foreach ($treeCondition as $key => $val) {
                $owner->$key = $val;
            }
        }
        $this->safeSetPathToNode($owner, $parent->getFullPath());
        $owner->{$this->getLevelFieldName()} = $parent->getLevel() + 1;
    }
    
    /**
     * Sets the weight field for current node(without saving it)
     * And sets weight field for sibling nodes with saving them.
     * 
     * @param ActiveRecord $parent
     * @param ActiveRecord|null $sibling
     * @param boolean $after Insert after or before sibling(s)
     * @throws \LogicException
     */
    protected function adjustNodeWeight($parent, $sibling = null, $after = true)
    {
        $owner = $this->owner;    
        $idField = $this->getIdFieldName();
        $weightField = $this->getWeightFieldName();
        $query = $parent->getChildrenQuery();
        
        // if current node is sibling to $sibling we need to exclude it from processed siblings
        if ((!$owner->isNewRecord) && ($this->isChildOf($parent) ) ) {
            if ($sibling && ($owner->$idField == $sibling->$idField)) {
                throw new \LogicException('You cannot insert after of before yourself');
            }
            $query->andWhere(['!=', $idField, $owner->$idField]);
        }
        
        if (!$sibling) {
            // add to parent
            if ($after) {
                // as last child
                $queryMax = clone $query;
                $owner->$weightField = $queryMax->max($weightField) + 1;                 
            } else {
                // as first child
                $queryFirst = clone $query;
                $queryFirst->limit(1);
                if (!($sibling = $queryFirst->one())) {
                    $owner->$weightField = 1;
                }
            }
        } 
        if ($sibling) {
            // inserting before or after some known node
            $i = 1;
            $querySib = clone $query;
            $siblings = $querySib->all();
            foreach ($siblings as $oldsibling) {
                if ($hit = ($oldsibling->$idField == $sibling->$idField)) {
                    if ($after) {
                        $oldsibling->$weightField = $i;
                        $owner->$weightField = $i + 1;
                    } else {
                        $owner->$weightField = $i;
                        $oldsibling->$weightField = $i + 1;
                    }
                } else {
                    $oldsibling->$weightField = $i;
                }
                
                $this->saveAsAffected($oldsibling);
                
                if ($hit) {
                    $i++;
                }
                $i++;
            }

        }

    }
    
    /**
     * Saving affected AR models
     * 
     * @param ActiveRecord $model
     * @return boolean
     */
    protected function saveAsAffected($model)
    {
        if ($this->modelScenarioForAffectedModelsForSavingProcess) {
            $model->scenario = $this->modelScenarioForAffectedModelsForSavingProcess;
        }
        return $model->save(false);
    }
    
    /**
     * Safe setting of path field to node
     * 
     * @param ActiveRecord $node
     * @param string $newPath
     * @throws \LogicException
     */
    protected function safeSetPathToNode($node, $newPath)
    {
        if ($this->maxPathLength) {
            if (strlen($newPath) > intval($this->maxPathLength)) {
                throw new \LogicException('The length of path field is longer than allowed');
            }
        }
        $node->{$this->getPathFieldName()} = $newPath;
    }

    /**
     * Returns query for all siblings with this $path
     * 
     * @param string $path
     * @return ActiveQuery
     */
    protected function getSiblingsQuery($path)
    {
        return $this->getQuery()->andWhere([$this->getPathFieldName() => $path]);
    }  
    
    /**
     * Returns node of the same tree by it's id.
     * 
     * @param integer $nid
     * @return ActiveRecord|null
     */
    protected function getNodeById($nid)
    {
        return $this->getQuery()->andWhere([$this->getIdFieldName() => intval($nid)])->one();
    }    

    /**
     * Checks whether associated model is new active record model
     * 
     * @throws \LogicException
     */
    protected function checkNotNewModel()
    {
        $owner = $this->owner;
        if ($owner->isNewRecord) {
            throw new \LogicException('This operation could not be performed on new models');
        }
    }
    
    /**
     * Checks whether associated model is new active record model
     * 
     * @throws \LogicException
     */
    protected function checkNotRootNode()
    {
        if ($this->isRoot()) {
            throw new \LogicException('This operation could not be performed on root node');
        }
    }   
    
}