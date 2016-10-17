<?php namespace Folklore\GraphQL;

use GraphQL\GraphQL as GraphQLBase;
use GraphQL\Schema;
use GraphQL\Error;

use GraphQL\Type\Definition\ObjectType;

use Folklore\GraphQL\Error\ValidationError;

use Folklore\GraphQL\Exception\TypeNotFound;
use Folklore\GraphQL\Exception\SchemaNotFound;

use Folklore\GraphQL\Events\SchemaAdded;
use Folklore\GraphQL\Events\TypeAdded;

class GraphQL
{
    protected $app;
    
    protected $schemas = [];
    protected $types = [];
    protected $typesInstances = [];
    
    public function __construct($app)
    {
        $this->app = $app;
    }

    public function schema($schema = null)
    {
        if ($schema instanceof Schema) {
            return $schema;
        }
        
        $this->clearTypeInstances();
        
        $schemaName = is_string($schema) ? $schema:config('graphql.schema', 'default');
        
        if (!is_array($schema) && !isset($this->schemas[$schemaName])) {
            throw new SchemaNotFound('Type '.$schemaName.' not found.');
        }
        
        $schema = is_array($schema) ? $schema:$this->schemas[$schemaName];
        
        $schemaQuery = array_get($schema, 'query', []);
        $schemaMutation = array_get($schema, 'mutation', []);
        $schemaTypes = array_get($schema, 'types', []);

        $newSchema = [];
        
        //Get the types either from the schema, or the global types.
        $types = [];
        if (sizeof($schemaTypes)) {
            foreach ($schemaTypes as $name => $type) {
                $objectType = $this->objectType($type, is_numeric($name) ? []:[
                    'name' => $name
                ]);
                $this->typesInstances[$name] = $objectType;
                $types[] = $objectType;
            }
        } else {
            foreach ($this->types as $name => $type) {
                $types[] = $this->type($name);
            }
        }
        
        $newSchema['types'] = $types;

        if (!empty($schemaQuery)) {
            $query = $this->objectType($schemaQuery, [
                'name' => 'Query'
            ]);
            $newSchema['query'] = $query;
        }


        if (!empty($schemaMutation)) {
            $mutation = $this->objectType($schemaMutation, [
                'name' => 'Mutation'
            ]);
            $newSchema['mutation'] = $mutation;
        }

        return new Schema($newSchema);
    }
    
    public function type($name, $fresh = false)
    {
        $error = false;
        $class = null;
        if (!isset($this->types[$name])) {
            $error = true;
        }

        // @TODO: Find a better way to fix this issue.
        // I think this function needs to be aware of what schema are
        // being used as this fix might cause things to break.
        if ($error == true) {
            foreach($this->schemas as $schema) {
                $get = array_get($schema['types'], $name);
                if ($get) {
                    $class = $this->buildObjectTypeFromClass($get);
                    $error = false;
                    break;
                }
                $error = true;
            }
        }

        if ($error == true)
            throw new TypeNotFound('Type '.$name.' not found.');

        if (!$fresh && isset($this->typesInstances[$name])) {
            return $this->typesInstances[$name];
        }

        if (!$class)
            $class = $this->types[$name];

        $type = $this->objectType($class, [
            'name' => $name
        ]);

        $this->typesInstances[$name] = $type;
        
        return $type;
    }
    
    public function objectType($type, $opts = [])
    {
        // If it's already an ObjectType, just update properties and return it.
        // If it's an array, assume it's an array of fields and build ObjectType
        // from it. Otherwise, build it from a string or an instance.
        $objectType = null;
        if ($type instanceof ObjectType) {
            $objectType = $type;
            foreach ($opts as $key => $value) {
                if (property_exists($objectType, $key)) {
                    $objectType->{$key} = $value;
                }
                if (isset($objectType->config[$key])) {
                    $objectType->config[$key] = $value;
                }
            }
        } elseif (is_array($type)) {
            $objectType = $this->buildObjectTypeFromFields($type, $opts);
        } else {
            $objectType = $this->buildObjectTypeFromClass($type, $opts);
        }
        
        return $objectType;
    }
    
    public function query($query, $params = [], $opts = [])
    {
        $result = $this->queryAndReturnResult($query, $params, $opts);
        
        if (!empty($result->errors)) {
            $errorFormatter = config('graphql.error_formatter', [self::class, 'formatError']);
            
            return [
                'data' => $result->data,
                'errors' => array_map($errorFormatter, $result->errors)
            ];
        } else {
            return [
                'data' => $result->data
            ];
        }
    }
    
    public function queryAndReturnResult($query, $params = [], $opts = [])
    {
        $root = array_get($opts, 'root', null);
        $context = array_get($opts, 'context', null);
        $schemaName = array_get($opts, 'schema', null);
        $schema = $this->schema($schemaName);
        $result = GraphQLBase::executeAndReturnResult($schema, $query, $root, $context, $params);
        return $result;
    }
    
    public function addTypes($types)
    {
        foreach ($types as $name => $type) {
            $this->addType($type, is_numeric($name) ? null:$name);
        }
    }
    
    public function addType($class, $name = null)
    {
        $name = $this->getTypeName($class, $name);
        $this->types[$name] = $class;
        
        event(new TypeAdded($class, $name));
    }

    public function addTypes($types) {
        foreach($types as $name => $class) {
            $this->addType($class, $name);
        }
    }

    public function addSchema($name, $schema)
    {
        $this->schemas[$name] = $schema;
        
        event(new SchemaAdded($schema, $name));
    }
    
    public function clearType($name)
    {
        if (isset($this->types[$name])) {
            unset($this->types[$name]);
        }
    }
    
    public function clearSchema($name)
    {
        if (isset($this->schemas[$name])) {
            unset($this->schemas[$name]);
        }
    }
    
    public function clearTypes()
    {
        $this->types = [];
    }
    
    public function clearSchemas()
    {
        $this->schemas = [];
    }
    
    public function getTypes()
    {
        return $this->types;
    }
    
    public function getSchemas()
    {
        return $this->schemas;
    }
    
    protected function clearTypeInstances()
    {
        $this->typesInstances = [];
    }
    
    protected function buildObjectTypeFromClass($type, $opts = [])
    {
        if (!is_object($type)) {
            $type = $this->app->make($type);
        }
        
        foreach ($opts as $key => $value) {
            $type->{$key} = $value;
        }
        
        return $type->toType();
    }
    
    protected function buildObjectTypeFromFields($fields, $opts = [])
    {
        $typeFields = [];
        foreach ($fields as $name => $field) {
            if (is_string($field)) {
                $field = $this->app->make($field);
                $name = is_numeric($name) ? $field->name:$name;
                $field->name = $name;
                $field = $field->toArray();
            } else {
                $name = is_numeric($name) ? $field['name']:$name;
                $field['name'] = $name;
            }
            $typeFields[$name] = $field;
        }
        
        return new ObjectType(array_merge([
            'fields' => $typeFields
        ], $opts));
    }
    
    protected function getTypeName($class, $name = null)
    {
        if ($name) {
            return $name;
        }
        
        $type = is_object($class) ? $class:$this->app->make($class);
        return $type->name;
    }
    
    public static function formatError(Error $e)
    {
        $error = [
            'message' => $e->getMessage()
        ];
        
        $locations = $e->getLocations();
        if (!empty($locations)) {
            $error['locations'] = array_map(function ($loc) {
                return $loc->toArray();
            }, $locations);
        }
        
        $previous = $e->getPrevious();
        if ($previous && $previous instanceof ValidationError) {
            $error['validation'] = $previous->getValidatorMessages();
        }
        
        return $error;
    }
}
