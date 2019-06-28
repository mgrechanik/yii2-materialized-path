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
 * This is the model class for table "menuitem".
 * 
 * It holds many trees.
 *
 * @property int $id
 * @property int $treeid The id of the menu this menuitem belongs to
 * @property string $path Path to parent node
 * @property int $level Level of the node in the tree
 * @property int $weight Weight among siblings
 * @property string $name Name
 */
class Menuitem extends \yii\db\ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'menuitem';
    }
    
    public function behaviors()
    {
        return [
            'materializedpath' => [
                'class' => MaterializedPathBehavior::class,
                'treeIdentityFields' => ['treeid'],
            ],
        ];
    }     

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['name'], 'required'],
            [['level', 'weight'], 'integer'],
            [['path'], 'string'],
            [['name'], 'string', 'max' => 255],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'treeid' => 'The id of the menu this menuitem belongs to',
            'path' => 'Path to parent node',
            'level' => 'Level of the node in the tree',
            'weight' => 'Weight among siblings',
            'name' => 'Name',
        ];
    }
}
