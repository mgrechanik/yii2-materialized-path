<?php
/**
 * This file is part of the mgrechanik/yii2-materialized-path library
 *
 * @copyright Copyright (c) Mikhail Grechanik <mike.grechanik@gmail.com>
 * @license https://github.com/mgrechanik/yii2-materialized-path/blob/master/LICENCE.md
 * @link https://github.com/mgrechanik/yii2-materialized-path
 */

namespace mgrechanik\yiimaterializedpath\widgets;

use yii\base\Widget;
use yii\helpers\Html;

/**
 * Class TreeToListWidget
 *
 * This class displays php tree as ul-li nested list
 *
 * Example of use:
 * ```
 * use mgrechanik\yiimaterializedpath\widgets\TreeToListWidget;
 * use mgrechanik\yiimaterializedpath\ServiceInterface;
 * use common\models\Catalog;
 *
 * // getting our service for managing trees
 * $service = Yii::createObject(ServiceInterface::class);
 * // find for what we need to build a tree
 * $model15 = Catalog::findOne(15);
 * // this tree will have $model15 and all it's descendants
 * $tree = $service->buildTree($model15);
 * // or you can have a tree with only it's descendants
 * //$tree = $service->buildDescendantsTree($model15);
 *
 * // And now supposing that Catalog model have `name` field
 * // display it as nested ul-li list:
 * print TreeToListWidget::widget(['tree' => $tree]);
 * ```
 */
class TreeToListWidget extends Widget
{
    /**
     * @var array The tree we want to display
     * Tree here is a hierarchical structure of TreeNode objects
     */
    public $tree;

    /**
     * @var array Options for ul tags
     */
    public $ulOptions = [
        'encode' => false
    ];

    /**
     * @var string What field we are going to display as item's label
     * Used if [[labelCreateFunction]] is empty.
     * Expects nodes of the tree to be in array format.
     */
    public $labelFieldName = 'name';

    /**
     * @var callable The function to create  item's label
     * It's format: function($node){ return $node['name']}
     */
    public $labelCreateFunction;

    /**
     * @var bool Whether to encode item's label
     */
    public $encodeLabel = true;

    /**
     * @inheritdoc
     */
    public function run()
    {
        $items = [];
        foreach ($this->tree as $tnode) {
            $node = $tnode->node;
            $children = $tnode->children;
            $label = $this->createLabel($node);
            $items[] = empty($children) ?
                $label : $label .
                static::widget([
                    'tree' => $children,
                    'ulOptions' => $this->ulOptions
                ]);
        }
        print Html::ul($items, $this->ulOptions);
    }

    /**
     * Creating a label we will display as list's item
     *
     * @param array $node
     * @return string
     */
    protected function createLabel($node)
    {
        $function = $this->labelCreateFunction;
        $label = is_callable($function) ?
            $function($node) : $node[$this->labelFieldName];
        if ($this->encodeLabel) {
            $label = Html::encode($label);
        }
        return $label;
    }
}