<?php
/**
 * This file is part of the mgrechanik/yii2-materialized-path library
 *
 * @copyright Copyright (c) Mikhail Grechanik <mike.grechanik@gmail.com>
 * @license https://github.com/mgrechanik/yii2-materialized-path/blob/master/LICENCE.md
 * @link https://github.com/mgrechanik/yii2-materialized-path
 */

namespace mgrechanik\yiimaterializedpath\tests\models;

use mgrechanik\yiimaterializedpath\MaterializedPathBehavior;

/**
 * This is the model class for table "animal".
 * 
 * The table with this model holds only one tree, 
 * in which all nodes (ar models) are connected hierarchically
 *
 * @property int $id
 * @property string $path Path to parent node
 * @property int $level Level of the node in the tree
 * @property int $weight Weight among siblings
 * @property string $name Name
 */
class Animal extends \yii\db\ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'animal';
    }
    
    public function behaviors()
    {
        return [
            'materializedpath' => [
                'class' => MaterializedPathBehavior::class,
                'modelScenarioForChildrenNodesWhenTheyDeletedAfterParent' => 'SCENARIO_NOT_DEFAULT',
            ],
        ];
    } 
    
    // Use this along with 'modelScenarioForChildrenNodesWhenTheyDeletedAfterParent' above
    // to set up transaction for process when main node is deleted and all it's descendants
    // need to move to their grandfather or to be deleted also
    public function transactions()
    {
        return [
            self::SCENARIO_DEFAULT => self::OP_DELETE,
        ];
    }
    
    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['level', 'weight'], 'integer'],
            [['name'], 'required'],
            [['path', 'name'], 'string', 'max' => 255],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'path' => 'Path to parent node',
            'level' => 'Level of the node in the tree',
            'weight' => 'Weight among siblings',
            'name' => 'Name',
        ];
    }
}
