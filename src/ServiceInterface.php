<?php
/**
 * This file is part of the mgrechanik/yii2-materialized-path library
 *
 * @copyright Copyright (c) Mikhail Grechanik <mike.grechanik@gmail.com>
 * @license https://github.com/mgrechanik/yii2-materialized-path/blob/master/LICENCE.md
 * @link https://github.com/mgrechanik/yii2-materialized-path
 */

namespace mgrechanik\yiimaterializedpath;

/**
 * Trees managing service interface
 * 
 * @author Mikhail Grechanik <mike.grechanik@gmail.com>
 * @since 1.0.0
 */
interface ServiceInterface
{
    /**
     * Get the root node of the tree
     * 
     * @param string $className The name of AR class
     * @param array $treeCondition Condition for the tree
     * It is an array like ['treeid' => 1] for tables with many trees
     * @return RootNode
     */
    public function getRoot($className, $treeCondition = []);
    
    /**
     * Returns Root model (for id < 0) or AR model from the table by id
     * 
     * You might need it if you operate nodes by it's id and you do not 
     * want in your code add checks for negative values as a mark of RootNode
     * 
     * @param string $className
     * @param integer $id
     * @param array $treeCondition Condition for the tree
     * You do not need it if table holds only one tree.
     * You may skip it (it would be figured from id value) if your treeCondition
     * has only one element, example ['treeid' => 1] 
     * @return ActiveRecord|RootNode|null
     */
    public function getModelById($className, $id, $treeCondition = []);
    
    /**
     * Get the tree condition for the model
     * 
     * @param array 
     * @throws \LogicException
     */
    public function getTreeCondition($model);

    /**
     * Returns id of the parent node or null if the latter is a root node
     * 
     * @param string $path
     * @return int|null
     */
    public function getParentidFromPath($path);
    
    /**
     * Returns array of columns to identify a tree
     * 
     * @param string $className
     * @param Object|null $model AR model
     * @return array 
     */
    public function getTreeIdentityFields($className, $model = null);

    /**
     * Returns unique suffix for the tree in the table
     * 
     * @param array $treeCondition
     */
    public function createSuffixForTreeCondition($treeCondition = []);
    
    /**
     * Builds a tree of descendants of node
     * 
     * Every node is represented as TreeNode object
     * Result starts as array of $parent's children and from them go deeper. 
     * 
     * Say we have tree:
     * 
     * - 1 
     *    - 5
     *        -7
     *    - 6
     * 
     * Call buildDescendantsTree(Animal::findOne(1))  will give the next tree:
     * 
     *    - 5
     *        -7
     *    - 6
     * 
     * [
     *      0 => TreeNode(
     *              node => ['id' => 5, 'path' => '1/', ...]
     *              children => [
     *                  0 => TreeNode(
     *                          node => ['id' => 7, 'path' => '1/5', ...]
     *                          children => []
     *                       )
     *              ]
     *           )
     *      1 => TreeNode(
     *              node => ['id' => 6, 'path' => '1/', ...]
     *              children => []
     *           }
     * ]
     * 
     * @param ActiveRecord|RootNode $parent The node which descendants we are asking
     * @param boolean $isArray Whether keep information about node as array or AR object
     * @param integer[] $exceptIds See [[MaterializedPathBehavior::getDescendantsQuery]]
     * @param integer|null $depth See [[MaterializedPathBehavior::getDescendantsQuery]]
     * return ActiveRecord[]
     */
    public function buildDescendantsTree($parent, $isArray = true, $exceptIds = [], $depth = null);
    
    /**
     * Builds a tree starting with this parent node
     * 
     * Result is like to [[buildDescendantsTree]] but prepended with this parent node
     * 
     * Say we have tree:
     * 
     * - 1 
     *    - 5
     *        -7
     *    - 6
     * 
     * Call buildTree(Animal::findOne(1)) will give the next tree:
     * 
     * - 1 
     *    - 5
     *        -7
     *    - 6
     * 
     * [
     *      0 => TreeNode(
     *              node => ['id' => 1, 'path' => '', ...]
     *              children => [
     *                  // ... all it's children
     *              ]
     *           )
     * ]
     * 
     * @param ActiveRecord|RootNode $parent The node from which we build a tree
     * @param boolean $isArray Whether keep information about node as array or AR object
     * @param integer[] $exceptIds See [[MaterializedPathBehavior::getDescendantsQuery]]
     * @param integer|null $depth See [[MaterializedPathBehavior::getDescendantsQuery]]
     * return ActiveRecord[]
     */
    public function buildTree($parent, $isArray = true, $exceptIds = [], $depth = null);
    
    /**
     * Builds a flat tree, just simple array - list of nodes the way they follow like in menu.
     * 
     * It is useful to display tree for <select></select> in one foreach, without recursion.
     * Or use this tree with ArrayDataProvider
     * Indent is controlled via `level`
     * 
     * Say we have a tree
     * 
     * - 1 
     *    - 5
     *        -7
     *    - 6
     * - 2
     * - 3
     *    - 8
     *    - 9
     * - 4
     * 
     * $root = Animal::findOne(1)->getRoot();
     * $this->buildFlatTree($root) 
     * 
     * will sort them in the next order: 
     * 
     *      1
     *      5
     *      7
     *      6
     *      2
     *      3
     *      8
     *      9
     *      4
     * 
     * The result will be:
     *       Array
     *       (
     *           [0] => Array
     *               (
     *                   [id] => 1
     *                   [path] => 
     *                   [level] => 1
     *                   [weight] => 1
     *                   [name] => cat
     *               )
     *
     *           [1] => Array
     *               (
     *                   [id] => 5
     *                   [path] => 1/
     *                   [level] => 2
     *                   [weight] => 1
     *                   [name] => mouse
     *               )
     *           ...
     *
     *           [8] => Array
     *               (
     *                   [id] => 4
     *                   [path] => 
     *                   [level] => 1
     *                   [weight] => 4
     *                   [name] => bear
     *               )
     *
     *       )
     * 
     * If you need keys to be equal to ids set up $indexBy parameter.
     * Useful for ArrayDataProvider
     * 
     * @param ActiveRecord|RootNode $parent The node for which we are building a tree
     * @param boolean $isArray Whether result array will consist of arrays
     * @param boolean $includeItself Whether to start list with the `$parent` node
     * @param boolean $indexBy Whether the result should be indexed by model's id
     * @param integer[] $exceptIds See [[MaterializedPathBehavior::getDescendantsQuery]]
     * @param integer|null $depth See [[MaterializedPathBehavior::getDescendantsQuery]]
     * @return array 
     */
    public function buildFlatTree($parent, $isArray = true, $includeItself = false, $indexBy = false, $exceptIds = [], $depth = null);
    
    /**
     * Builds select items for flat tree
     * 
     * 
     * Example:
     * Code
     * ```
     *   $model1 = Animal::findOne(1);
     *   $root = $model1->getRoot();
     *   $tree = $this->service->buildFlatTree($root);
     *   $selectItems = $this->service->buildSelectItems($tree, function($node) {
     *       return ($node['id'] < 0) ? 'root' : str_repeat('-', $node['level']) . ' ' . $node['name'];
     *   });
     * ```
     * Will give the result array ready for 
     * any \yii\helpers\Html::dropDownList/ListBox etc.:
     * 
     *   Array
     *   (
     *       [1] => - cat
     *       [5] => -- mouse
     *       [7] => --- stag
     *       [6] => -- fox
     *       [2] => - dog
     *       [3] => - snake
     *       [8] => -- lion
     *       [9] => -- hedgehog
     *       [4] => - bear
     *   )
     * 
     * @param array $flatTreeArray  Array representing a tree, built by [[buildFlatTree]]
     * @param callable $createLabel Function to create label.
     * As argument it takes node array or object
     * @param string $indexKey The name of the id field
     * @return array
     * Result format:
     * [
     *      id1 => label1,
     *      id2 => label2,
     *      ...
     * ]
     * 
     */
    public function buildSelectItems($flatTreeArray, callable $createLabel, $indexKey = 'id', $isArray = true);
    
    /**
     * Returns all nodes' ids of some subtree
     * 
     * It is useful when you want to get validation range of ids
     * 
     * @param ActiveRecord|RootNode $parent The root node of this subtree
     * @param boolean $includeItself Whether to start list with this $parent node
     * @param integer[] $exceptIds See [[MaterializedPathBehavior::getDescendantsQuery]]
     * @param integer|null $depth See [[MaterializedPathBehavior::getDescendantsQuery]]
     * @return integer[]
     */
    public function buildSubtreeIdRange($parent, $includeItself = false, $exceptIds = [], $depth = null);
    
    /**
     * Cloning subtree
     * 
     * @param ActiveRecord|RootNode $sourceNode What to clone
     * @param ActiveRecord|RootNode $destNode Destination where to clone
     * @param boolean $withSourceNode Whether to clone all subtree with $sourceNode as root
     * of this subtree (when `true`) or clone only it's children (when `false`)
     * @param boolean $scenario Scenario to set to new cloned models before saving
     * @throws \LogicException
     */
    public function cloneSubtree($sourceNode, $destNode, $withSourceNode = true, $scenario = null);    

}