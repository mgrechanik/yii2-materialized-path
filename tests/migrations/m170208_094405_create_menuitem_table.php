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
 * Handles the creation of table `menuitem`.
 */
class m170208_094405_create_menuitem_table extends Migration
{
    /**
     * @inheritdoc
     */
    public function up()
    {
        $this->createTable('menuitem', [
            'id' => $this->primaryKey(),
            'treeid' => $this->integer(4)->notNull()->comment('The id of the menu this menuitem belongs to'),
            'path' => $this->string(255)->notNull()->defaultValue('')->comment('Path to parent node'),
            'level' => $this->integer(4)->notNull()->defaultValue(1)->comment('Level of the node in the tree'),
            'weight' => $this->integer(11)->notNull()->defaultValue(1)->comment('Weight among siblings'),
            'name' => $this->string()->notNull()->comment('Name'),
        ]);
        
        $this->createIndex('menuitem_path_index', 'menuitem', 'path');
        $this->createIndex('menuitem_level_index', 'menuitem', 'level');
        $this->createIndex('menuitem_weight_index', 'menuitem', 'weight');
        $this->createIndex('menuitem_treeid_index', 'menuitem', 'treeid');
        
    }

    /**
     * @inheritdoc
     */
    public function down()
    {
        $this->dropTable('menuitem');
    }
}
