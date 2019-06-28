# Creating a catalog at Yii2  using [Materialized path](https://github.com/mgrechanik/yii2-materialized-path) extension

## Table of contents

* [Introduction](#intro)
* [Demonstration](#demo)
* [Program part](#program-part)
    * [Explanation of the architecture](#architecture)
    * [Migrations](#migration)
    * [Active Record model](#ar-model)
    * [Form model](#form)
    * [Form template](#form-template)
    * [Controller and templates](#controller)
    * [Service CatalogService](#service)
    * [Printing the tree](#output)	
    * [Other](#other)	

## Introduction <span id="intro"></span>

This article's goal is to give practical example of using `Materialized Path` extension by providing example of creating a catalog.

By term catalog we would mean Active Record models organized hierarchically among themselves.  
One database table holds only one catalog (one tree).

In this example we will solve the next tasks:
1. Creating table in the database using migration and creating AR model for it
2. Creating catalog page - a list of all elements - displayed as a tree along with CRUD operations links to elements
3. Creating a page of adding a new catalog item

	- Choosing a position in catalog will be done using `<select>` list with existed tree displayed in it
	- We need here a possibility to choose exact position where we want to add our new catalog item
	- Validation of correct choice

4. Creating a page of editing catalog item 

	- We need a possibility not to change position in the tree, some "view mode" for position
	- When editing position we also want to have a possibility to choose any position we want among allowed ones
	- The list of choice should not hold the current item or any of it's descendants
    - Validation of correct choice

5. Deleting we will leave as it is defined in this extension by default - when item is being deleted it's children move to it's parent

## Demonstration <span id="demo"></span>

As a result we will have the next functionality:
![catalog functionality](https://raw.githubusercontent.com/mgrechanik/yii2-materialized-path/master/docs/images/catalog.png "catalog functionality")

## Program part <span id="program-part"></span>

### Explanation of the architecture <span id="architecture"></span>

1. For this example we will use some simple layered structure
2. We will separate form functionality from  AR model functionality
3. Functionality of managing AR model we will place in separate [service](#service)
4. Lets use Advanced application template

### Migrations <span id="migration"></span>

We will need the next migration. Create `m180908_094404_create_catalog_table.php` with the code:

```php
use yii\db\Migration;

/**
 * Handles the creation of table `catalog`.
 */
class m180908_094404_create_catalog_table extends Migration
{
    /**
     * @inheritdoc
     */
    public function up()
    {
        $this->createTable('catalog', [
            'id' => $this->primaryKey(),
            'path' => $this->string(255)->notNull()->defaultValue('')->comment('Path to parent node'),
            'level' => $this->integer(4)->notNull()->defaultValue(1)->comment('Level of the node in the tree'),
            'weight' => $this->integer(11)->notNull()->defaultValue(1)->comment('Weight among siblings'),
            'name' => $this->string()->notNull()->comment('Name'),
        ]);
        
        $this->createIndex('catalog_path_index', 'catalog', 'path');
        $this->createIndex('catalog_level_index', 'catalog', 'level');
        $this->createIndex('catalog_weight_index', 'catalog', 'weight');        
        
    }

    /**
     * @inheritdoc
     */
    public function down()
    {
        $this->dropTable('catalog');
    }
}
```
**Important in the code above**:

1. Seeing that table holds only one tree we do not need to add column to identify a tree
2. From catalog itself there is only `name` column, all the rest are for materialized path extension purpose
	
### Active Record model <span id="ar-model"></span>

```php
namespace common\models;

use Yii;
use mgrechanik\yiimaterializedpath\MaterializedPathBehavior;

/**
 * This is the model class for table "catalog".
 *
 * @property int $id
 * @property string $path Path to parent node
 * @property int $level Level of the node in the tree
 * @property int $weight Weight among siblings
 * @property string $name Name
 */
class Catalog extends \yii\db\ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'catalog';
    }

    /**
     * {@inheritdoc}
     */
    public function behaviors()
    {
        return [
            'materializedpath' => [
                'class' => MaterializedPathBehavior::class,
                'modelScenarioForChildrenNodesWhenTheyDeletedAfterParent' => 'SCENARIO_NOT_DEFAULT',
            ],
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
    public function transactions()
    {
        return [
            self::SCENARIO_DEFAULT => self::OP_DELETE,
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
```	

**Important in the code above**:

1. This AR model is close to one generated by `Gii`, because it does not contain form functionality
2. We specify our `materializedpath` behavior which turns AR model into **tree node**.  
   The name of the fields are the same which the behavior expects so we do not need to specify them 
3. For need of deleting operation to work in transaction we did two things - 
   set up `transactions()` method and did additional adjustment of behavior.


### Form model <span id="form"></span>

#### Form model: 
```php
namespace frontend\models;

use Yii;
use yii\base\Model;
use mgrechanik\yiimaterializedpath\ServiceInterface;
use common\models\Catalog;

class CatalogForm extends Model
{
    const SCENARIO_CREATE = 1;
    const SCENARIO_UPDATE = 2;

    // operations with node

    const OP_VIEW = 1;
    const OP_APPEND_TO = 2;
    const OP_INSERT_BEFORE = 3;
    const OP_INSERT_AFTER = 4;

    // form fields

    public $name;

    public $newParent;

    public $operation;

    // service

    public $model;

    public $service;

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();
        $this->service = Yii::createObject(ServiceInterface::class);
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        $range = [self::OP_APPEND_TO, self::OP_INSERT_BEFORE, self::OP_INSERT_AFTER];
        if ($this->scenario == self::SCENARIO_UPDATE) {
            // View operation is allowed only for existed models
            $range[] = self::OP_VIEW;
        }
        $messageRange = $this->scenario == self::SCENARIO_CREATE ?
            'For new item you need to choose append or insert operation' : 'Invalid choise';

        return [
            [['name'], 'required'],

            [['name'], 'string', 'max' => 255],

            ['newParent', 'in', 'range' => $this->getValidParentIds()],

            [['newParent', 'operation'], 'required', 'on' => self::SCENARIO_CREATE],

            ['operation', 'in', 'range' => $range, 'message' => $messageRange],

            ['operation', 'in', 'not' => true,
                'range' => [self::OP_INSERT_BEFORE, self::OP_INSERT_AFTER],
                'when' => function($model) {
                    return $model->newParent < 0;
                },
                'whenClient' => "function (attribute, value) {
                    return $('#catalogform-newparent').val() < 0;
                }",
                'message' => 'You cannot insert before or after root node.'
            ]
        ];
    }

    public function scenarios()
    {
        return [
            self::SCENARIO_CREATE => ['name', 'newParent', 'operation'],
            self::SCENARIO_UPDATE => ['name', 'newParent', 'operation'],
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        $what = $this->model->isNewRecord ? 'add' : 'move';
        return [
            'name' => 'Name',
            'newParent' => 'Position',
            'operation' => 'Choose where to ' . $what . ' a catalog item'
        ];
    }

    public function chooseRoot()
    {
        $this->newParent = $this->getRootId();
    }

    public function getRootId()
    {
        $service = $this->service;
        $root = $service->getRoot(Catalog::class);
        return $root->getId();
    }

    public function getListItems()
    {
        $model = $this->model;
        $result = [];
        if ($this->scenario == CatalogForm::SCENARIO_UPDATE) {
            $result[CatalogForm::OP_VIEW] = 'Do not change position';
        }
        $result[ CatalogForm::OP_APPEND_TO] =
            $model->isNewRecord ? 'Append to' : 'Move to';
        $result[CatalogForm::OP_INSERT_BEFORE] = 'Insert before';
        $result[CatalogForm::OP_INSERT_AFTER] = 'Insert after';
        return $result;
    }

    /**
     * We need to exclude current model and all it's subtree from select choise list
     *
     * @return array
     */
    public function getExceptIds()
    {
        return $this->model->isNewRecord ? [] : [$this->model->id];
    }

    /**
     * Valid Ids of models to who this model could be added/moved to
     *
     * @return array
     */
    protected function getValidParentIds()
    {
        $service = $this->service;
        $root = $service->getRoot(Catalog::class);
        $exceptIds = $this->getExceptIds();
        return $service->buildSubtreeIdRange($root, true, $exceptIds);
    }
}
```

**Important in the code above**:

1. We set up four operations: "View", "Add to parent", "Insert before node", 
  "Insert after node"
2. `newParent` sets up the position in the tree. Range of allowed `id` values is formed according to scenario,
   because when editing we see catalog tree without editing node and all it's descendants
3. Also we are not allowed to insert our node "Before" or "After" the Root node of the tree, because the Root is only one in the tree 
and at the very top of it

#### Form template <span id="form-template"></span>
```php
use yii\helpers\Html;
use yii\widgets\ActiveForm;
use common\models\Catalog;
use frontend\models\CatalogForm;

/* @var $this yii\web\View */
/* @var $form yii\widgets\ActiveForm */
/* @var $catalogForm frontend\models\CatalogForm */

// building a flat tree
$service = $catalogForm->service;
$root = $service->getRoot(Catalog::class);
$tree = $service->buildFlatTree($root, true, true, $catalogForm->getExceptIds());
$items = $service->buildSelectItems($tree, function($node) {
    return ($node['id'] < 0) ? '- root' : '' . str_repeat('  ', $node['level']) . str_repeat('-', $node['level']) .
        ' ' . Html::encode($node['name']) . '';
});
// end building a flat tree

?>

<div class="catalog-form">

    <?php $form = ActiveForm::begin(); ?>

    <?= $form->field($catalogForm, 'name')->textInput(['maxlength' => true]) ?>
    <div class="row">
        <div class="col-xs-6">
    <?= $form->field($catalogForm, 'newParent')->listBox($items, ['encode' => false, 'encodeSpaces' => true, 'size' => 12]) ?>
        </div>
        <div class="col-xs-6">
            <?= $form->field($catalogForm, 'operation')->dropDownList($catalogForm->getListItems()) ?>
        </div>
    </div>
    <div class="form-group">
        <?= Html::submitButton('Save', ['class' => 'btn btn-success']) ?>
    </div>

    <?php ActiveForm::end(); ?>

</div>
```
**Important in the code above**:

1. Take a look at the steps we make to build a tree for `<select>` list:
	- we receive tree managing service
	- we find parent node which subtree we want to display. Seeing that we want do display all tree we choose Root node
	- we build flat tree with root node shown in it and settings for what we want to exclude (if we want to)
	- we create list for `select`-а. Levels are responsible for paddings


### Controller and templates <span id="controller"></span>

#### Controller
```php
namespace frontend\controllers;

use Yii;
use common\models\Catalog;
use common\models\search\CatalogSearch;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\filters\VerbFilter;
use frontend\models\CatalogForm;
use common\service\CatalogService;
use mgrechanik\yiimaterializedpath\ServiceInterface;
use yii\data\ArrayDataProvider;

/**
 * CatalogController implements the CRUD actions for Catalog model.
 */
class CatalogController extends Controller
{

    private $service;

    public function __construct($id, $module, CatalogService $service, $config = [])
    {
        parent::__construct($id, $module, $config);
        $this->service = $service;
    }
    /**
     * {@inheritdoc}
     */
    public function behaviors()
    {
        return [
            'verbs' => [
                'class' => VerbFilter::className(),
                'actions' => [
                    'delete' => ['POST'],
                ],
            ],
        ];
    }

    /**
     * Lists all Catalog models.
     * @return mixed
     */
    public function actionIndex()
    {
        // Getting our Catalog tree as simple array
        $treeService = Yii::createObject(ServiceInterface::class);
        $root = $treeService->getRoot(Catalog::class);
        // for dataProvider we want result indexed by id, that is last 'true' work
        $tree = $treeService->buildFlatTree($root, true, false, true);

        $dataProvider = new ArrayDataProvider([
            'allModels' => $tree,
        ]);

        return $this->render('catalogindex', [
            'dataProvider' => $dataProvider,
        ]);
    }

    /**
     * Displays a single Catalog model.
     * @param integer $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionView($id)
    {
        return $this->render('view', [
            'model' => $this->findModel($id),
        ]);
    }

    /**
     * Creates a new Catalog model.
     * If creation is successful, the browser will be redirected to the 'view' page.
     * @return mixed
     */
    public function actionCreate()
    {
        $model = new Catalog();

        $catalogForm = new CatalogForm(['model' => $model]);
        $catalogForm->scenario = CatalogForm::SCENARIO_CREATE;
        $catalogForm->chooseRoot();
        $catalogForm->operation = CatalogForm::OP_APPEND_TO;

        if ($catalogForm->load(Yii::$app->request->post()) && $catalogForm->validate()) {
            try {
                if ($id = $this->service->create($catalogForm, false)) {
                    return $this->redirect(['index']);
                }
            } catch (\DomainException $e) {
                Yii::$app->session->setFlash('error', $e->getMessage());
            }
        }

        return $this->render('create', [
            'catalogForm' => $catalogForm,
        ]);
    }

    /**
     * Updates an existing Catalog model.
     * If update is successful, the browser will be redirected to the 'view' page.
     * @param integer $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionUpdate($id)
    {
        $model = $this->findModel($id);

        $catalogForm = new CatalogForm(['model' => $model, 'name' => $model->name]);
        $catalogForm->scenario = CatalogForm::SCENARIO_UPDATE;
        $parent = $model->parent();
        $catalogForm->newParent = $parent->getId();
        $catalogForm->operation = CatalogForm::OP_VIEW;

        if ($catalogForm->load(Yii::$app->request->post()) && $catalogForm->validate()) {
            try {
                $this->service->update($model->id, $catalogForm, false);
                return $this->redirect(['index']);
            } catch (\DomainException $e) {
                Yii::$app->session->setFlash('error', $e->getMessage());
            }
        }

        return $this->render('update', [
            'catalogForm' => $catalogForm,
        ]);
    }

    /**
     * Deletes an existing Catalog model.
     * If deletion is successful, the browser will be redirected to the 'index' page.
     * @param integer $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionDelete($id)
    {
        $this->findModel($id)->delete();

        return $this->redirect(['index']);
    }

    /**
     * Finds the Catalog model based on its primary key value.
     * If the model is not found, a 404 HTTP exception will be thrown.
     * @param integer $id
     * @return Catalog the loaded model
     * @throws NotFoundHttpException if the model cannot be found
     */
    protected function findModel($id)
    {
        if (($model = Catalog::findOne($id)) !== null) {
            return $model;
        }

        throw new NotFoundHttpException('The requested page does not exist.');
    }
}

```

**Important in the code above**:

1. Using DI we inject `CatalogService` to which we pass our valid forms as DTO
2. In `actionIndex` we build categories tree like we did it above in the [form template](#form-template),
   but with difference that here we do not show Root node. And for `dataProvider` we needed
   result array to be indexed by model's `id`-s

#### catalogindex.php <span id="view-catalogindex"></span>

```php
use yii\helpers\Html;
use yii\grid\GridView;

/* @var $this yii\web\View */
/* @var $dataProvider yii\data\ArrayDataProvider */

$this->title = 'Catalog';
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="catalog-index">

    <h1><?= Html::encode($this->title) ?></h1>

    <p>
        <?= Html::a('Create catalog item', ['create'], ['class' => 'btn btn-success']) ?>
    </p>

    <?= GridView::widget([
        'dataProvider' => $dataProvider,
        'filterModel' => $searchModel,
        'columns' => [
            ['class' => 'yii\grid\SerialColumn'],

            'id',
            [
                'label' => 'Name',
                'value' => function($model, $key, $index, $column) {
                    return str_repeat('&nbsp;&nbsp;', $model['level'])
                        . str_repeat('-', $model['level']) .
                        ' ' . Html::encode($model['name']);
                },
                'format' => 'raw'
            ],
            'level',
            ['class' => 'yii\grid\ActionColumn'],
        ],
    ]); ?>
</div>

```

#### create.php <span id="view-create"></span>	   

```php
use yii\helpers\Html;


/* @var $this yii\web\View */
/* @var $model common\models\Catalog */

$this->title = 'Creating a catalog item';
$this->params['breadcrumbs'][] = ['label' => 'Catalog', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="catalog-create">

    <h1><?= Html::encode($this->title) ?></h1>

    <?= $this->render('_form', [
        'catalogForm' => $catalogForm,
    ]) ?>

</div>
```

#### update.php <span id="view-update"></span>	

```php
use yii\helpers\Html;

/* @var $this yii\web\View */
/* @var $model common\models\Catalog */
$model = $catalogForm->model;
$this->title = 'Update Catalog item: ' . $model->name;
$this->params['breadcrumbs'][] = ['label' => 'Catalog', 'url' => ['index']];
$this->params['breadcrumbs'][] = ['label' => $model->name, 'url' => ['view', 'id' => $model->id]];
$this->params['breadcrumbs'][] = 'Update';
?>
<div class="catalog-update">

    <h1><?= Html::encode($this->title) ?></h1>

    <?= $this->render('_form', [
        'catalogForm' => $catalogForm,
    ]) ?>

</div>
```  

#### view.php <span id="view-view"></span>

```php
use yii\helpers\Html;
use yii\widgets\DetailView;

/* @var $this yii\web\View */
/* @var $model common\models\Catalog */

$this->title = $model->name;
$this->params['breadcrumbs'][] = ['label' => 'Catalog', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="catalog-view">

    <h1><?= Html::encode($this->title) ?></h1>

    <p>
        <?= Html::a('Update', ['update', 'id' => $model->id], ['class' => 'btn btn-primary']) ?>
        <?= Html::a('Delete', ['delete', 'id' => $model->id], [
            'class' => 'btn btn-danger',
            'data' => [
                'confirm' => 'Are you sure you want to delete this item?',
                'method' => 'post',
            ],
        ]) ?>
    </p>

    <?= DetailView::widget([
        'model' => $model,
        'attributes' => [
            'id',
            'name',
            [
                'label' => 'Parent',
                'value' => function ($model, $widget){
                    $parent = $model->parent();
                    return $parent->isRoot() ? 'root' : $parent->name;
                }
            ],
            'level'
        ],
    ]) ?>

</div>
```
	   
### Service CatalogService <span id="service"></span>
```php
namespace common\service;

use Yii;
use common\models\Catalog;
use frontend\models\CatalogForm;
use mgrechanik\yiimaterializedpath\ServiceInterface;

class CatalogService
{
    private $service;

    public function __construct()
    {
        $this->service = Yii::createObject(ServiceInterface::class);
    }

    public function create(CatalogForm $form, $runValidation = true)
    {
        $model = new Catalog(['name' => $form->name]);
        $parent = $this->findModel($form->newParent);
        $result = $this->saveModel($model, $parent, $form->operation, $runValidation);
        if (is_null($result)) {
            throw new \DomainException('You need to choose where to add a new item');
        } elseif ($result) {
            return $model->id;
        }
    }

    public function update($id, CatalogForm $form, $runValidation = true)
    {
        $model = $this->findModel($id);
        $parent = $this->findModel($form->newParent);
        $model->name = $form->name;
        $result = $this->saveModel($model, $parent, $form->operation, $runValidation);
        if (is_null($result)) {
            $model->save($runValidation);
        }
    }

    protected function findModel($id)
    {
        // It finds AR model from table or RootNode when id is negative
        if ($model = $this->service->getModelById(Catalog::class, $id)) {
            return $model;
        }
        throw new NotFoundException('The catalog item does not exist.');
    }

    protected function saveModel($model, $parent, $operation, $runValidation)
    {
        switch ($operation)
        {
            case CatalogForm::OP_APPEND_TO :
                return $model->appendTo($parent, $runValidation);
            case CatalogForm::OP_INSERT_BEFORE :
                return $model->insertBefore($parent, $runValidation);
            case CatalogForm::OP_INSERT_AFTER :
                return $model->insertAfter($parent, $runValidation);
        }
        // no operations matched, so no saving done
        return null;
    }
}
```

**Important in the code above**:
1. Inside this catalog service we are using trees managing service: `$this->service`
2. Since in our forms we use Root position which is identified by negative `id` , in 
   `findModel` method we search for models using `$this->service->getModelById()`, who returns
   either AR model or RootNode model
3. The call of next methods - `appendTo`, `insertBefore`, `insertAfter` - results that model is being saved in the database
4. At creating new item scenario we are obliged to check that correct position in the tree are choosen
5. At editing existed item scenario we are given an operation when position does not change though all other
model data is being saved. It is happening when from form we get `CatalogForm::OP_VIEW` operation

### Print the tree at the page <span id="output"></span>
Now if you need to print this catalog tree in any template just use the next code:
```php
use mgrechanik\yiimaterializedpath\ServiceInterface;
use common\models\Catalog;
use mgrechanik\yiimaterializedpath\widgets\TreeToListWidget;

// get the trees managing service
$service = \Yii::createObject(ServiceInterface::class);
// Get the element relevant to who we build the tree.
// In our case it is the Root node
$root = $service->getRoot(Catalog::class);
// Build the tree from descendants of the Root node
$tree = $service->buildDescendantsTree($root);
// Print at the page 
print TreeToListWidget::widget(['tree' => $tree]);
```
*You will see the next tree:*

<ul>
<li>Laptops &amp; PC<ul>
<li>Laptops</li>
<li>PC</li>
</ul></li>
<li>Phones &amp; Accessories<ul>
<li>Smartphones<ul>
<li>Android</li>
<li>iOS</li>
</ul></li>
<li>Batteries</li>
</ul></li>
</ul>
   
### Other <span id="other"></span>
```php
namespace common\service;

class NotFoundException extends \DomainException
{
}
```




