<?php
/**
 * This file is part of the mgrechanik/yii2-materialized-path library
 *
 * @copyright Copyright (c) Mikhail Grechanik <mike.grechanik@gmail.com>
 * @license https://github.com/mgrechanik/yii2-materialized-path/blob/master/LICENCE.md
 * @link https://github.com/mgrechanik/yii2-materialized-path
 */

namespace mgrechanik\yiimaterializedpath;

use mgrechanik\yiimaterializedpath\tools\RootNode;
use mgrechanik\yiimaterializedpath\tools\TreeNode;


/**
 * Service to manage trees
 * 
 * @author Mikhail Grechanik <mike.grechanik@gmail.com>
 * @since 1.0.0
 */
class Service implements ServiceInterface
{
    /**
     * @var array Saves the information about AR classes and their Materialized Path settings
     * Format:
     * $cache[ArClassName]['treeIdentityFields']
     *                    ['Root{{%suffix1}}']        // RootNode1
     *                    ['Root{{%suffix2}}']        // RootNode2
     */
    protected $cache = [];
    
    /**
     * {@inheritdoc}
     */
    public function getRoot($className, $treeCondition = [])
    {
         $cacheKey = 'Root' . $this->createSuffixForTreeCondition($treeCondition);
         if (!isset($this->cache[$className][$cacheKey])) {
             $this->cache[$className][$cacheKey] = $this->createRoot($className, $treeCondition);
         }
         return $this->cache[$className][$cacheKey];
    }
    
    /**
     * {@inheritdoc}
     */
    public function getModelById($className, $id, $treeCondition = [])
    {
        $id = (int) $id;
        if ($id < 0) {
            $fields = $this->getMetaData('treeIdentityFields', $className);
            if ((count($fields) == 1) && empty($treeCondition)) {
                // we has not specified required in this case treeCondition 
                // so we try to guess it from id value
                // we are guessing only for one field tree condition
                $treeCondition[$fields[0]] = (int) floor((-1 * $id + 1) / 100);
            }
            return $this->getRoot($className, $treeCondition);
        } else {
            return $className::findOne($id);
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function getTreeCondition($model)
    {
        if (!$fields = $this->getTreeIdentityFields(get_class($model), $model)) {
            return [];
        }
        $result = [];
        foreach ($fields as $name) {
            if (empty($model->$name)) {
                throw new \LogicException('Model does not have all treeIdentityFields filled with correct data');
            }
            $result[$name] = $model->$name;
        }
        return $result;
    }    

    /**
     * {@inheritdoc}
     */
    public function getParentidFromPath($path)
    {
        if (empty($path)) {
            return null;
        }
        $path = rtrim($path, '/');
        $parts = explode('/', $path);
        return (int) array_pop($parts);        
    }
    
    /**
     * {@inheritdoc}
     */
    public function getTreeIdentityFields($className, $model = null)
    {
        return $this->getMetaData('treeIdentityFields', $className, $model);
    }
    
    /**
     * {@inheritdoc}
     */
    public function createSuffixForTreeCondition($treeCondition = [])
    {
        if (empty($treeCondition)) {
            return '';
        } 
        $result = '';
        foreach ($treeCondition as $key => $val) {
            $result .= '_' . strval($key) . ':' . strval($val);
        }
        return $result;
    }
    
    /**
     * {@inheritdoc}
     */
    public function buildDescendantsTree($parent, $isArray = true, $exceptIds = [], $depth = null)
    {
        $query = $parent->getDescendantsQuery($exceptIds, $depth);
        if ($isArray) {
            $query->asArray();
        }
        $nodes = $query->all();
        $tree = [];
        $cache = [];
        $pathField = $parent->getPathFieldName();
        $idField = $parent->getIdFieldName();
        $pathPrefix = $parent->getFullPath();
        foreach ($nodes as $node) {
            $path = $this->getProperty($node, $pathField, $isArray);
            if ($pathPrefix) {
                $path = (string) substr($path, strlen($pathPrefix));
            }
            $parentId = (int) $this->getParentidFromPath($path);
            $object = new TreeNode();
            $object->node = $node;
            $cache[intval($this->getProperty($node, $idField, $isArray))] = $object;
            if ($parentId === 0) {
                $tree[] = $object;
            } else {
                if (isset($cache[$parentId])) {
                    $pobj = $cache[$parentId];
                    $pobj->children[] = $object;
                    $object->parent = $pobj;
                }
            }
        }
        return $tree;
    }
    
    /**
     * {@inheritdoc}
     */
    public function buildTree($parent, $isArray = true, $exceptIds = [], $depth = null)
    {
        $tree = [];
        $treeNode = new TreeNode();
        $treeNode->node = $this->getNode($parent, $isArray);
        $children = $this->buildDescendantsTree($parent, $isArray, $exceptIds, $depth);        
        foreach ($children as $child) {
            $child->parent = $treeNode;
        }
        $treeNode->children = $children;        
        $tree[] = $treeNode;
        return $tree;
    }
    
    /**
     * {@inheritdoc}
     */
    public function buildFlatTree($parent, $isArray = true, $includeItself = false, $indexBy = false, $exceptIds = [], $depth = null)
    {
        $result = [];
        if ($includeItself) {
            $tree = $this->buildTree($parent, $isArray, $exceptIds, $depth);
        } else {
            $tree = $this->buildDescendantsTree($parent, $isArray, $exceptIds, $depth);
        }
        $idField = $indexBy ? $parent->getIdFieldName() : false;
        foreach ($tree as $treeNode) {
            $this->fillWithChildren($treeNode, $result, $isArray, $idField);
        }
        return $result;
    }
    
    /**
     * {@inheritdoc}
     */
    public function buildSelectItems($flatTreeArray, callable $createLabel, $indexKey = 'id', $isArray = true)
    {
        $result = [];
        foreach ($flatTreeArray as $node) {
            $result[$this->getProperty($node, $indexKey, $isArray)] = $createLabel($node);
        }
        return $result;
    }
    
    
    /**
     * {@inheritdoc}
     */
    public function buildSubtreeIdRange($parent, $includeItself = false, $exceptIds = [], $depth = null)
    {
        $idField = $parent->getIdFieldName();
        $query = $parent->getDescendantsQuery($exceptIds, $depth);
        $query->select($idField)->orderBy($idField)->asArray();
        $ids = $query->column();
        $ids = \array_map('intval', $ids);
        if ($includeItself) {
            $id = $parent->isRoot() ? $parent->getId() : $parent->$idField;
            array_unshift($ids, $id);
        }
        return $ids;
    }
    
    /**
     * {@inheritdoc}
     */
    public function cloneSubtree($sourceNode, $destNode, $withSourceNode = true, $scenario = null)
    {
        if ($sourceNode->isRoot() && $withSourceNode) {
            throw new \LogicException('You cannot clone root node');
        }
        if (get_class($sourceNode) != get_class($destNode)) {
            throw new \LogicException('You cannot clone between different types');
        }        
        if ($sourceNode->isNewRecord || $destNode->isNewRecord) {
            throw new \LogicException('You cannot clone new nodes');
        }
        if ((($sourceNode->getId() == $destNode->getId()) && $withSourceNode)
            || ($destNode->isDescendantOf($sourceNode))) {
            throw new \LogicException('You cannot clone node to itself or it\'s descendants');
        }        
        $nodes = $withSourceNode ? [$sourceNode] : $sourceNode->children();
        if (!empty($nodes)) {
            $class = get_class($sourceNode);
            $transaction = $class::getDb()->beginTransaction();
            try {
                foreach ($nodes as $node) {
                    $this->cloneToParent($node, $destNode, $scenario);
                }
                $transaction->commit();
            } catch(\Exception $e) {
                $transaction->rollBack();
                throw $e;
            } catch(\Throwable $e) {
                $transaction->rollBack();
                throw $e;
            }
        }
    }
    
    /**
     * Clone node and all it's descendants to new parent
     * 
     * @param ActiveRecord $node
     * @param ActiveRecord|RootNode $parent
     * @param boolean $scenario Scenario to set to new cloned models before inserting
     */
    protected function cloneToParent($node, $parent, $scenario)
    {
        $children = $node->children();

        $idField = $node->getIdFieldName();
        $node->$idField = null;
        $node->setIsNewRecord(true);
        if ($scenario) {
            $node->scenario = $scenario;
        }
        $node->appendTo($parent, false, false);

        foreach ($children as $child) {
            $this->cloneToParent($child, $node, $scenario);
        }
    }

    /**
     * Fills result with node and it's children
     * 
     * @param TreeNode $treeNode
     * @param array $result
     * @param boolean $isArray
     * @param string|boolean $idField
     */
    protected function fillWithChildren($treeNode, &$result, $isArray, $idField = false)
    {
        $node = $treeNode->node;
        if ($idField) {
            $key = $this->getProperty($node, $idField, $isArray);
            $result[$key] = $node;
        } else {
            $result[] = $node;
        }
        if (!empty($treeNode->children)) {
            foreach ($treeNode->children as $child) {
                $this->fillWithChildren($child, $result, $isArray, $idField);
            }
        }
    }

    /**
     * Gets the value of the property on node, represented ny object or array
     * 
     * @param ActiveRecord|array $node
     * @param string $property
     * @param boolean $isArray
     * @return mixed
     */
    protected function getProperty($node, $property, $isArray)
    {
        return $isArray ? $node[$property] : $node->$property;
    }
    
    /**
     * Returns node representation according to format needed
     * 
     * @param ActiveRecord $node
     * @param boolean $isArray
     */
    protected function getNode($node, $isArray)
    {
        if (!$isArray) {
            return $node;
        }
        if ($node->isRoot()) {
            return [
                $node->getIdFieldName() => $node->getId(),
                $node->getPathFieldName() => null,
                $node->getLevelFieldName() => 0,
                $node->getWeightFieldName() => 1,
                
            ];
        } else {
            return $node->attributes;
        }
    }

    /**
     * Creating a root node
     * 
     * @param string $className
     * @param array $treeCondition
     * @throws \LogicException
     */
    protected function createRoot($className, $treeCondition = [])
    {
        $fields = $fieldsstart = $this->getTreeIdentityFields($className);
        if (!empty($fields)) {
            if (empty($treeCondition)) {
                throw new \LogicException('You need to specify tree condition for table with many trees');
            }
            $condition = array_keys($treeCondition);
            sort($fields, SORT_STRING);
            sort($condition, SORT_STRING);            
            if ($fields !== $condition) {
                throw new \LogicException('You need to specify correct fieldnames for tree condition');
            }
        }
        return new RootNode($className, $treeCondition, [
            'treeIdentityFields' => $fieldsstart,
            'mpFieldNames' => $this->getMetaData('mpFieldNames', $className)
        ]);
    }
    
    /**
     * Returns metadata about materialized path behavior properties set to AR model
     * 
     * @param string $what
     * @param string  $className
     * @param ActiveRecord $model
     * @return array
     */
    protected function getMetaData($what, $className, $model = null)
    {
        if (!isset($this->cache[$className]['treeIdentityFields'])) {
            if (is_null($model)) {//var_dump($className);//die($className);
                $model = new $className;
            }
            $this->cache[$className]['treeIdentityFields'] = $model->treeIdentityFields;
            $this->cache[$className]['mpFieldNames'] = $model->mpFieldNames;
        }
        return $this->cache[$className][$what];        
    }    
    
}