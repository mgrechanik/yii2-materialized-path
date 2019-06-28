<?php
/**
 * This file is part of the mgrechanik/yii2-materialized-path library
 *
 * @copyright Copyright (c) Mikhail Grechanik <mike.grechanik@gmail.com>
 * @license https://github.com/mgrechanik/yii2-materialized-path/blob/master/LICENCE.md
 * @link https://github.com/mgrechanik/yii2-materialized-path
 */

namespace mgrechanik\yiimaterializedpath\tools;

use mgrechanik\yiimaterializedpath\MaterializedPathBehavior;

/**
 * Class to imitate root node as of ActiveRecord type
 * 
 * This way we can use Root node exactly like we use all AR nodes of the tree.
 * You can apply many of this behavior methods on it, but only those who has logical sence.
 * Say you can ask for $root->children() or $root->getDescendantQuery().
 * But you cannot use $root->appendTo() because root is the head of the tree, it could not be moved.
 * 
 * But you are not supposed to save it because it is virtual node, 
 * it is not present in the table
 * 
 */
abstract class MpRootActiveRecord extends \yii\db\ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public function behaviors()
    {
        return [
            'materializedpath' => [
                'class' => MaterializedPathBehavior::class,
            ],
        ];
    }    

    /**
     * We need to override it so Root node could not be seen as new models
     * @return boolean
     */
    public function getIsNewRecord() {
        return false;
    }

    /**
     * Root node is a not a new record and is not supposed to be saved
     * @param bool $runValidation
     * @param array $attributeNames
     * @throws \DomainException
     */
    public function update($runValidation = true, $attributeNames = null)
    {
        throw new \DomainException('Root node is virtual node, it does not exist in the database. So do not try to save it');
    }
    
    /**
     * Root node is not supposed to be deleted
     * @throws \DomainException
     */
    public function delete()
    {
        throw new \DomainException('Root node is virtual node, it does not exist in the database. So do not try to delete it');
    }
    
    /**
     * We need this so AR will not ask db scheme for attributes
     * @return array
     */
    public function attributes()
    {
        return [];
    }

}
