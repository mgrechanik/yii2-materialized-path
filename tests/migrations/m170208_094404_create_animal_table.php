<?php
/**
 * This file is part of the mgrechanik/yii2-materialized-path library
 *
 * @copyright Copyright (c) Mikhail Grechanik <mike.grechanik@gmail.com>
 * @license https://github.com/mgrechanik/yii2-materialized-path/blob/master/LICENCE.md
 * @link https://github.com/mgrechanik/yii2-materialized-path
 */

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
