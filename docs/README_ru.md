# Yii2 Materialized Path Расширение

Данное расширение позволяет организовать Active Record модели в дерево по алгоритму Materialized Path

[English version](../README.md)

## Содержание

* [Возможности](#features)
* [Установка](#installing)
* [Миграции](#migration)
* [Настройка](#settings)
* [Объяснение структуры данных](#explaining-data)
* [Состав расширения](#explaining-extension-structure)
* [Работа с деревом](#work-with-tree)
    * [Корень дерева](#root)
	* [Выборки](#descendants)
    * [Навигация по дереву](#navigation)
	* [Характеристики узла](#properties)
	* [Вставка новых и перенос существующих узлов](#modification-edit)
	* [Удаление узла](#modification-delete)
* [Сервис по управлению деревьями](#service)
    * [Построение деревьев и их вывод](#building-trees)
	    * [Иерархическое дерево](#hierarchical-tree)
		* [Плоское дерево](#flat-tree)
	* [Клонирование](#service-cloning)
	* [Прочие возможности](#service-other)
* [Приложение А: Пример построения каталога](#appendix-a)
* [Приложение Б: Примеры работы с API](#appendix-b)

## Возможности <span id="features"></span>
* Позволяет организовать ActiveRecord объекты в дерево
* Каждое дерево имеет только один [корневой узел](#root)
* В одной таблице, при необходимости, можно хранить много непересекающихся деревьев, например пунктов меню у разных меню
* Множество способов обхода дерева и опроса текущего узла
* Операции модификации дерева: вставка новых узов, перенос существующих. Выполняются в т.ч. в `транзакции`
* Два режима при удалении узла, когда потомки тоже удаляются или когда потомки переносятся к его родителю
* Сервис по управлению деревьями позволяет:
    * Выбирать дерево (поддерево) одним запросом из БД
    * Дерево формируется в 2-у форматах: вложенная структура, удобная для отображения в виде `<ul>-<li>` списков
	  или "плоское" представление дерева - простой `php` массив, удобный к выводу в `<select>` списке или использованию с Data Provider
    * Клонирование поддеревьев	  
    * Получать диапазоны `id`-шек потомков узла, полезно для правил валидации



## Установка <span id="installing"></span>

Установка через composer:

Выполните
```
composer require --prefer-dist mgrechanik/yii2-materialized-path
```

или добавьте
```
"mgrechanik/yii2-materialized-path" : "^1.0"
```
в  `require` секцию вашего `composer.json` файла.

## Миграции <span id="migration"></span>

Данное расширение ожидает наличие в таблице дополнительных полей, отвечающих за хранение дерева.

Пример миграции для таблицы содержащей много деревьев смотрите [здесь](https://github.com/mgrechanik/yii2-materialized-path/blob/master/tests/migrations/m170208_094405_create_menuitem_table.php).

А вот пример [миграции](https://github.com/mgrechanik/yii2-materialized-path/blob/master/tests/migrations/m170208_094404_create_animal_table.php) для таблицы с одним деревом:

```php
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
```


*На что тут обратить внимание:*

1. Объяснение назначения полей будет дано в [объяснение структуры данных](#explaining-data)
2. Для `path` мы задали длину поля как `255` символов, что оптимально для `mysql`, и позволяет хранить деревья
огромной вложенности, но вы можете указать свою величину
3. `defaultValue` для полей `path`, `weight` и `level` проставлено на всякий случай, чтобы даже строки
добавленные не с помощью `api` расширения, а вручную (через `phpmyadmin` тот же), смогли занять свою начальную
позицию в дереве 
4. Для `SQLite` базы данных уберите из миграции выше `->comment()`-ы 


## Настройка <span id="settings"></span>

Чтобы превратить Active Record модель в **узел дерева**, к нему нужно подключить следующее поведение:

```php
use mgrechanik\yiimaterializedpath\MaterializedPathBehavior;

class Animal extends \yii\db\ActiveRecord
{
    public static function tableName()
    {
        return 'animal';
    }
	
    public function behaviors()
    {
        return [
            'materializedpath' => [
                'class' => MaterializedPathBehavior::class,
                // настройки поведения
            ],
        ];
    } 
	
	// ...
}	
```

У данного `MaterializedPathBehavior` поведения имеются следующие настройки:
1. `treeIdentityFields` - массив с названиями полей, которые служат уникальным идентификатором дерева.
2. `mpFieldNames` - карта соответствия названий полей в вашей модели, тем что используются в расширении.
Используйте если ваши названия отличаются от `id`, `path`, `level`, `weight`
3. `modelScenarioForAffectedModelsForSavingProcess` объяснен [тут](#modification-common)
4. Про `moveChildrenWnenDeletingParent` и `modelScenarioForChildrenNodesWhenTheyDeletedAfterParent` 
смотри в [удаление узла](#modification-delete).
5. `maxPathLength` можно опционально установить в некий предел длины поля `path` при превышении которого будет 
генерироваться исключение


## Объяснение структуры данных <span id="explaining-data"></span>

Данные в БД хранятся следующим образом:

Когда в таблице хранится только одно дерево:
![Представление дерева в БД](https://raw.githubusercontent.com/mgrechanik/yii2-materialized-path/master/docs/images/animal.png "Дерево в БД")

Когда в таблице хранится много деревьев: <span id="menuitem-table">
![Представление дерева в БД](https://raw.githubusercontent.com/mgrechanik/yii2-materialized-path/master/docs/images/menuitem.png "Дерево в БД")

> Для дальнейших примеров будем использовать данные деревья.  
> Для первой таблицы создана ActiveRecord модель [Animal](https://github.com/mgrechanik/yii2-materialized-path/blob/master/tests/models/Animal.php).  
> Для второй таблицы создана ActiveRecord модель [Menuitem](https://github.com/mgrechanik/yii2-materialized-path/blob/master/tests/models/Menuitem.php).


Итак общее что мы видим:
* Если в таблице хранится много деревьев, как в `Menuitem`, то заводится специальный столбец(цы) с уникальным идентификатором дерева.
  Как `treeid` на примере. И все дальнейшие манипуляции с деревом будут изолированными, касаться только его, 
  другие деревья никак не учитывая
* Каждое дерево имеет только один корень, который не присутствует в БД, т.е. является виртуальным узлом, но его можно получить
  и работать с ним как и с любым другим узлом. [Подробнее](#root)
* Узел `Animal(1) - cat` является первым ребенком этого корневого узла
* `id` столбец - тут уникальный номер узла дерева. Расширение требует использование только положительных чисел в качестве `id`-шек узлов.  
* `path` столбец - тут хранится **путь к родителю** данного узла. В виде `id`-шек разделенных `/`. Для детей корня тут будет пусто.
* `level` столбец - тут хранится уровень, на котором расположен данный узел, для корня - 0, и т.д.
* `weight` столбец - здесь хранится "вес" узла среди его соседей. Соседи отсортированы между собой по этому столбцу

Вот такая архитектура гарантирует эффективную и достаточную структуру для хранения дерева в БД:
* Храним, если надо, признак принадлежности к нужному дереву
* Каждый узел , благодаря `path`,  знает своего родителя
* И между соседями узел располагается в зависимости от `weight`

> Отличие от других часто встречающихся решений
> 1) В других расширениях бывает что такие узлы как `Animal(1) - cat` специальным образом трактуют как корневые. 
> И получается что дерево имеет много корневых узлов. В данном расширении такой узел один, не представленный в БД, 
> т.е. все узлы организованы в **ОДНО** дерево  
> 2) Также часто в колонке с путем хранят его полный путь, с учетом `id`-шки самого узла.  
> В данном расширении хранится только путь к родителю. У соседних узлов данное поле будет совпадать

## Состав расширения <span id="explaining-extension-structure"></span>
Данное расширение предоставляет две вещи:
* `mgrechanik\yiimaterializedpath\MaterializedPathBehavior` - данное поведение присоединяется к ActiveRecord модели,
превращая ее этим в узел дерева, добавляя к ней соответствущий функционал 
* `mgrechanik\yiimaterializedpath\Service` - данный [сервис](#service) предназначен для дополнительных операций по управлению 
деревьями: построение и вывод деревьев (поддеревьев), получение корневых узлов, клонирование и другое.

## Работа с деревом <span id="work-with-tree"></span>

### Корень дерева <span id="root"></span>

#### Общее <span id="root-common"></span>

Каждое дерево имеет **один** корневой узел (дальше - просто "корень") - узел, который располагается на самом верху и не имеет родителя.

Мы в таблице БД не сохраняем запись под корень, т.к. о нем заведомо известно что он есть у любого дерева.
Таким образом нам его дополнительно не требуется в БД создавать чтобы начать наполнять дерево.   
Считаем что он уже есть.  
А в таблице мы храним реально добавленные в дерево данные. Начиная с узлов 1-ого уровня - `cat`, `dog`, `snake`, `bear`.

Тем не менее с этим корневым узлом мы можем работать также как и с остальными узлами:
* можем опрашивать его потомков, получим все наше дерево
* можем добавлять к нему узлы, они станут узлами 1-ого уровня
* но в случае с корневым узлом, работают только логически допустимые вещи. Добавить к корню можно, 
а вот вставить до/после не выйдет, т.к. это корень

>Технически корень дерева представлен в виде объекта `mgrechanik\yiimaterializedpath\tools\RootNode` класса, фиктивной
>AR модели которую не стоит пытаться сохранять в базу (будет исключение), но на которой точно также висит `MaterializedPathBehavior`,
>соответственно с ней можно работать как с любым другим узлом.

#### Id корневого узла <span id="root-id"></span>

Как мы говорили выше в путях `path` не указываем `id` корня (т.к. его на самом деле нет в таблице), но тем не менее
корневые узлы имеют свой `id`, это контролируется через `RootNode::getId()`. Это нужно главным образом для форм 
редактирования, когда нужно выбирать корень в форме и его надо как то отличать от других узлов по идентификатору.

Данное `id` корня формируется по простому алгоритму: 
* это будет обязательно отрицательное число
* если дерево в таблице одно то его значение будет `-100`
* если деревьев много то вычисляться будет по формуле `-100 * (treeField1 + treeField1 + treeFieldi)`, т.е. для 
`['treeid' => 2]` `id` будет `-200`

#### Работа с корневым узлом <span id="root-work"></span>

Для того чтобы работать с корневым узлом его объект надо сначала получить.  
Это делается следующими способами:
1) **Через имя AR модели** (и необязательное условие для дерева) <span id="get-root"></span>
```php
use mgrechanik\yiimaterializedpath\ServiceInterface;
// AR class
use mgrechanik\yiimaterializedpath\tests\models\Animal;
// сервис по управлению деревьями
$service = \Yii::createObject(ServiceInterface::class);
// получаем
// корень дерева
$root = $service->getRoot(Animal::class);  
```
Если у вас в одной таблице несколько деревьев, то для получения корня нужного дерева требуется указать условие для этого дерева:
```php
use mgrechanik\yiimaterializedpath\tests\models\Menuitem;
// ...
// корень первого дерева
$root1 = $service->getRoot(Menuitem::class, ['treeid' => 1]);
// корень второго дерева
$root2 = $service->getRoot(Menuitem::class, ['treeid' => 2]);
```

2) **Имея любую существующую AR модель** (не новую) можно получить корень дерева, к которому она принадлежит:
```php
$model7 = Animal::findOne(7); // 'stag'
// корень дерева
$root = $model7->getRoot();
```
Для всех узлов одного дерева из кеша будет возвращаться один и тот же объект корня (можно проверять через `===` оператор). 

3) **Через его отрицательное `id`**  <span id="get-modelbyid"></span>
```php
$root = $service->getModelById(Animal::class, -100); 
```
Используйте этот метод получения узла, если id-шки приходят из формы, где можно выбрать и корень и обычный узел.

Примеры: [1](#example-root), [2](#example-insertaschildatpos).

### Выборки <span id="queries"></span>

Выбрав любой узел дерева, включая [корень](#root), вы можете получать следующую информацию:

1) **Получение потомков узла** <span id="descendants"></span>

Получаем объект запроса на потомков данного узла:

```php
               $node->getDescendantsQuery($exceptIds = [], $depth = null)
```
- Отдельно через `$exceptIds` можно указать какие поддеревья исключать из выборки, формат вида `[1,  2, 3 => false]`,
что означает полностью исключить поддеревья с вершинами `1`, `2`, узел `3` оставить, а вот всех его потомков исключить
- `$depth` указывает как много уровней потомков выбирать  
`null` означает выбирать всех потомков,  
`1` означает выбирать в глубину только на один уровень, т.е. только детей данного узла  
и т.д.  

Пример:
```php
$model = Animal::findOne(1);
$query = $model->getDescendantsQuery();
$result = $query->asArray()->all();
```
Полученный результат будет отсортирован по `level ASC, weight ASC`, т.е. не будет готов к выводу в виде дерева, 
для построения `php` деревьев смотрите [здесь](#building-trees).

2) **Получение объекта запроса** <span id="common-query"></span>

Когда вам потребуется построить свой специфический запрос к узлам дерева, вместо непосредственной работы с таблицей
через `ClassName::find()` лучше начать с данного объекта запроса:

```php
               $node->getQuery()
```
- Этот объект запроса несет в себе **условие принадлежности** узла к конкретному дереву 
- С этого объекта начинаются все выборки данного расширения
- С [корневого узла](#root) его также можно получить
- Технически его реализацию можете увидеть в `RootNode::getQuery()`
- Начинать новые свои условия нужно уже с `andWhere()`

----

### Навигация по дереву <span id="navigation"></span>

1) **Получение корня дерева**
```php
               $node->getRoot()
```
Работает только для существующих в БД моделей, т.к. новая модель еще не принадлежит никакому дереву.  
Возвращает один и тот же объект для любого узла дерева, т.к. в дереве один [корень](#root).

2) **Работа с детьми узла**

*Получение всех детей узла:*
```php
               $node->children()
```

Получим массив AR моделей **непосредственных** детей данного узла.

Или используя более общий объект запроса:

```php
               $node->getChildrenQuery($sortAsc = true)
``` 
Пример:
```php
$query = $model->getChildrenQuery();
$result = $query->asArray()->all();
```

*Получение первого ребенка узла:*
```php
               $node->firstChild()
```

*Получение последнего ребенка узла:*
```php
               $node->lastChild()
```

3) **Работа с родителями узла**

*Получение родителя:*
```php
               $node->parent()
``` 

*Получение родителей:*
```php
               $node->parents($orderFromRootToCurrent = true, $includeRootNode = false, $indexResultBy = false)
```
Здесь:
- `$orderFromRootToCurrent` - сортировать родителей от корня к текущему или наоборот
- `$includeRootNode` - включать ли корневой узел в результат
- `$indexResultBy` - индексировать ли результат по `id`-шкам моделей

*Получение `id`-шек родителей:*
```php
               $node->getParentIds($includeRootNode = false)
```			   
Здесь:
- В результате `id`-шки узлов будут указаны начиная от корня и следуя к текущему родителю узла
- `$includeRootNode` - включать ли `id` корневого узла в результат

4) **Работа с соседями узла**

*Получаем:*

*Всех соседей:*
```php
               $node->siblings($withCurrent = false, $indexResultBy = false)
```
Здесь:
- `$withCurrent` - включать ли текущий узел в результат
- `$indexResultBy` - индексировать ли результат по `id`-шкам моделей

*Одного следующего:*
```php
               $node->next()
```
*Одного предыдущего:*
```php
               $node->prev()
```
*Всех следующих:*
```php
               $node->nextAll()
```
*Всех предыдущих:*
```php
               $node->prevAll()
```	
*Получить позицию текущего узла среди соседей (начинается с нуля):*
```php
               $node->index()	
```			   

### Характеристики узла <span id="properties"></span>

Над узлом можно осуществлять следующие проверки:

*Узнаем корневой ли он:*
```php
               $node->isRoot()
```	
*Узнаем является ли узел - листом, т.е. не имеет потомков:*
```php
               $node->isLeaf()
```	
*Узнаем является ли любым потомком указанного узла:*
```php
               $node->isDescendantOf($node)
```
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;,аргумент `$node` можно или ActiveRecord объект или число - `primary key` ноды. 

*Узнаем является ли ребенком указанного узла:*
```php
               $node->isChildOf($node)
```
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;, `$node` как выше 

*Узнаем является узлы соседями:*
```php
               $node->isSiblingOf($node)
```
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;, `$node` только ActiveRecord

Также об узле можно узнать следующие данные:

*Полный путь к данному узлу:*
```php
               $node->getFullPath()
```
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;, данный путь включает то что у узла в поле `path` , соединенное с `id` 
данного узла. Т.е. для узла `Animal(5)` данное значение будет `'1/5'`

*Id узла:*
```php
               $node->getId()
```
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;, данная обертка удобна, т.к. сработает в том числе и для корневого узла (даст отрицательный `id`)

*Уровень узла:*
```php
               $node->getLevel()
```

*Условие для дерева к которому принадлежит данный узел:*
```php
               $node->getTreeCondition()
```
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;, вернет массив вида `['treeid' => 2]` для таблицы как `Menuitem`

*Получить имена полей, используемых данным расширением:*
```php
               $node->getIdFieldName()
               $node->getPathFieldName()
               $node->getLevelFieldName()
               $node->getWeightFieldName()
```

### Вставка новых и перенос существующих узлов  <span id="modification-edit"></span>

#### Общая информация <span id="modification-common"></span>

Общие особенности которые нужно знать при вставке/переносе узлов:

1. Одни и те же методы работают как для вставки новых так и для перемещения существующих узлов
2. Сигнатура каждого метода модификации является следующей:
    - `$node->appendTo($node, $runValidation = true, $runTransaction = true)`
    - Эти методы физически сохраняют модель, т.е. работают как `ActiveRecord::save()`, соответственно
есть возможность проверки модели на валидность перед сохранением через `$runValidation`
    - Возвращают результат `true`/`false` успешности или не успешности операции
    - Часто вставка/перенос одной записи имеет такие последствия что многие другие записи также потребуют
изменения и сохранения, и всю эту операцию удобно осуществлять в одной транзакции 
используя `$runTransaction`.
    - Также имеется настройка `MaterializedPathBehavior::$modelScenarioForAffectedModelsForSavingProcess`
которая позволяет задать какой то свой `сценарий` для вот этих дополнительно затронутых моделей перед их сохранением
3. [Корневой узел](#root) в данных операциях может быть использован только в сценарии дабавления/переноса узла к нему
4. Все такие операции вставки как `prependTo/insertBefore(After)` требуют перенастройки веса `weight` у новых соседей.
Данное расширение при этом не занимается поиском свободных интервалов у `weight` или чем то подобным, 
а перестраивает веса всех соседей заново, с соответствующим сохранением всех этих моделей-соседей
5. В ситуации когда в таблице много деревьев:
    - При создании новых узлов *условие для дерева* им проставится такое же как у того узла относительно которого добавляем
    - Для существующих узлов перенос в другое дерево недопустим	

---

**Итак сами операции**

#### Добавление/перенос узла к новому родителю на позицию последнего ребенка

```php
               $model->appendTo($node, $runValidation = true, $runTransaction = true)
```

- `$model` добавится/перенесется к новому родителю `$node` и расположится в конце его списка детей
- При переносе все потомки `$model` естественно тоже с ней переносятся

Имеем также полностью зеркальную операцию к вышеописанной, когда к узлу слева добавляют новые узлы:

```php
               $model->add($child, $runValidation = true, $runTransaction = true)
```	
Примеры: [1](#example-root-1), [2](#example-root-2), [3](#example-append)

#### Добавление/перенос узла к новому родителю на позицию первого ребенка

```php
               $model->prependTo($node, $runValidation = true, $runTransaction = true)
```

- `$model` добавится/перенесется к новому родителю `$node` и расположится в начале его списка детей	

[Пример](#example-prepend)

#### Добавление/перенос узла на позицию перед указанным узлом

```php
               $model->insertBefore($node, $runValidation = true, $runTransaction = true)
```	   
- `$model` добавится/перенесется на позицию перед `$node`. Родитель у них станет один и тот же	

[Пример](#example-insertbefore).		   

#### Добавление/перенос узла на позицию после указанного узла

```php
               $model->insertAfter($node, $runValidation = true, $runTransaction = true)
```	   
- `$model` добавится/перенесется на позицию после `$node`

[Пример](#example-insertafter).		   

#### Добавление/перенос узла к новому родителю на позицию выраженную числом

```php
               $model->insertAsChildAtPosition($node, $position, $runValidation = true, $runTransaction = true)
```	   
- `$model` добавится/перенесется к новому родителю `$node` и среди его детей станет на позицию `$position` (отсчет с нуля)
- это по сути обертка над перечисленными выше методами

[Пример](#example-insertaschildatpos)

### Удаление узла  <span id="modification-delete"></span>

Удаление узла осуществляется через имеющийся в `Yii` метод `ActiveRecord::delete()` 
```php
               $model->delete()
```
При удалении узла настройка `MaterializedPathBehavior::$moveChildrenWnenDeletingParent` поведения обеспечивает 
2 варианта для потомков удаляемого узла:
1. `true` - потомки переносятся к родителю удаляемого узла (через `appendTo`). Установлена по умолчанию.
2. `false` - потомки удаляются вместе с ним

Т.к. метод `delete()` встроенный, то о выполнении его в транзакции вы должны позаботиться сами, согласно 
[документации](https://www.yiiframework.com/doc/guide/2.0/en/db-active-record#transactional-operations).
Пример такой настройки смотрите на примере [модели каталога](catalog_ru.md#ar-model)

Способом выше мы обернули транзакцией операцию удаления, но если потомки тоже должны удаляться, то нам не интересно
чтобы каждая из них также удалялась во вложенной транзакции, поэтому настройкой 
`MaterializedPathBehavior::$modelScenarioForChildrenNodesWhenTheyDeletedAfterParent` мы таким потомкам перед удалением
 устанавливаем какой то свой, отличный от того что в `transactions()`, сценарий.
 
[Пример](#example-delete) 
 
## *Сервис* по управлению деревьями <span id="service"></span> 

### Общее
Данный сервис обеспечивает дополнительный функционал по управлению деревьями, когда нам нужно оперировать многими
узлами.

Получаем данный сервис так:
```php
use mgrechanik\yiimaterializedpath\ServiceInterface;
// сервис по управлению деревьями
$service = \Yii::createObject(ServiceInterface::class);
```
Или инжектим через `DI`.  
Технически синглтон для данного сервиса определяется в бутстрапе расширения:
`mgrechanik\yiimaterializedpath\tools\Bootstrap`.

### Построение деревьев и их вывод <span id="building-trees"></span>

#### Общая информация

Как мы видели [выше](#descendants) выборка `$node->getDescendantsQuery()` (работает и для [корневого узла](#root), если нужно все дерево) 
одним запросом из БД получает нужное кол-во потомков данного узла. Но данный массив записей требуется представить в виде 
удобном для работы (в т.ч. отображения) как дерево. 

Поэтому в данном сервисе мы преобразовываем эту структуру в два вида `php` деревьев:
- `buildTree`, `buildDescendantsTree` строят [иерархическую](#hierarchical-tree) структуру в виде связанных между собой специального типа узлов.
Эта структура удобна для **рекурсивного** вывода дерева в виде вложенных `<ul>-<li>` списков
- `buildFlatTree` на основе информации выше строит ["плоское" дерево](#flat-tree) - как простой одноразмерный массив узлов с учетом их расположения.
Удобно для вывода в админке простым `foreach`, например в виде `<select>` списка, или для Data Provider-а.

#### Иерархическое дерево <span id="hierarchical-tree"></span>

```php
               $service->buildTree($parent, $isArray = true, $exceptIds = [], $depth = null)
               $service->buildDescendantsTree($parent, $isArray = true, $exceptIds = [], $depth = null)
```	
*Данные методы построят следующее дерево*:
1. Общий алгоритм такой
	- Выбираем/находим узел от которого отталкиваемся при постройке дерева потомков
	- Строим дерево 
	- Выводим его 
2. Результат будет массивом  объектов типа `mgrechanik\yiimaterializedpath\tools\TreeNode`, т.е. узлов,
которые уже в своих св-вах `children` будут ссылаться на своих детей
3. `buildTree` строит дерево начиная с `$parent` узла, а `buildDescendantsTree` начнет дерево с детей у `$parent`
4. `isArray` - это выбираем в каком формате (массив или AR объект) сложить наши данные в `TreeNode::$node`
5. `$exceptIds` как [выше](#descendants)
6. `$depth` как [выше](#descendants)
7. Выводится на страницу такое дерево в виде вложенного `<ul>-<li>` списка простым виджетом вроде -
`mgrechanik\yiimaterializedpath\widgets\TreeToListWidget`. Данный виджет имеет совсем базовый функционал, идет как
пример, но имеет возможность сформировать по своим правилам надпись для узла дерева

Пример:

```php
use mgrechanik\yiimaterializedpath\ServiceInterface;
use mgrechanik\yiimaterializedpath\tests\models\Animal;
use mgrechanik\yiimaterializedpath\widgets\TreeToListWidget;

$service = \Yii::createObject(ServiceInterface::class);

// 1) выбираем модель
$model1 = Animal::findOne(1);
```

```php
// 2) строим дерево
$tree = $service->buildTree($model1);
```
Получим схематично следующую структуру:

```
Array
(
    [0] => TreeNode Object
        (
            [node] => Array ([id] => 1, [name] => cat, ...))    // <---- Сам узел с id=1
            [parent] => 
            [children] => Array                                 // <---- Это его дети (МАССИВ-Z)
                (
                    [0] => TreeNode Object
                        (
                            [node] => Array ([id] => 5, [name] => mouse, ...)
                            [parent] => ...
                            [children] => Array
                                (
                                    [0] => TreeNode Object
                                        (
                                            [node] => Array ([id] => 7, [name] => stag, ...)
                                            [parent] => ...
                                            [children] => Array ()
                                        )
                                )
                        )
                    [1] => TreeNode Object
                        (
                            [node] => Array ( [id] => 6, [name] => fox, ... )
                            [parent] => ...
                            [children] => Array ()
                        )
                )
        )
)
```

> А если бы мы строили через `$tree = $service->buildDescendantsTree($model1);` то результат был бы - МАССИВ-Z выше

```php
// 3) теперь выводим это дерево:
print TreeToListWidget::widget(['tree' => $tree]);
```
Получим:
<ul>
<li>cat<ul>
<li>mouse<ul>
<li>stag</li>
</ul></li>
<li>fox</li>
</ul></li>
</ul>

Html у этого кода следующий:

```html
<ul>
    <li>cat
        <ul>
            <li>mouse
                <ul>
                    <li>stag</li>
                </ul>
            </li>
            <li>fox</li>
        </ul>
    </li>
</ul>
```

*Пример вывода ВСЕГО дерева:*

Код:
```php
$root = $service->getRoot(Animal::class);
$tree = $service->buildDescendantsTree($root);
print TreeToListWidget::widget(['tree' => $tree]);
```
Выведет:
<ul>
<li>cat<ul>
<li>mouse<ul>
<li>stag</li>
</ul></li>
<li>fox</li>
</ul></li>
<li>dog</li>
<li>snake<ul>
<li>lion</li>
<li>hedgehog</li>
</ul></li>
<li>bear</li>
</ul>

*Пример вывода двух первых уровней дерева (используем параметр `$depth`):*

Код:
```php
$root = $service->getRoot(Animal::class);
$tree = $service->buildDescendantsTree($root, true, [], 2);
print TreeToListWidget::widget(['tree' => $tree]);
```
Выведет:
<ul>
<li>cat<ul>
<li>mouse</li>
<li>fox</li>
</ul></li>
<li>dog</li>
<li>snake<ul>
<li>lion</li>
<li>hedgehog</li>
</ul></li>
<li>bear</li>
</ul>


-----

#### Плоское дерево <span id="flat-tree"></span>

Под "плоским" мы будем понимать дерево в виде простого массива, за один проход `foreach`-ем которого
можно вывести дерево вида:
```
- root             
  - (1) cat        
    -- (5) mouse   
      --- (7) stag 
    -- (6) fox     
  - (2) dog        
  - (3) snake      
    -- (8) lion    
    -- (9) hedgehog
  - (4) bear 
```

Такое дерево создается:

```php
           $service->buildFlatTree($parent, $isArray = true, $includeItself = false, $indexBy = false, $exceptIds = [], $depth = null)
```	
*Данный метод построит следующее дерево*:
1. Общий алгоритм такой
	- Выбираем/находим узел от которого отталкиваемся при постройке дерева потомков
	- Строим дерево 
	- Выводим его 
2. Результат будет массивом узлов, представленных как ассоц. массивы (`$isArray = true`) или AR объекты
3. `$includeItself` определяет с чего начать дерево - с `$parent` при `$includeItself = true`, или с его потомков
4. `$indexBy` - индексировать ли массив результата по `id`-шкам. Может быть полезным при использовании как Data Provider
5. `$exceptIds` как [выше](#descendants).
6. `$depth` как [выше](#descendants).

Пример:

```php
// 1) выбираем узел
$root = $service->getRoot(Animal::class);
```

```php
// 2) строим дерево
$tree = $service->buildFlatTree($root);
```
Получим следующий массив:
```
Array
(
    [0] => Array               // Чтобы ключи были как у id-шек см $indexBy выше 
        (
            [id] => 1
            [path] => 
            [level] => 1
            [weight] => 1
            [name] => cat
        )
    [1] => Array
        (
            [id] => 5
            [path] => 1/
            [level] => 2
            [weight] => 1
            [name] => mouse
        )
    [2] => Array
        (
            [id] => 7
            [path] => 1/5/
            [level] => 3
            [weight] => 1
            [name] => stag
        )
    // .....
    // .....
    [8] => Array
        (
            [id] => 4
            [path] => 
            [level] => 1
            [weight] => 4
            [name] => bear
        )
)
```

Этот массив уже готов (с использованием `$indexBy`) для работы как Data Provider, пример смотрите 
страницу просмотра каталога - `actionIndex` -  в [контроллере каталога](catalog_ru.md#controller).

Для того чтобы такой массив преобразовать в `$items` для встроенного в `Yii` `listBox` списка 
понадобится следующий хэлпер:

```php
           $service->buildSelectItems($flatTreeArray, callable $createLabel, $indexKey = 'id', $isArray = true);
```		   
1. `$flatTreeArray` - массив полученный выше через `buildFlatTree`
2. `$createLabel` - анонимная функция, которая сформирует название пункта дерева. 
   На входе этой функции текущий узел, возвращает строку - надпись пункта.
3. `$indexKey` - по какому полю индексировать
4. Результат будет массив опций вида `[id1 => label1, id2 => label2, ...]`


```php
// 3) Строим select список
$items = $service->buildSelectItems($tree,
	function($node) {
		return ($node['id'] < 0) ? '- root' : str_repeat('  ', $node['level']) . str_repeat('-', $node['level']) . 
				' (' . $node['id'] . ') ' . $node['name'];
	}
); 
```
Который покажет следующее дерево:
```
  - (1) cat        
    -- (5) mouse   
      --- (7) stag 
    -- (6) fox     
  - (2) dog        
  - (3) snake      
    -- (8) lion    
    -- (9) hedgehog
  - (4) bear 
```

Пример вывода **всего** дерева включая [корень](#root):
```php
$root = $service->getRoot(Animal::class);
$tree = $service->buildFlatTree($root, true, true);
$items = $service->buildSelectItems($tree,
	function($node) {
		return ($node['id'] < 0) ? '- root' : str_repeat('  ', $node['level']) . str_repeat('-', $node['level']) . 
				' (' . $node['id'] . ') ' . $node['name'];
	} 
); 
```
Покажет:
```
- root             
  - (1) cat        
    -- (5) mouse   
      --- (7) stag 
    -- (6) fox     
  - (2) dog        
  - (3) snake      
    -- (8) lion    
    -- (9) hedgehog
  - (4) bear 
```
В работе вы можете посмотреть данный код, в [форме редактирования](catalog_ru.md#form-template) элемента каталога.

-----

### Клонирование <span id="service-cloning"></span>

```php
           $service->cloneSubtree($sourceNode, $destNode, $withSourceNode = true, $scenario = null)
```	
1. Общее:
    - Операция пройдет в транзакции
	- Клонирование возможно только между моделями одного типа
	- Клонировать можно в другое дерево
	- `$sourceNode`, `$destNode` - Active Record модели или [корневые узлы](#root)
2. `$sourceNode` - вершина того поддерева что клонируем
3. `$destNode` - тот узел к которому клонируем
4. `$withSourceNode` - начинать клонировать именно с `$sourceNode` или с ее детей.  
Например, нужно устанавливать в `false` если `$sourceNode` - это [корень](#root), склонируется все дерево
5. `$scenario` - если указано, то установит данный сценарий склонированным моделям перед сохранением

[Примеры с клонированием](#example-cloning)

-----

### Прочие возможности <span id="service-other"></span>

#### Получение корня дерева <span id="serice-get-root"></span>

```php
           $service->getRoot($className, $treeCondition = [])
```	
1. `$className` - имя ActiveRecord модели
2. `$treeCondition` - условие для дерева, в случае когда в одной таблице хранится множество деревьев.
Массив вида `['treeid' => 1]`

#### Получение любого узла по его `id` <span id="serice-get-modelbyid"></span>

```php
           $service->getModelById($className, $id, $treeCondition = [])
```	
1. Обертка над `$className::findOne($id)` которая может найти и корень дерева по отрицательному `$id`
Используется когда, например, из формы можно выбрать корневой элемент наряду с остальными
2. `$className` - имя ActiveRecord модели
3. `$id` - уникальный идентификатор модели или отрицательное значение для корневого узла
2. `$treeCondition` - условие для дерева, в случае когда в одной таблице хранится множество деревьев.
Указывается только если условие состоит из 2-ух полей. Для условий с одним полем, таким как `['treeid' => 2]` пропускаем, он вычисляется по `$id`.

#### Получение id-шек узлов поддерева <span id="service-get-id-range"></span>

```php
           $service->buildSubtreeIdRange($parent, $includeItself = false, $exceptIds = [], $depth = null)
```		   
1. Позволяет получить массив `id`-шек узлов у поддерева с вершиной в `$parent`
2. `$includeItself` - включая ли `$parent`
3. `$exceptIds` как [выше](#descendants).
4. `$depth` как [выше](#descendants).
5. Данный функционал интересен например для `yii\validators\RangeValidator`

####  Условие для дерева к которому принадлежит данный узел <span id="service-get-tree-condition"></span>
```php
           $service->getTreeCondition($model)
```	
1. `$model` - проверяемый узел
2. Вернет массив вида `['treeid' => 1]` - условие принадлежности узла `$model` к его дереву

####  Получение родительской id-шки из пути <span id="service-get-parentid"></span>
```php
           $service->getParentidFromPath($path)
```
1. `$path` - путь
2. Вернет из пути, если присутствует, последнюю `id`-шку или `null`

## Приложение А: Пример построения каталога <span id="appendix-a"></span>

Пример как создавать/редактировать узлы дерева в админке и отображать деревья мы покажем в руководстве
[создание каталога на yii2](catalog_ru.md), в котором можно увидеть данную архитектуру в работе.

## Приложение Б: Примеры работы с API <span id="appendix-b"></span>

### Общее

Все примеры будут работать с таблицей `Animal` в следующем ее стартовом состоянии:
```
- root             
  - (1) cat        
    -- (5) mouse   
      --- (7) stag 
    -- (6) fox     
  - (2) dog        
  - (3) snake      
    -- (8) lion    
    -- (9) hedgehog
  - (4) bear 
```

Также неявно для всех примеров присутствует следующее начало: 
```php
use mgrechanik\yiimaterializedpath\ServiceInterface;
use mgrechanik\yiimaterializedpath\tests\models\Animal;
// сервис по управлению деревьями
$service = \Yii::createObject(ServiceInterface::class);
```

### Работа с корнем дерева <span id="example-root"></span>

#### Добавляем к корню новый узел через `add()` или `appendTo()` <span id="example-root-1"></span>

Или так:
```php
$root = $service->getRoot(Animal::class);
$root->add(new Animal(['name' => 'new']));
```
Или так:
```php
$root = $service->getRoot(Animal::class);
$newModel = new Animal(['name' => 'new']);
$newModel->appendTo($root);
```
Произойдет:
```
- root                        - root
  - (1) cat                     - (1) cat
    -- (5) mouse                  -- (5) mouse
      --- (7) stag                  --- (7) stag
    -- (6) fox                    -- (6) fox
  - (2) dog            ==>      - (2) dog
  - (3) snake                   - (3) snake
    -- (8) lion                   -- (8) lion
    -- (9) hedgehog               -- (9) hedgehog
  - (4) bear                    - (4) bear
                                - (10) new      
```

#### Переносим к корню существующий узел через `appendTo()` <span id="example-root-2"></span>

```php
$model7 = Animal::findOne(7);
$root = $model7->getRoot();
$model7->appendTo($root);
```
Произойдет:
```
- root                        - root
  - (1) cat                     - (1) cat
    -- (5) mouse                  -- (5) mouse
      --- (7) stag                -- (6) fox
    -- (6) fox                  - (2) dog
  - (2) dog            ==>      - (3) snake
  - (3) snake                     -- (8) lion
    -- (8) lion                   -- (9) hedgehog
    -- (9) hedgehog             - (4) bear
  - (4) bear                    - (7) stag
```

### `appendTo()` <span id="example-append"></span>

Переносим поддерево в новую позицию:

```php
$model1 = Animal::findOne(1);
$model3 = Animal::findOne(3);
$model1->appendTo($model3);
```
Произойдет:
```
- root                        - root
  - (1) cat                     - (2) dog
    -- (5) mouse                - (3) snake
      --- (7) stag                -- (8) lion
    -- (6) fox                    -- (9) hedgehog
  - (2) dog            ==>        -- (1) cat
  - (3) snake                       --- (5) mouse
    -- (8) lion                       ---- (7) stag
    -- (9) hedgehog                 --- (6) fox
  - (4) bear                    - (4) bear
```

### `prependTo()` <span id="example-prepend"></span>

Добавляем новый узел как первого ребенка к узлу:

```php
$model1 = Animal::findOne(1);
$newModel = new Animal(['name' => 'new']);
$newModel->prependTo($model1);
```
Произойдет:
```
- root                        - root
  - (1) cat                     - (1) cat
    -- (5) mouse                  -- (12) new
      --- (7) stag                -- (5) mouse
    -- (6) fox                      --- (7) stag
  - (2) dog            ==>        -- (6) fox
  - (3) snake                   - (2) dog
    -- (8) lion                 - (3) snake
    -- (9) hedgehog               -- (8) lion
  - (4) bear                      -- (9) hedgehog
                                - (4) bear
```

### `insertBefore()` <span id="example-insertbefore"></span>

Добавляем новый узел перед другим:

```php
$model3 = Animal::findOne(3);
$newModel = new Animal(['name' => 'new']);
$newModel->insertBefore($model3);
```
Произойдет:
```
- root                        - root
  - (1) cat                     - (1) cat
    -- (5) mouse                  -- (5) mouse
      --- (7) stag                  --- (7) stag
    -- (6) fox                    -- (6) fox
  - (2) dog            ==>      - (2) dog
  - (3) snake                   - (13) new
    -- (8) lion                 - (3) snake
    -- (9) hedgehog               -- (8) lion
  - (4) bear                      -- (9) hedgehog
                                - (4) bear
```

### `insertAfter()` <span id="example-insertafter"></span>

Переносим существующий узел сразу после другого:

```php
$model7 = Animal::findOne(7);
$model8 = Animal::findOne(8);
$model7->insertAfter($model8);
```
Произойдет:
```
- root                        - root
  - (1) cat                     - (1) cat
    -- (5) mouse                  -- (5) mouse
      --- (7) stag                -- (6) fox
    -- (6) fox                  - (2) dog
  - (2) dog            ==>      - (3) snake
  - (3) snake                     -- (8) lion
    -- (8) lion                   -- (7) stag
    -- (9) hedgehog               -- (9) hedgehog
  - (4) bear                    - (4) bear
```

### `insertAsChildAtPosition()` <span id="example-insertaschildatpos"></span>

Вставляем новую запись как 3-ий ребенок у корня (позиция 2):

```php
$root = $service->getRoot(Animal::class);
$newModel = new Animal(['name' => 'new']);
$newModel->insertAsChildAtPosition($root, 2);
```
Произойдет:
```
- root                        - root                        
  - (1) cat                     - (1) cat                   
    -- (5) mouse                  -- (5) mouse
      --- (7) stag                  --- (7) stag
    -- (6) fox                    -- (6) fox
  - (2) dog            ==>      - (2) dog                   
  - (3) snake                   - (14) new                  
    -- (8) lion                 - (3) snake
    -- (9) hedgehog               -- (8) lion
  - (4) bear                      -- (9) hedgehog
                                - (4) bear
```

### `delete()` <span id="example-delete"></span>

Удаляем существующую запись с учетом того что ее потомки перенесутся к родителю:

```php
$model3 = Animal::findOne(3);
$model3->delete()
```
Произойдет:
```
- root                        - root
  - (1) cat                     - (1) cat
    -- (5) mouse                  -- (5) mouse
      --- (7) stag                  --- (7) stag
    -- (6) fox                    -- (6) fox
  - (2) dog            ==>      - (2) dog
  - (3) snake                   - (4) bear
    -- (8) lion                 - (8) lion
    -- (9) hedgehog             - (9) hedgehog
  - (4) bear                  
```

### Клонирование <span id="example-cloning"></span>

#### Клонируем один узел <span id="example-cloning-1"></span>

```php
$model7 = Animal::findOne(7);
$model8 = Animal::findOne(8);
$service->cloneSubtree($model7, $model8);
```
Произойдет:
```
- root                        - root
  - (1) cat                     - (1) cat
    -- (5) mouse                  -- (5) mouse
      --- (7) stag                  --- (7) stag
    -- (6) fox                    -- (6) fox
  - (2) dog            ==>      - (2) dog
  - (3) snake                   - (3) snake
    -- (8) lion                   -- (8) lion
    -- (9) hedgehog                 --- (197) stag
  - (4) bear                      -- (9) hedgehog
                                - (4) bear                
```

#### Клонируем все поддерево <span id="example-cloning-2"></span>

Клонируем **все** поддерево (как по умолчанию):

```php
$model1 = Animal::findOne(1);
$model8 = Animal::findOne(8);
$service->cloneSubtree($model1, $model8);
```
Произойдет:
```
- root                        - root
  - (1) cat                     - (1) cat
    -- (5) mouse                  -- (5) mouse
      --- (7) stag                  --- (7) stag
    -- (6) fox                    -- (6) fox
  - (2) dog            ==>      - (2) dog
  - (3) snake                   - (3) snake
    -- (8) lion                   -- (8) lion
    -- (9) hedgehog                 --- (198) cat
  - (4) bear                          ---- (199) mouse
                                        ----- (200) stag
                                      ---- (201) fox
                                  -- (9) hedgehog
                                - (4) bear               
```

#### Клонируем поддерево без его вершины <span id="example-cloning-3"></span>

Клонируем поддерево начиная с детей у исходной ноды:

```php
$model1 = Animal::findOne(1);
$model8 = Animal::findOne(8);
$service->cloneSubtree($model1, $model8, false);
```
Произойдет:
```
- root                        - root
  - (1) cat                     - (1) cat
    -- (5) mouse                  -- (5) mouse
      --- (7) stag                  --- (7) stag
    -- (6) fox                    -- (6) fox
  - (2) dog            ==>      - (2) dog
  - (3) snake                   - (3) snake
    -- (8) lion                   -- (8) lion
    -- (9) hedgehog                 --- (202) mouse
  - (4) bear                          ---- (203) stag
                                    --- (204) fox
                                  -- (9) hedgehog
                                - (4) bear              
```

#### Дублируем потомков одного узла <span id="example-cloning-4"></span>

```php
$model1 = Animal::findOne(1);
$service->cloneSubtree($model1, $model1, false);
```
Произойдет:
```
- root                        - root
  - (1) cat                     - (1) cat
    -- (5) mouse                  -- (5) mouse
      --- (7) stag                  --- (7) stag
    -- (6) fox                    -- (6) fox
  - (2) dog            ==>        -- (205) mouse
  - (3) snake                       --- (206) stag
    -- (8) lion                   -- (207) fox
    -- (9) hedgehog             - (2) dog
  - (4) bear                    - (3) snake
                                  -- (8) lion
                                  -- (9) hedgehog
                                - (4) bear             
```

#### Клонируем все дерево в новое <span id="example-cloning-5"></span>

Исходные данные в `Menuitem` таблице у нас [следующие](#menuitem-table).  
Склонируем одно дерево полностью в любое новое:

```php
// существующее не пустое дерево
$root1 = $service->getRoot(Menuitem::class, ['treeid' => 1]);
// корень для нового пустого дерева
$root5 = $service->getRoot(Menuitem::class, ['treeid' => 5]);
// клонируем начиная с детей корня1
$service->cloneSubtree($root1, $root5, false);
```
Произойдет:
```
- root1                     
  - (1) red                   
    -- (4) black              
    -- (5) yellow    ==>      ...   
  - (2) green                
    -- (6) blue              
  - (3) brown                
  
- root5                     - root5
                              - (32) red
                                -- (33) black
                     ==>        -- (34) yellow
                              - (35) green
                                -- (36) blue
                              - (37) brown 

```

