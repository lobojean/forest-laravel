<?php

namespace ForestAdmin\ForestLaravel;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Symfony\Component\ClassLoader\ClassMapGenerator;
use ForestAdmin\Liana\Model\Collection as ForestCollection;
use ForestAdmin\Liana\Model\Field as ForestField;
use ForestAdmin\Liana\Model\Pivot as ForestPivot;

class DatabaseStructure {
    /**
     * Array containing the properties of a models
     *
     * @var array
     */
    protected $properties = array();

    /**
     * Array containing the methods of a models
     *
     * @var array
     */
    protected $methods = array();

    /**
     * Array containing the directories where to search for models
     *
     * @var array
     */
    protected $dirs = array();

    /**
     * Array containing the collections of models that make the structure of the database
     *
     * @var array
     */
    protected $collections = array();

    /**
     * @var null
     */
    private $commandPointer = null;

    protected function sendInfo($message) {
        if ($this->commandPointer && method_exists($this->commandPointer, 'info')) {
            $this->commandPointer->info($message);
        } else {
            echo $message.'<br />';
        }
    }

    /**
     * DatabaseStructure constructor.
     * @param null $dirs
     */
    public function __construct($dirs, $commandPointer = null) {
        if ($dirs) {
            $this->dirs = $dirs;
        } else {
            $this->dirs = Config::get('forest.ModelLocations');
        }
        $this->commandPointer = $commandPointer;
    }

    public static function getCollections() {
        return unserialize(Cache::rememberForever('forestCollections', function() {
            $object = new DatabaseStructure(Config::get('forest.ModelLocations'), null);
            $collections = $object->generateCollections();
            $serialized = serialize($collections);

            return $serialized;
        }));
    }

    /**
     * Retrieve collections from the models
     */
    public function generateCollections()
    {
        // check if the DBAL driver instance exist
        $hasDoctrine = interface_exists('Doctrine\DBAL\Driver');

        $models = $this->loadModels();

        // for each model
        foreach ($models as $name) {
            // rest of the two arrays that would contain data from the last model
            $this->properties = array();
            $this->methods = array();

            if (class_exists($name)) {
                try {
                    // Instanciate reflection on the model
                    $reflectionClass = new \ReflectionClass($name);

                    // If not an extension of the class model
                    if (!$reflectionClass->isSubclassOf('Illuminate\Database\Eloquent\Model')) {
                        continue;
                    } else {
                        $this->sendInfo('Model : '.$name);
                    }

                    // If not an abstract class
                    if (!$reflectionClass->IsInstantiable()) {
                        continue;
                    }

                    // Instantiate the model
                    $model = App::make($name);

                    // If we have the doctrine driver we can retrieve properties dat
                    if ($hasDoctrine) {
                        $this->getPropertiesFromTable($model);
                    }

                    // We retreive the methos to find the relations, foreign key
                    $this->getPropertiesFromMethods($model);

                    // Generate the collection for this model
                    $collection = $this->generateCollection(
                        $name,
                        $reflectionClass->getName(),
                        $model
                    );
                    $this->collections[] = $collection;

                } catch (\Exception $e) {
                    var_dump($e->getMessage());
                }
            }
        }

//        dd($this->collections);
        return $this->collections;
    }

    /**
     * Generate a collection from an eloquent model
     *
     * @param $name
     * @param $entityClassName
     * @param $model
     * @return ForestCollection
     */
    protected function generateCollection($name, $entityClassName, $model) {

        $properties = [];

        // For each properties
        foreach($this->properties as $fieldName => $property) {
            // Check if it's really a property
            if ($property['comment'] == "") {
                // Instantiation of a field for the property
                $properties[] = new ForestField($fieldName, $property['type']);
                // Else it's a relation, foreign key
            } else {
                // Go through the existing properties
                // (since the properties are first in the array and relation after)
                foreach ($properties as $field) {
                    $foreign = explode('>', $property['comment']);
                    // retrieve the foreign_key name and where it points to
                    list($currentProperty, $reference) = $foreign;

                    // If this field is the foreign key
                    if ($field->getField() == $currentProperty) {
                        $pivot = new ForestPivot($currentProperty);
                        $field->setPivot($pivot);
                        $field->setReference($reference);
                    }
                }
            }
        }

        // Instatiation of a Collection for the model
        $collection = new ForestCollection($name, $entityClassName, $model->getKeyName(), $properties);

        return $collection;
    }

    /**
     * Load an array of the models from the directories
     *
     * @return array
     */
    public function loadModels() {
        $models = array();

        foreach($this->dirs as $dir) {
            $dir = base_path(). '/' . $dir;
            if (file_exists($dir)) {
                foreach(ClassMapGenerator::createMap($dir) as $model => $path) {
                    $models[] = $model;
                }
            }
        }

        return $models;
    }

    /**
     * Extract the properties from a model
     *
     * @param $model
     */
    public function getPropertiesFromTable($model) {
        $table = $model->getConnection()->getTablePrefix() . $model->getTable();
        $schema = $model->getConnection()->getDoctrineSchemaManager($table);
        $databasePlatform = $schema->getDatabasePlatform();
        $databasePlatform->registerDoctrineTypeMapping('enum', 'string');

        $database = null;

        if (strpos($table, '.')) {
            list($database, $table) = explode('.', $table);
        }

        $columns = $schema->listTableColumns($table, $database);

        if ($columns) {
            foreach ($columns as $column) {
                $name = $column->getName();

                if (in_array($name, $model->getDates())) {
                    $type = '\Carbon\Carbon';
                } else {
                    $type = $column->getType()->getName();
                    switch ($type) {
                        case 'string':
                        case 'text':
                        case 'date':
                        case 'time':
                        case 'guid':
                        case 'datetimetz':
                        case 'datetime':
                            $type = 'string';
                            break;
                        case 'integer':
                        case 'bigint':
                        case 'smallint':
                            $type = 'integer';
                            break;
                        case 'decimal':
                        case 'float':
                            $type = 'float';
                            break;
                        case 'boolean':
                            $type = 'boolean';
                            break;
                        default:
                            $type = 'mixed';
                            break;
                    }
                }
                $comment = $column->getComment();
                $this->setProperty($name, $type, '');
                $this->setMethod(
                    Str::camel("where_" . $name),
                    '\Illuminate\Database\Query\Builder|\\' . get_class($model),
                    array('$value')
                );
            }
        }
    }

    /**
     * Retrieve the methods from a model
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     */
    protected function getPropertiesFromMethods($model) {
        $methods = get_class_methods($model);
        if ($methods) {
            foreach ($methods as $method) {

                if (Str::startsWith($method, 'get') && Str::endsWith(
                        $method,
                        'Attribute'
                    ) && $method !== 'getAttribute'
                ) {
                    //Magic get<name>Attribute
                    $name = Str::snake(substr($method, 3, -9));
                    if (!empty($name)) {
                        $this->setProperty($name, null, '');
                    }
                } elseif (Str::startsWith($method, 'set') && Str::endsWith(
                        $method,
                        'Attribute'
                    ) && $method !== 'setAttribute'
                ) {
                    //Magic set<name>Attribute
                    $name = Str::snake(substr($method, 3, -9));
                    if (!empty($name)) {
                        $this->setProperty($name, null, '');
                    }
                } elseif (Str::startsWith($method, 'scope') && $method !== 'scopeQuery') {
                    //Magic set<name>Attribute
                    $name = Str::camel(substr($method, 5));
                    if (!empty($name)) {
                        $reflection = new \ReflectionMethod($model, $method);
                        $args = $this->getParameters($reflection);
                        //Remove the first ($query) argument
                        array_shift($args);
                        $this->setMethod($name, '\Illuminate\Database\Query\Builder|\\' . $reflection->class, $args);
                    }
                } elseif (!method_exists('Illuminate\Database\Eloquent\Model', $method)
                    && !Str::startsWith($method, 'get')
                ) {
                    //Use reflection to inspect the code, based on Illuminate/Support/SerializableClosure.php
                    $reflection = new \ReflectionMethod($model, $method);
                    $file = new \SplFileObject($reflection->getFileName());
                    $file->seek($reflection->getStartLine() - 1);
                    $code = '';
                    while ($file->key() < $reflection->getEndLine()) {
                        $code .= $file->current();
                        $file->next();
                    }
                    $code = trim(preg_replace('/\s\s+/', '', $code));
                    $begin = strpos($code, 'function(');
                    $code = substr($code, $begin, strrpos($code, '}') - $begin + 1);
                    foreach (array(
                                 'hasMany',
                                 'hasManyThrough',
                                 'belongsToMany',
                                 'hasOne',
                                 'belongsTo',
                                 'morphOne',
                                 'morphTo',
                                 'morphMany',
                                 'morphToMany'
                             ) as $relation) {
                        $search = '$this->' . $relation . '(';
                        if ($pos = stripos($code, $search)) {
                            $this->sendInfo('This is '.$relation);
                            //Resolve the relation's model to a Relation object.
                            $relationObj = $model->$method();
                            if ($relationObj instanceof Relation) {
                                $this->sendInfo('It is an instance of Relation');
                                $relatedModel = '\\' . get_class($relationObj->getRelated());
                                $relations = ['hasManyThrough', 'belongsToMany', 'hasMany', 'morphMany', 'morphToMany'];
                                if (in_array($relation, $relations)) {
                                    $this->sendInfo('-------------> First relation');
                                    //Collection or array of models (because Collection is Arrayable)
                                    // TODO : in the case of a hasMany there's no foreign key
//                                    $this->setProperty(
//                                        $method,
//                                        $this->getCollectionClass($relatedModel) . '|' . $relatedModel . '[]',
//                                        $relationObj->getForeignKey().'>'.$method.'.'.$relationObj->getOtherKey()
//                                    );
                                } elseif ($relation === "morphTo") {
                                    $this->sendInfo('-------------> second relation');
                                    // Model isn't specified because relation is polymorphic
                                    $this->setProperty(
                                        $method,
                                        '\Illuminate\Database\Eloquent\Model|\Eloquent',
                                        $relationObj->getForeignKey().'>'.$method.'.'.$relationObj->getOtherKey()
                                    );
                                } else {
                                    $this->sendInfo('-------------> Third relation');
                                    //Single model is returned
                                    $this->setProperty(
                                        $method,
                                        $relatedModel,
                                        $relationObj->getForeignKey().'>'.$method.'.'.$relationObj->getOtherKey()
                                    );
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Add a property extracted form the model to the properties array
     *
     * @param string $name
     * @param string|null $type
     * @param string|null $comment
     */
    protected function setProperty($name, $type = null, $comment = '') {
        if (!isset($this->properties[$name])) {
            $this->properties[$name] = array();
            $this->properties[$name]['type'] = 'mixed';
            $this->properties[$name]['comment'] = (string) $comment;
        }
        if ($type !== null) {
            $this->properties[$name]['type'] = $type;
        }
    }

    /**
     * Add a method extracted from the model to the methods array
     *
     * @param $name
     * @param string $type
     * @param array $arguments
     */
    protected function setMethod($name, $type = '', $arguments = array()) {
        $methods = array_change_key_case($this->methods, CASE_LOWER);
        if (!isset($methods[strtolower($name)])) {
            $this->methods[$name] = array();
            $this->methods[$name]['type'] = $type;
            $this->methods[$name]['arguments'] = $arguments;
        }
    }

    /**
     * Determine a model classes' collection type.
     *
     * @see http://laravel.com/docs/eloquent-collections#custom-collections
     * @param string $className
     * @return string
     */
    private function getCollectionClass($className)
    {
        // Return something in the very very unlikely scenario the model doesn't
        // have a newCollection() method.
        if (!method_exists($className, 'newCollection')) {
            return '\Illuminate\Database\Eloquent\Collection';
        }
        /** @var \Illuminate\Database\Eloquent\Model $model */
        $model = new $className;
        return '\\' . get_class($model->newCollection());
    }
}