<?php

namespace infuse\Model\Driver;

use ICanBoogie\Inflector;
use infuse\Model;
use infuse\Model\Query;
use JAQB\QueryBuilder;
use PDOException;
use PDOStatement;
use Pimple\Container;

class DatabaseDriver implements DriverInterface
{
    /**
     * @var \JAQB\QueryBuilder
     */
    private $db;

    /**
     * @var \Pimple\Container
     */
    private $app;

    /**
     * @param \JAQB\QueryBuilder $db
     * @param \Pimple\Container  $app
     */
    public function __construct(QueryBuilder $db, Container $app = null)
    {
        $this->db = $db;
        $this->app = $app;
    }

    /**
     * Returns the query builder instance used by this driver.
     *
     * @return \JAQB\QueryBuilder
     */
    public function getDatabase()
    {
        return $this->db;
    }

    public function createModel(Model $model, array $parameters)
    {
        $values = $this->serialize($parameters);

        try {
            return $this->db->insert($values)
                ->into($this->getTablename($model))
                ->execute() instanceof PDOStatement;
        } catch (PDOException $e) {
            $this->app['logger']->error($e);
        }

        return false;
    }

    public function getCreatedID(Model $model, $propertyName)
    {
        try {
            $id = $this->db->getPDO()->lastInsertId();

            return $this->unserializeValue($model::properties($propertyName), $id);
        } catch (PDOException $e) {
            $this->app['logger']->error($e);
        }
    }

    public function loadModel(Model $model)
    {
        try {
            $row = $this->db->select('*')
                ->from($this->getTablename($model))
                ->where($model->id(true))
                ->one();

            if (is_array($row)) {
                $row = $this->unserialize($row, $model::properties());
            }

            return $row;
        } catch (PDOException $e) {
            $this->app['logger']->error($e);
        }

        return [];
    }

    public function updateModel(Model $model, array $parameters)
    {
        if (count($parameters) == 0) {
            return true;
        }

        $values = $this->serialize($parameters);

        try {
            return $this->db->update($this->getTablename($model))
                ->values($values)
                ->where($model->id(true))
                ->execute() instanceof PDOStatement;
        } catch (PDOException $e) {
            $this->app['logger']->error($e);
        }

        return false;
    }

    public function deleteModel(Model $model)
    {
        try {
            return $this->db->delete($this->getTablename($model))
                ->where($model->id(true))
                ->execute() instanceof PDOStatement;
        } catch (PDOException $e) {
            $this->app['logger']->error($e);
        }

        return false;
    }

    public function totalRecords($model, array $criteria)
    {
        try {
            return (int) $this->db->select('count(*)')
                ->from($this->getTablename($model))
                ->where($criteria)
                ->scalar();
        } catch (PDOException $e) {
            $this->app['logger']->error($e);
        }

        return 0;
    }

    public function queryModels($model, Query $query)
    {
        try {
            $data = $this->db->select('*')
                ->from($this->getTablename($model))
                ->where($query->getWhere())
                ->limit($query->getLimit(), $query->getStart())
                ->orderBy($query->getSort())
                ->all();

            $properties = $model::properties();
            foreach ($data as &$row) {
                $row = $this->unserialize($row, $properties);
            }

            return $data;
        } catch (PDOException $e) {
            $this->app['logger']->error($e);
        }

        return [];
    }

    /**
     * Generates the tablename for the model.
     *
     * @param string|Model $model
     *
     * @return string
     */
    public function getTablename($model)
    {
        $inflector = Inflector::get();

        return $inflector->camelize($inflector->pluralize($model::modelName()));
    }

    /**
     * Marshals a value to storage.
     *
     * @param mixed $value
     *
     * @return mixed serialized value
     */
    public function serializeValue($value)
    {
        // encode arrays/objects as JSON
        if (is_array($value) || is_object($value)) {
            return json_encode($value);
        }

        return $value;
    }

    /**
     * Marshals a value for a given property from storage.
     *
     * @param array $property
     * @param mixed $value
     *
     * @return mixed unserialized value
     */
    public function unserializeValue(array $property, $value)
    {
        // handle empty strings as null
        if ($property['null'] && $value == '') {
            return;
        }

        $type = $property['type'];

        // handle boolean values, they might be strings
        if ($type == Model::TYPE_BOOLEAN && is_string($value)) {
            return ($value == '1') ? true : false;
        }

        // cast numbers as....numbers
        if ($type == Model::TYPE_NUMBER) {
            return $value + 0;
        }

        // cast dates as numbers also
        if ($type == Model::TYPE_DATE) {
            if (!is_numeric($value)) {
                return strtotime($value);
            } else {
                return $value + 0;
            }
        }

        // decode JSON into an array
        if ($type == Model::TYPE_JSON && is_string($value)) {
            return (array) json_decode($value, true);
        }

        return $value;
    }

    /**
     * Serializes an array of values.
     *
     * @param array $values
     *
     * @return array
     */
    private function serialize(array $values)
    {
        foreach ($values as &$value) {
            $value = $this->serializeValue($value);
        }

        return $values;
    }

    /**
     * Unserializes an array of values.
     *
     * @param array $values
     * @param array $properties model properties
     *
     * @return array
     */
    private function unserialize(array $values, array $properties)
    {
        foreach ($values as $k => &$value) {
            if (isset($properties[$k])) {
                $value = $this->unserializeValue($properties[$k], $value);
            }
        }

        return $values;
    }
}
