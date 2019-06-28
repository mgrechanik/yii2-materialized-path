<?php
/**
 * This file is part of the mgrechanik/yii2-materialized-path library
 *
 * @copyright Copyright (c) Mikhail Grechanik <mike.grechanik@gmail.com>
 * @license https://github.com/mgrechanik/yii2-materialized-path/blob/master/LICENCE.md
 * @link https://github.com/mgrechanik/yii2-materialized-path
 */

namespace mgrechanik\yiimaterializedpath\tools;

use yii\db\ActiveQuery;

/**
 * Root node of the tree
 * 
 * If table is allowed to has only one tree there will be be only one Root Node for this table.
 * 
 * If table is allowed to store many trees for every tree will be one it's own Root Node (associated
 * with tree condition)
 * 
 * This is a virtual AR model, it does not exist in the database
 * 
 * @author Mikhail Grechanik <mike.grechanik@gmail.com>
 * @since 1.0.0
 */
class RootNode extends MpRootActiveRecord
{
    /**
     * @var string The class name of AR model 
     */
    protected $className = '';
    
    /**
     * @var array The tree condition fot the tree this root node starts
     * For example if table holds many trees it would be like ['treeid' => 2] for some concrete tree 
     */
    protected $treeCondition = [];
    
    /**
     * {@inheritdoc}
     */    
    public function __construct($className, $treeCondition, $config = []) {
        parent::__construct($config);
        $this->className = $className;
        $this->treeCondition = $treeCondition;
    }
    
    /**
     * Get the query for this tree
     * 
     * @return ActiveQuery
     */
    public function getQuery()
    {
        $class = $this->className;
        $query = $class::find();
        if (!empty($this->treeCondition)) {
            $query->where($this->treeCondition);
        }
        return $query;
    }
    
    /**
     * Returns the tree condition
     * 
     * @return array
     */
    public function getTreeCondition()
    {
        return $this->treeCondition;
    }
    
    /**
     * Id of the root node.
     * 
     * Since root node is virtual AR object but sometimes we need to distinguish it
     * from other nodes by id (when it comes from web form) we use special ID for it.
     * 
     * It is NEGATIVE number
     * -100 is the base value (when we have one tree in the table).
     * If there are tree condition, meaning that table holds many trees this ID will be 
     * calculated according next formula:
     *          -100 * (treeField1 + treeField1 + treeFieldi)
     * So if your tree condition id ['treeid' => 2] id will be -200.
     */
    public function getId()
    {
        $koef = 0;
        foreach ($this->treeCondition as $val) {
            if (is_numeric($val)) {
                $koef += $val;
            }
        }
        if (!$koef) {
            $koef = 1;
        }
        return -100 * $koef;
    }
    
}